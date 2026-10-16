<?php

namespace App\Services\Ativos;

use App\Exceptions\ErroNegocio;
use App\Models\AndarEdificio;
use App\Models\AtivoImobilizado;
use App\Models\InventarioFisicoAtivo;
use App\Models\LinhaInventarioFisicoAtivo;
use App\Models\PortaEdificio;
use App\Services\Sistema\ServicoAuditoria;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Folhas de inventário físico dos activos por edifício, andar ou porta (Fase F, Versão Actual — js/activos_edificios.js
 * :534-969). Regras do legado mantidas:
 *   - a folha fotografa os bens esperados no âmbito (código, descrição, porta, andar e valor) — assetGerarInventario :594-610;
 *   - a contagem marca cada bem Encontrado / Em falta / Noutro local (com a porta onde foi encontrado); acrescentam-se
 *     bens não previstos (EXTRA) com a porta onde foram encontrados (:784-830);
 *   - comparação: encontrados, em falta, noutro local, não previstos, conformidade = encontrados / previstos (:832-886);
 *   - «aplicar» move para a porta onde foram encontrados os bens DESLOCADOS e EXTRA (:888-903);
 *   - «fechar» põe a folha FECHADA (quem e quando) e deixa de se poder alterar (:905-914).
 * Correcções/melhorias: a folha não se apaga — anula-se com motivo (o legado eliminava folha e contagem, :916-927);
 * só se contam linhas da própria folha e portas do próprio edifício; um bem não entra duas vezes; edifícios e bens
 * abatidos não entram na folha nem se movem; tudo validado no servidor e auditado.
 */
final class ServicoInventarioFisicoAtivos
{
    public function __construct(
        private readonly ServicoAuditoria $auditoria,
    ) {}

    // ───────────── Geração e consulta ─────────────

    /**
     * @param  array{ambito: string, andar_id?: ?int, porta_id?: ?int, data?: ?string, responsavel?: ?string, notas?: ?string, confirmar_vazia?: ?bool}  $d
     */
    public function gerar(AtivoImobilizado $edificio, array $d): InventarioFisicoAtivo
    {
        if ($edificio->tipo_ativo !== ServicoLocaisAtivos::TIPO_EDIFICIO) {
            throw new ErroNegocio("O activo {$edificio->codigo} não é um edifício/local.", 'NAO_E_EDIFICIO', 422);
        }
        $ambito = $d['ambito'] ?? 'EDIFICIO';
        if (! in_array($ambito, InventarioFisicoAtivo::AMBITOS, true)) {
            throw new ErroNegocio('Âmbito inválido (EDIFICIO, ANDAR ou PORTA).', 'DADOS_INVALIDOS', 422);
        }
        $andarId = $ambito === 'ANDAR' ? (int) ($d['andar_id'] ?? 0) : null;
        $portaId = $ambito === 'PORTA' ? (int) ($d['porta_id'] ?? 0) : null;
        if ($ambito === 'ANDAR' && ! AndarEdificio::query()->where('edificio_id', $edificio->id)->whereKey($andarId)->exists()) {
            throw new ErroNegocio('Escolha um andar deste edifício.', 'DADOS_INVALIDOS', 422);
        }
        if ($ambito === 'PORTA' && ! PortaEdificio::query()->where('edificio_id', $edificio->id)->whereKey($portaId)->exists()) {
            throw new ErroNegocio('Escolha uma porta deste edifício.', 'DADOS_INVALIDOS', 422);
        }

        return DB::transaction(function () use ($edificio, $d, $ambito, $andarId, $portaId) {
            $bens = $this->esperados($edificio->id, $andarId, $portaId);
            if ($bens->isEmpty() && empty($d['confirmar_vazia'])) {
                throw new ErroNegocio('Não há bens esperados neste âmbito. Confirme para gerar a folha só para registar bens não previstos.', 'SEM_BENS_ESPERADOS', 422);
            }
            $valor = $bens->reduce(fn ($s, $b) => bcadd($s, CalculadoraAmortizacoes::d($b->valor_aquisicao), 2), '0.00');
            $inv = InventarioFisicoAtivo::create([
                'edificio_id' => $edificio->id, 'ambito' => $ambito, 'andar_id' => $andarId, 'porta_id' => $portaId,
                'data' => $d['data'] ?? now()->toDateString(), 'responsavel' => trim((string) ($d['responsavel'] ?? '')) ?: Auth::user()?->nome_utilizador,
                'notas' => trim((string) ($d['notas'] ?? '')) ?: null, 'estado' => InventarioFisicoAtivo::ABERTO,
                'esperados' => $bens->count(), 'valor_esperado' => $valor, 'criado_por' => Auth::user()?->nome_utilizador,
            ]);
            foreach ($bens as $b) {
                LinhaInventarioFisicoAtivo::create($this->fotografia($inv, $b) + ['resultado' => null]);
            }
            $this->auditoria->registar('Activos', 'Gerar inventário físico', "INV-{$inv->id} {$ambito}: {$bens->count()} bem(ns)", 'inventarios_fisicos_ativos', $inv->id);

            return $inv->refresh();
        });
    }

    /** Lista das folhas (renderAssetInventories :612-661) com esperados, contados, por contar e diferenças. */
    public function listar(array $f): LengthAwarePaginator
    {
        $q = InventarioFisicoAtivo::query()->with('edificio:id,codigo,descricao')
            ->when($f['edificio_id'] ?? null, fn ($q, $v) => $q->where('edificio_id', $v))
            ->when($f['estado'] ?? null, fn ($q, $v) => $q->where('estado', $v), fn ($q) => $q->where('estado', '<>', InventarioFisicoAtivo::ANULADO))
            ->orderByDesc('data')->orderByDesc('id');
        $pagina = $q->paginate((int) ($f['por_pagina'] ?? 100), ['*'], 'pagina', (int) ($f['pagina'] ?? 1));
        $contagens = LinhaInventarioFisicoAtivo::query()->whereIn('inventario_fisico_id', $pagina->getCollection()->pluck('id'))
            ->selectRaw("inventario_fisico_id,
                SUM(CASE WHEN resultado IS DISTINCT FROM 'EXTRA' THEN 1 ELSE 0 END) AS previstos,
                SUM(CASE WHEN resultado IN ('ENCONTRADO','EM_FALTA','DESLOCADO') THEN 1 ELSE 0 END) AS contados,
                SUM(CASE WHEN resultado IS NULL OR resultado = '' THEN 1 ELSE 0 END) AS por_contar,
                SUM(CASE WHEN resultado IN ('EM_FALTA','DESLOCADO','EXTRA') THEN 1 ELSE 0 END) AS diferencas")
            ->groupBy('inventario_fisico_id')->get()->keyBy('inventario_fisico_id');
        $nomes = $this->nomesLocais($pagina->getCollection()->pluck('edificio_id')->unique()->all());
        $pagina->setCollection($pagina->getCollection()->map(fn (InventarioFisicoAtivo $i) => $i->toArray() + [
            'numero' => "INV-{$i->id}",
            'ambito_texto' => $this->ambitoTexto($i, $nomes),
            'previstos' => (int) ($contagens[$i->id]->previstos ?? 0),
            'contados' => (int) ($contagens[$i->id]->contados ?? 0),
            'por_contar' => (int) ($contagens[$i->id]->por_contar ?? 0),
            'diferencas' => (int) ($contagens[$i->id]->diferencas ?? 0),
        ]));

        return $pagina;
    }

    /** Folha completa (assetFolhaInventario / contexto :663-778): cabeçalho, linhas com o texto dos locais e portas do edifício. */
    public function folha(InventarioFisicoAtivo $inv): array
    {
        $nomes = $this->nomesLocais([$inv->edificio_id]);
        $linhas = LinhaInventarioFisicoAtivo::query()->where('inventario_fisico_id', $inv->id)->get()
            ->sortBy(fn ($l) => (string) $l->codigo, SORT_NATURAL)->values();

        return [
            'inventario' => $inv->load('edificio:id,codigo,descricao')->toArray() + ['numero' => "INV-{$inv->id}", 'ambito_texto' => $this->ambitoTexto($inv, $nomes)],
            'linhas' => $linhas->map(fn (LinhaInventarioFisicoAtivo $l) => $l->toArray() + [
                'local_esperado' => $l->resultado === 'EXTRA' ? null : $this->portaTexto($l->porta_id, $nomes),
                'local_encontrado' => $l->porta_encontrada_id ? $this->portaTexto($l->porta_encontrada_id, $nomes) : null,
            ])->all(),
            'portas' => collect($nomes['portas'])->filter(fn ($p) => (int) $p['edificio_id'] === (int) $inv->edificio_id)
                ->map(fn ($p, $id) => ['id' => $id, 'texto' => $this->portaTexto($id, $nomes)])->values()->all(),
        ];
    }

    /** Comparação esperado × encontrado (assetCompararInventario :832-886). */
    public function comparacao(InventarioFisicoAtivo $inv): array
    {
        $folha = $this->folha($inv);
        $linhas = collect($folha['linhas']);
        $grupo = fn (string $r) => $linhas->where('resultado', $r)->values();
        $total = fn (Collection $ls) => $ls->reduce(fn ($s, $l) => bcadd($s, CalculadoraAmortizacoes::d($l['valor']), 2), '0.00');
        $previstas = $linhas->filter(fn ($l) => $l['resultado'] !== 'EXTRA');
        $encontrados = $grupo('ENCONTRADO');
        $grupos = ['encontrados' => $encontrados, 'em_falta' => $grupo('EM_FALTA'), 'deslocados' => $grupo('DESLOCADO'), 'extras' => $grupo('EXTRA')];

        return $folha + [
            'resumo' => collect($grupos)->map(fn ($ls) => ['n' => $ls->count(), 'valor' => $total($ls)])->all() + [
                'por_contar' => $linhas->filter(fn ($l) => ($l['resultado'] ?? '') === '')->count(),
                'previstos' => $previstas->count(),
                // conformidade = encontrados / previstos, com 1 casa (taxa, :845)
                'conformidade' => $previstas->count() ? round($encontrados->count() / $previstas->count() * 100, 1) : 0.0,
                'a_aplicar' => $linhas->filter(fn ($l) => in_array($l['resultado'], ['DESLOCADO', 'EXTRA'], true) && $l['porta_encontrada_id'])->count(),
            ],
        ];
    }

    // ───────────── Contagem ─────────────

    /**
     * Grava a contagem (assetGravarContagem :784-803): resultado, porta onde foi encontrado (só para «Noutro local») e notas.
     *
     * @param  list<array{id: int, resultado?: ?string, porta_encontrada_id?: ?int, notas?: ?string}>  $linhas
     */
    public function gravarContagem(InventarioFisicoAtivo $inv, array $linhas): array
    {
        return DB::transaction(function () use ($inv, $linhas) {
            $inv = $this->aberto($inv);
            $existentes = LinhaInventarioFisicoAtivo::query()->where('inventario_fisico_id', $inv->id)->lockForUpdate()->get()->keyBy('id');
            $portas = PortaEdificio::query()->where('edificio_id', $inv->edificio_id)->pluck('id')->flip();
            $contados = 0;
            foreach ($linhas as $n => $d) {
                $l = $existentes->get((int) ($d['id'] ?? 0)) ?? throw new ErroNegocio('Linha '.($n + 1).': não pertence a esta folha.', 'DADOS_INVALIDOS', 422);
                if ($l->resultado === 'EXTRA') {
                    continue;   // os não previstos gerem-se à parte (acrescentar/retirar)
                }
                $resultado = ($d['resultado'] ?? '') ?: null;
                if ($resultado !== null && ! in_array($resultado, ['ENCONTRADO', 'EM_FALTA', 'DESLOCADO'], true)) {
                    throw new ErroNegocio("Linha {$l->codigo}: resultado inválido.", 'DADOS_INVALIDOS', 422);
                }
                $porta = $resultado === 'DESLOCADO' && ! empty($d['porta_encontrada_id']) ? (int) $d['porta_encontrada_id'] : null;
                if ($porta && ! isset($portas[$porta])) {
                    throw new ErroNegocio("Linha {$l->codigo}: a porta indicada não é deste edifício.", 'DADOS_INVALIDOS', 422);
                }
                $l->update(['resultado' => $resultado, 'porta_encontrada_id' => $porta,
                    'notas' => array_key_exists('notas', $d) ? (mb_substr(trim((string) $d['notas']), 0, 2000) ?: null) : $l->notas]);
                $contados += $resultado ? 1 : 0;
            }
            $inv->update(['contado_em' => now()]);

            return ['contados' => $contados];
        });
    }

    /** Bem encontrado e não previsto (assetAdicionarExtra :805-822). */
    public function acrescentarExtra(InventarioFisicoAtivo $inv, int $ativoId, int $portaId): LinhaInventarioFisicoAtivo
    {
        return DB::transaction(function () use ($inv, $ativoId, $portaId) {
            $inv = $this->aberto($inv);
            $bem = AtivoImobilizado::query()->find($ativoId) ?? throw new ErroNegocio('Escolha o bem encontrado.', 'DADOS_INVALIDOS', 422);
            if ($bem->tipo_ativo === ServicoLocaisAtivos::TIPO_EDIFICIO) {
                throw new ErroNegocio('Um edifício não se acrescenta a uma folha de inventário.', 'EDIFICIO_NAO_LOCALIZAVEL', 422);
            }
            if (! PortaEdificio::query()->where('edificio_id', $inv->edificio_id)->whereKey($portaId)->exists()) {
                throw new ErroNegocio('Indique a porta (deste edifício) onde foi encontrado.', 'DADOS_INVALIDOS', 422);
            }
            if (LinhaInventarioFisicoAtivo::query()->where('inventario_fisico_id', $inv->id)->where('ativo_imobilizado_id', $bem->id)->exists()) {
                throw new ErroNegocio('Esse bem já está na folha.', 'BEM_NA_FOLHA', 422);
            }

            return LinhaInventarioFisicoAtivo::create($this->fotografia($inv, $bem) + ['resultado' => 'EXTRA', 'porta_encontrada_id' => $portaId]);
        });
    }

    /** Retira um bem não previsto da folha aberta (assetRemoverExtra :824-830). Só as linhas EXTRA saem. */
    public function removerExtra(LinhaInventarioFisicoAtivo $linha): void
    {
        DB::transaction(function () use ($linha) {
            $linha = LinhaInventarioFisicoAtivo::query()->lockForUpdate()->findOrFail($linha->id);
            $this->aberto(InventarioFisicoAtivo::query()->findOrFail($linha->inventario_fisico_id));
            if ($linha->resultado !== 'EXTRA') {
                throw new ErroNegocio('Só os bens não previstos se retiram da folha.', 'LINHA_PREVISTA', 422);
            }
            $linha->delete();
        });
    }

    // ───────────── Aplicar, fechar e anular ─────────────

    /** Move os bens deslocados e não previstos para a porta onde foram encontrados (assetAplicarInventario :888-903). */
    public function aplicar(InventarioFisicoAtivo $inv): array
    {
        return DB::transaction(function () use ($inv) {
            $inv = $this->aberto($inv);
            $mover = LinhaInventarioFisicoAtivo::query()->where('inventario_fisico_id', $inv->id)->whereIn('resultado', ['DESLOCADO', 'EXTRA'])
                ->whereNotNull('porta_encontrada_id')->get();
            if ($mover->isEmpty()) {
                throw new ErroNegocio('Não há localizações novas para aplicar.', 'NADA_A_APLICAR', 422);
            }
            $portas = PortaEdificio::query()->whereIn('id', $mover->pluck('porta_encontrada_id'))->get()->keyBy('id');
            $movidos = 0;
            $ignorados = [];
            foreach ($mover as $l) {
                $bem = AtivoImobilizado::query()->lockForUpdate()->find($l->ativo_imobilizado_id);
                $porta = $portas->get($l->porta_encontrada_id);
                if (! $bem || ! $porta || $bem->tipo_ativo === ServicoLocaisAtivos::TIPO_EDIFICIO || $bem->estado === AtivoImobilizado::ESTADO_ABATIDO) {
                    $ignorados[] = $l->codigo;

                    continue;
                }
                if ((int) $bem->porta_id !== $porta->id) {
                    $bem->update(['edificio_id' => (int) $porta->edificio_id, 'andar_id' => (int) $porta->andar_id, 'porta_id' => $porta->id]);
                }
                $movidos++;
            }
            $inv->update(['aplicado_em' => now()]);
            $this->auditoria->registar('Activos', 'Aplicar inventário físico', "INV-{$inv->id}: {$movidos} bem(ns) relocalizado(s)", 'inventarios_fisicos_ativos', $inv->id);

            return ['relocalizados' => $movidos, 'ignorados' => $ignorados];
        });
    }

    /** Fecha a folha (assetFecharInventario :905-914): os bens por contar ficam registados como não contados. */
    public function fechar(InventarioFisicoAtivo $inv): InventarioFisicoAtivo
    {
        return DB::transaction(function () use ($inv) {
            $inv = $this->aberto($inv);
            $porContar = LinhaInventarioFisicoAtivo::query()->where('inventario_fisico_id', $inv->id)->where(fn ($q) => $q->whereNull('resultado')->orWhere('resultado', ''))->count();
            $inv->update(['estado' => InventarioFisicoAtivo::FECHADO, 'fechado_em' => now(), 'fechado_por' => Auth::user()?->nome_utilizador]);
            $this->auditoria->registar('Activos', 'Fechar inventário físico', "INV-{$inv->id}: {$porContar} por contar", 'inventarios_fisicos_ativos', $inv->id);

            return $inv->refresh();
        });
    }

    /** Anula a folha aberta, com motivo (o legado eliminava-a). As localizações dos bens não mudam. */
    public function anular(InventarioFisicoAtivo $inv, string $motivo): InventarioFisicoAtivo
    {
        if (trim($motivo) === '') {
            throw new ErroNegocio('Indique o motivo da anulação.', 'DADOS_INVALIDOS', 422);
        }

        return DB::transaction(function () use ($inv, $motivo) {
            $inv = InventarioFisicoAtivo::query()->lockForUpdate()->findOrFail($inv->id);
            if ($inv->estado === InventarioFisicoAtivo::FECHADO) {
                throw new ErroNegocio('Um inventário fechado não pode ser anulado.', 'INVENTARIO_FECHADO', 422);
            }
            if ($inv->estado === InventarioFisicoAtivo::ANULADO) {
                throw new ErroNegocio('A folha já está anulada.', 'INVENTARIO_ANULADO', 422);
            }
            $inv->update(['estado' => InventarioFisicoAtivo::ANULADO, 'anulado_em' => now(), 'anulado_por' => Auth::user()?->nome_utilizador,
                'motivo_anulacao' => mb_substr(trim($motivo), 0, 500)]);

            return $inv->refresh();
        });
    }

    // ───────────── Auxiliares ─────────────

    private function aberto(InventarioFisicoAtivo $inv): InventarioFisicoAtivo
    {
        $inv = InventarioFisicoAtivo::query()->lockForUpdate()->findOrFail($inv->id);
        if ($inv->estado !== InventarioFisicoAtivo::ABERTO) {
            throw new ErroNegocio($inv->estado === InventarioFisicoAtivo::FECHADO ? 'O inventário está fechado e já não pode ser alterado.' : 'A folha está anulada.',
                'INVENTARIO_NAO_ABERTO', 422);
        }

        return $inv;
    }

    /** Bens esperados no âmbito: localizados no edifício (porta do edifício), sem edifícios nem abatidos. */
    private function esperados(int $edificioId, ?int $andarId, ?int $portaId): Collection
    {
        $portas = PortaEdificio::query()->where('edificio_id', $edificioId)
            ->when($andarId, fn ($q) => $q->where('andar_id', $andarId))->when($portaId, fn ($q) => $q->whereKey($portaId))->pluck('id');

        // bens nas portas do âmbito e, numa folha do edifício ou do andar, também os que lá estão sem porta (ADR-071)
        return AtivoImobilizado::query()
            ->where(fn ($q) => $q->whereIn('porta_id', $portas)->when(! $portaId, fn ($o) => $o->orWhere(fn ($s) => $s->whereNull('porta_id')
                ->where('edificio_id', $edificioId)->when($andarId, fn ($a) => $a->where('andar_id', $andarId)))))
            ->where(fn ($q) => $q->whereNull('tipo_ativo')->orWhere('tipo_ativo', '<>', ServicoLocaisAtivos::TIPO_EDIFICIO))
            ->where(fn ($q) => $q->whereNull('estado')->orWhere('estado', '<>', AtivoImobilizado::ESTADO_ABATIDO))
            ->orderBy('codigo')->get();
    }

    private function fotografia(InventarioFisicoAtivo $inv, AtivoImobilizado $b): array
    {
        return ['inventario_fisico_id' => $inv->id, 'ativo_imobilizado_id' => $b->id, 'codigo' => $b->codigo, 'descricao' => $b->descricao,
            'porta_id' => $b->porta_id, 'andar_id' => $b->andar_id, 'valor' => CalculadoraAmortizacoes::d($b->valor_aquisicao), 'notas' => null];
    }

    /** @return array{edificios: array<int, array>, andares: array<int, array>, portas: array<int, array>} */
    private function nomesLocais(array $edificios): array
    {
        return [
            'edificios' => AtivoImobilizado::withTrashed()->whereIn('id', $edificios)->get(['id', 'codigo', 'descricao'])->keyBy('id')->map->toArray()->all(),
            'andares' => AndarEdificio::query()->whereIn('edificio_id', $edificios)->get(['id', 'numero', 'nome'])->keyBy('id')->map->toArray()->all(),
            'portas' => PortaEdificio::query()->whereIn('edificio_id', $edificios)->get(['id', 'edificio_id', 'andar_id', 'numero'])->keyBy('id')->map->toArray()->all(),
        ];
    }

    private function andarTexto(?int $id, array $nomes): string
    {
        $f = $id ? ($nomes['andares'][$id] ?? null) : null;

        return $f ? ($f['nome'] ?: 'Piso '.$f['numero']) : 'Andar';
    }

    /** «Andar · Porta N» (portaTexto :668-673); uma porta já eliminada aparece como «—». */
    private function portaTexto(?int $id, array $nomes): string
    {
        $p = $id ? ($nomes['portas'][$id] ?? null) : null;
        if (! $p) {
            return '—';
        }

        return ($p['andar_id'] ? $this->andarTexto((int) $p['andar_id'], $nomes).' · ' : '').'Porta '.$p['numero'];
    }

    private function ambitoTexto(InventarioFisicoAtivo $i, array $nomes): string
    {
        return match ($i->ambito) {
            'PORTA' => $this->portaTexto($i->porta_id, $nomes),
            'ANDAR' => $this->andarTexto($i->andar_id, $nomes),
            default => 'Todo o edifício',
        };
    }
}
