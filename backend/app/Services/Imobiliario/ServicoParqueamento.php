<?php

namespace App\Services\Imobiliario;

use App\Exceptions\ErroNegocio;
use App\Models\ContratoArrendamento;
use App\Models\ContratoFracao;
use App\Models\Fracao;
use App\Models\HistoricoEstadoFracao;
use App\Models\Imovel;
use App\Models\Terceiro;
use App\Models\VeiculoContrato;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Parqueamento (imobiliario_parque.js do legado): as vagas são fracções com natureza ESTACIONAMENTO (a mesma tabela), por isso entram nos
 * contratos, no mapa e na rentabilidade como qualquer outra. Gerar vagas por piso, criar/editar/eliminar, afectar a uma fracção (a vaga
 * vai junto no contrato dela) e libertar, mapa por piso com a ocupação (contrato próprio ou por arrastamento da fracção-mãe), livres,
 * avisos e encargos propostos ao contrato. Correcções (P3): afectar uma vaga que já é de outra fracção recusa em vez de a reatribuir em
 * silêncio (só com `reatribuir`); editar uma vaga já não recalcula o código nem perde bloco, número e documentos; a vaga em contrato
 * vivo não se afecta, não muda para uso comum nem se elimina; os veículos guardam cor e dístico (ver ServicoVeiculos).
 */
final class ServicoParqueamento
{
    private const ESTADOS_CONTRATO_VIVOS = ContratoArrendamento::ESTADOS_VIVOS;

    /** @return Collection<int, Fracao> */
    public function vagas(Imovel $imovel): Collection
    {
        return Fracao::query()->where('imovel_id', $imovel->id)->where('natureza', 'ESTACIONAMENTO')->orderBy('ordem')->orderBy('id')->get();
    }

    /** @return array<string, mixed> */
    public function listas(): array
    {
        return CatalogoParqueamento::listas();
    }

    // ---------------------------------------------------------------- ocupação e mapa

    /**
     * Ocupação de cada vaga: contrato (lado senhorio) em vigor que a abranja (via PROPRIO) ou, se for afecta, que abranja a fracção-mãe (via FRACCAO).
     *
     * @param  Collection<int, Fracao>  $vagas
     * @return array<int, array{via: string, contrato_id: int, contrato: string, terceiro_id: int, estado: string}>
     */
    public function ocupacoes(Collection $vagas, bool $soActivos = true): array
    {
        $maes = $vagas->pluck('park_fracao_id')->filter()->all();
        $ids = array_values(array_unique(array_merge($vagas->pluck('id')->all(), $maes)));
        if ($ids === []) {
            return [];
        }
        $pares = ContratoFracao::query()->whereIn('fracao_id', $ids)->where('posicao', 'SENHORIO')->get(['contrato_id', 'fracao_id']);
        $contratos = ContratoArrendamento::query()->whereIn('id', $pares->pluck('contrato_id')->unique())->whereIn('estado', $soActivos ? ['ACTIVO'] : self::ESTADOS_CONTRATO_VIVOS)
            ->get(['id', 'numero', 'terceiro_id', 'estado'])->keyBy('id');
        $porFracao = [];
        foreach ($pares as $p) {
            if (isset($contratos[$p->contrato_id])) {
                $porFracao[(int) $p->fracao_id][] = $contratos[$p->contrato_id];
            }
        }
        $saida = [];
        foreach ($vagas as $v) {
            $c = ($porFracao[$v->id] ?? [null])[0];
            $via = 'PROPRIO';
            if ($c === null && $v->park_afectacao === 'PERMANENTE' && $v->park_fracao_id) {
                $c = ($porFracao[(int) $v->park_fracao_id] ?? [null])[0];
                $via = 'FRACCAO';
            }
            if ($c !== null) {
                $saida[(int) $v->id] = ['via' => $via, 'contrato_id' => (int) $c->id, 'contrato' => (string) $c->numero, 'terceiro_id' => (int) $c->terceiro_id, 'estado' => (string) $c->estado];
            }
        }

        return $saida;
    }

    /**
     * Mapa do parque por piso (do mais alto para o mais baixo), com a ocupação, as matrículas e os conflitos de porte. O resumo conta todas as vagas
     * (antes do filtro de texto).
     *
     * @return array{pisos: list<array<string, mixed>>, total: int, resumo: ?array<string, mixed>, avisos: list<string>}
     */
    public function mapa(Imovel $imovel, ?string $texto = null): array
    {
        $vagas = $this->vagas($imovel);
        if ($vagas->isEmpty()) {
            return ['pisos' => [], 'total' => 0, 'resumo' => null, 'avisos' => []];
        }
        $ocup = $this->ocupacoes($vagas);
        $maes = Fracao::query()->whereIn('id', $vagas->pluck('park_fracao_id')->filter()->unique())->get(['id', 'designacao', 'codigo'])->keyBy('id');
        $terceiros = Terceiro::query()->whereIn('id', collect($ocup)->pluck('terceiro_id')->unique())->get(['id', 'nome'])->keyBy('id');
        $veic = VeiculoContrato::query()->whereIn('contrato_id', collect($ocup)->pluck('contrato_id')->unique())->orderBy('ordem')->orderBy('id')->get()->groupBy('contrato_id');
        $linhas = [];
        foreach ($vagas as $v) {
            $o = $ocup[(int) $v->id] ?? null;
            $veiculos = $o ? ($veic[$o['contrato_id']] ?? collect())->filter(fn ($x) => $x->vaga_id === null || (int) $x->vaga_id === (int) $v->id)->values() : collect();
            $conflito = $veiculos->filter(fn ($x) => $x->porte && ! CatalogoParqueamento::porteCabe($v->park_porte, $x->porte))->pluck('matricula')->values()->all();
            $mae = $v->park_fracao_id ? ($maes[$v->park_fracao_id] ?? null) : null;
            $linhas[] = [
                'id' => (int) $v->id, 'codigo' => $v->codigo, 'designacao' => $v->designacao, 'piso' => $v->piso, 'andar' => Normaliza::texto($v->andar) ?? ($v->piso === null ? '—' : CatalogoImobiliario::nomePiso((int) $v->piso)),
                'afectacao' => $v->park_afectacao ?? 'AUTONOMA', 'tipo' => $v->park_tipo, 'porte' => $v->park_porte, 'altura_max' => $v->park_altura_max, 'tomada' => (bool) $v->park_tomada,
                'distico' => $v->park_distico, 'comprimento' => $v->park_comprimento, 'largura' => $v->park_largura, 'area' => $v->area_bruta, 'notas' => $v->notas, 'estado' => $v->estado, 'renda_referencia' => $v->renda_referencia, 'fracao_id' => $v->park_fracao_id ? (int) $v->park_fracao_id : null,
                'afecta_a' => $mae ? ($mae->designacao ?: $mae->codigo) : null, 'incluida_na_renda' => $v->park_incluido_na_renda, 'arrendavel' => CatalogoParqueamento::vagaArrendavel($v->natureza, $v->park_afectacao),
                'ocupada' => $o !== null, 'via' => $o['via'] ?? null, 'contrato_id' => $o['contrato_id'] ?? null, 'contrato' => $o['contrato'] ?? null,
                'ocupante' => $o ? ($terceiros[$o['terceiro_id']]->nome ?? null) : null,
                'veiculos' => $veiculos->map(fn ($x) => ['matricula' => $x->matricula, 'marca' => $x->marca, 'modelo' => $x->modelo, 'porte' => $x->porte])->all(), 'conflito_porte' => $conflito,
            ];
        }
        $resumo = $this->resumo($linhas);
        $q = mb_strtolower(trim((string) $texto));
        $visiveis = $q === '' ? $linhas : array_values(array_filter($linhas, fn ($l) => str_contains(mb_strtolower(implode(' ', array_filter([$l['designacao'], $l['codigo'], $l['distico'], $l['afecta_a'], $l['ocupante'], $l['contrato'],
            ...array_column($l['veiculos'], 'matricula')]))), $q)));
        $grupos = [];
        foreach ($visiveis as $l) {
            $grupos[$l['andar']]['andar'] = $l['andar'];
            $grupos[$l['andar']]['piso'] = $l['piso'];
            $grupos[$l['andar']]['vagas'][] = $l;
        }
        $pisos = array_values($grupos);
        usort($pisos, fn ($a, $b) => ($b['piso'] ?? -999) <=> ($a['piso'] ?? -999));
        foreach ($pisos as &$p) {
            usort($p['vagas'], fn ($a, $b) => strnatcasecmp((string) $a['designacao'], (string) $b['designacao']));
            $p['ocupadas'] = count(array_filter($p['vagas'], fn ($l) => $l['ocupada']));
        }
        unset($p);

        return ['pisos' => $pisos, 'total' => count($linhas), 'resumo' => $resumo, 'avisos' => $this->avisosDe($linhas, $vagas)];
    }

    /**
     * @param  list<array<string, mixed>>  $linhas
     * @return array<string, mixed>
     */
    public function resumo(array $linhas): array
    {
        $arrendaveis = array_filter($linhas, fn ($l) => $l['arrendavel']);
        $ocupadas = array_filter($arrendaveis, fn ($l) => $l['ocupada']);
        $n = count($arrendaveis);

        return [
            'total' => count($linhas), 'arrendaveis' => $n, 'ocupadas' => count($ocupadas), 'livres' => $n - count($ocupadas),
            'afectas' => count(array_filter($linhas, fn ($l) => $l['afectacao'] === 'PERMANENTE')), 'autonomas' => count(array_filter($linhas, fn ($l) => $l['afectacao'] === 'AUTONOMA')),
            'visita' => count(array_filter($linhas, fn ($l) => in_array($l['afectacao'], ['VISITA', 'CARGA_DESCARGA'], true))),
            'taxa' => $n > 0 ? (int) round(count($ocupadas) / $n * 100, 0, PHP_ROUND_HALF_UP) : null,
            'por_contrato_proprio' => count(array_filter($linhas, fn ($l) => $l['via'] === 'PROPRIO')), 'por_fraccao' => count(array_filter($linhas, fn ($l) => $l['via'] === 'FRACCAO')),
            'com_tomada' => count(array_filter($linhas, fn ($l) => $l['tomada'])), 'conflitos' => count(array_filter($linhas, fn ($l) => $l['conflito_porte'] !== [])),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $linhas
     * @param  Collection<int, Fracao>  $vagas
     * @return list<string>
     */
    private function avisosDe(array $linhas, Collection $vagas): array
    {
        $avisos = [];
        $duplas = $this->vagasPrometidasDuasVezes($vagas);
        if ($duplas > 0) {
            $avisos[] = "{$duplas} vaga(s) estão num contrato próprio E afectas a uma fracção arrendada: o mesmo lugar está prometido a duas pessoas.";
        }
        $semRenda = count(array_filter($linhas, fn ($l) => $l['arrendavel'] && ! ($l['renda_referencia'] > 0)));
        if ($semRenda > 0) {
            $avisos[] = "{$semRenda} vaga(s) arrendáveis sem renda de referência.";
        }
        $porte = count(array_filter($linhas, fn ($l) => $l['conflito_porte'] !== []));
        if ($porte > 0) {
            $avisos[] = "{$porte} vaga(s) com veículo maior do que o porte do lugar.";
        }
        $semAltura = count(array_filter($linhas, fn ($l) => $l['piso'] !== null && $l['piso'] < 0 && ! ($l['altura_max'] > 0)));
        if ($semAltura > 0) {
            $avisos[] = "{$semAltura} vaga(s) em cave sem altura máxima — é o que evita prometer um lugar onde o carro não entra.";
        }

        return $avisos;
    }

    /** Vagas com contrato próprio vivo e, ao mesmo tempo, afectas a uma fracção com contrato em vigor. */
    private function vagasPrometidasDuasVezes(Collection $vagas): int
    {
        $afectas = $vagas->where('park_afectacao', 'PERMANENTE')->filter(fn ($v) => $v->park_fracao_id);
        if ($afectas->isEmpty()) {
            return 0;
        }
        $proprias = $this->idsComContratoVivo($afectas->pluck('id')->all());
        $maes = $this->idsComContratoVivo($afectas->pluck('park_fracao_id')->unique()->all());

        return $afectas->filter(fn ($v) => isset($proprias[(int) $v->id]) && isset($maes[(int) $v->park_fracao_id]))->count();
    }

    /**
     * @param  list<int>  $fracaoIds
     * @return array<int, true>
     */
    private function idsComContratoVivo(array $fracaoIds, bool $soActivos = false): array
    {
        if ($fracaoIds === []) {
            return [];
        }
        $pares = ContratoFracao::query()->whereIn('fracao_id', $fracaoIds)->where('posicao', 'SENHORIO')->get(['contrato_id', 'fracao_id']);
        $vivos = ContratoArrendamento::query()->whereIn('id', $pares->pluck('contrato_id')->unique())->whereIn('estado', $soActivos ? ['ACTIVO'] : self::ESTADOS_CONTRATO_VIVOS)->pluck('id')->flip();

        return $pares->filter(fn ($p) => isset($vivos[$p->contrato_id]))->pluck('fracao_id')->mapWithKeys(fn ($i) => [(int) $i => true])->all();
    }

    /** @return list<array<string, mixed>> lugares arrendáveis, sem ocupação e fora de obra ou indisponíveis */
    public function livres(Imovel $imovel): array
    {
        $vagas = $this->vagas($imovel)->filter(fn ($v) => CatalogoParqueamento::vagaArrendavel($v->natureza, $v->park_afectacao) && ! in_array($v->estado, ['EM_OBRA', 'INDISPONIVEL'], true));
        $ocup = $this->ocupacoes($vagas, false);

        return $vagas->filter(fn ($v) => ! isset($ocup[(int) $v->id]))->map(fn ($v) => ['id' => (int) $v->id, 'designacao' => $v->designacao, 'afectacao' => $v->park_afectacao, 'renda_referencia' => $v->renda_referencia])->values()->all();
    }

    /**
     * Encargos propostos ao contrato por cada vaga afecta às fracções indicadas: valor da renda de referência e se vai incluída ou acrescida.
     * É só uma sugestão: o contrato decide (e a F3 grava os encargos com origem PARQUE).
     *
     * @param  list<int>  $fracaoIds
     * @return list<array<string, mixed>>
     */
    public function encargosPropostos(array $fracaoIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $fracaoIds))));
        if ($ids === []) {
            return [];
        }
        $saida = [];
        $vagas = Fracao::query()->where('natureza', 'ESTACIONAMENTO')->where('park_afectacao', 'PERMANENTE')->whereIn('park_fracao_id', $ids)->orderBy('ordem')->orderBy('id')->get();
        foreach ($vagas as $v) {
            $detalhe = array_filter([CatalogoParqueamento::PARK_TIPOS[$v->park_tipo ?? '']['rot'] ?? null, CatalogoParqueamento::PARK_PORTES[$v->park_porte ?? '']['rot'] ?? null,
                $v->park_tomada ? 'com tomada' : null, $v->park_altura_max > 0 ? 'altura máx. '.rtrim(rtrim(number_format((float) $v->park_altura_max, 2, '.', ''), '0'), '.').' m' : null]);
            $saida[] = ['fracao_id' => (int) $v->park_fracao_id, 'vaga_id' => (int) $v->id, 'origem' => 'PARQUE', 'designacao' => 'Lugar de estacionamento '.$v->designacao,
                'valor' => (float) ($v->renda_referencia ?? 0), 'incluido_na_renda' => $v->park_incluido_na_renda, 'detalhe' => implode(' · ', $detalhe)];
        }

        return $saida;
    }

    // ---------------------------------------------------------------- cadastro de vagas

    /**
     * Gera as vagas por piso (`G-101`, `G-201`; ao R/C `G-01`); gerar duas vezes não duplica (o que já existe é saltado e contado).
     *
     * @param  array<string, mixed>  $o  pisos (lista ou CSV), por_piso, prefixo, bloco, park_tipo, park_porte, park_altura_max, park_comprimento, park_largura, park_tomada, area_bruta, renda_referencia, afectacao
     * @return array{criadas: int, repetidas: int}
     */
    public function gerarVagas(Imovel $imovel, array $o): array
    {
        $pisos = collect(is_array($o['pisos'] ?? null) ? $o['pisos'] : explode(',', (string) ($o['pisos'] ?? '-1')))->map(fn ($p) => trim((string) $p))->filter(fn ($p) => $p !== '' && is_numeric($p))
            ->map(fn ($p) => (int) round((float) $p))->unique()->values();
        if ($pisos->isEmpty()) {
            throw new ErroNegocio('Indique o(s) piso(s) das vagas (use números negativos para as caves).', 'PARQUE_PISOS');
        }
        if (($o['afectacao'] ?? null) === 'PERMANENTE') {
            throw new ErroNegocio('Gere as vagas como autónomas (ou de uso comum) e afecte-as depois a uma fracção.', 'PARQUE_GERAR_PERMANENTE');
        }
        $porPiso = max(1, Normaliza::inteiro($o['por_piso'] ?? 1));
        if ($pisos->count() * $porPiso > CatalogoParqueamento::MAXIMO_VAGAS) {
            throw new ErroNegocio('São mais de '.CatalogoParqueamento::MAXIMO_VAGAS.' lugares de uma vez. Gere por partes.', 'PARQUE_DEMASIADAS');
        }
        $prefixo = mb_strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) ($o['prefixo'] ?? 'G')));
        $prefixo = $prefixo === '' ? 'G' : mb_substr($prefixo, 0, 4);
        $molde = $this->normalizarVaga(['designacao' => 'x', 'afectacao' => $o['afectacao'] ?? 'AUTONOMA', 'park_tipo' => $o['park_tipo'] ?? null, 'park_porte' => $o['park_porte'] ?? null,
            'park_altura_max' => $o['park_altura_max'] ?? null, 'park_comprimento' => $o['park_comprimento'] ?? null, 'park_largura' => $o['park_largura'] ?? null,
            'park_tomada' => $o['park_tomada'] ?? false, 'area_bruta' => $o['area_bruta'] ?? 0, 'renda_referencia' => $o['renda_referencia'] ?? null,
            'piso' => $pisos->every(fn ($p) => $p < 0) ? -1 : 0], $imovel, null);
        $bloco = Normaliza::texto($o['bloco'] ?? null);

        return DB::transaction(function () use ($imovel, $pisos, $porPiso, $prefixo, $molde, $bloco) {
            Imovel::query()->whereKey($imovel->id)->lockForUpdate()->first();
            $existentes = Fracao::query()->where('imovel_id', $imovel->id)->get(['id', 'codigo', 'designacao', 'ordem']);
            $usadas = $existentes->pluck('designacao')->map(fn ($x) => mb_strtolower($x))->flip()->all();
            $codigos = $existentes->pluck('codigo')->map(fn ($x) => mb_strtolower($x))->flip()->all();
            $ordem = (int) $existentes->max('ordem');
            $criadas = 0;
            $repetidas = 0;
            foreach ($pisos as $piso) {
                for ($k = 1; $k <= $porPiso; $k++) {
                    $designacao = CatalogoParqueamento::designacaoVaga($prefixo, $piso, $k);
                    $codigo = $imovel->codigo.'-'.$prefixo.str_pad((string) CatalogoParqueamento::sequenciaVaga($piso, $k), 3, '0', STR_PAD_LEFT);
                    if (isset($usadas[mb_strtolower($designacao)]) || isset($codigos[mb_strtolower($codigo)])) {
                        $repetidas++;

                        continue;
                    }
                    $usadas[mb_strtolower($designacao)] = true;
                    $codigos[mb_strtolower($codigo)] = true;
                    $tipo = $molde['park_tipo'] ?? ($pisos->every(fn ($p) => $p < 0) ? 'SUBTERRANEO' : 'DESCOBERTO');
                    $this->gravarNova($imovel, array_merge($molde, ['codigo' => $codigo, 'designacao' => $designacao, 'bloco' => $bloco, 'andar' => CatalogoImobiliario::nomePiso($piso), 'piso' => $piso,
                        'numero' => (string) CatalogoParqueamento::sequenciaVaga($piso, $k), 'park_tipo' => $tipo, 'ordem' => ++$ordem, 'estado' => 'DISPONIVEL']));
                    $criadas++;
                }
            }

            return ['criadas' => $criadas, 'repetidas' => $repetidas];
        });
    }

    /** @param  array<string, mixed>  $d */
    public function criarVaga(Imovel $imovel, array $d): Fracao
    {
        return DB::transaction(function () use ($imovel, $d) {
            Imovel::query()->whereKey($imovel->id)->lockForUpdate()->first();
            $reg = $this->normalizarVaga($d, $imovel, null);
            $existentes = Fracao::query()->where('imovel_id', $imovel->id)->get(['id', 'codigo', 'designacao', 'ordem']);
            $this->conferirDesignacao($existentes, $reg['designacao'], null);
            $reg['codigo'] = $reg['codigo'] ?? $this->proximoCodigo($imovel, $existentes);
            if ($existentes->contains(fn ($u) => mb_strtolower($u->codigo) === mb_strtolower($reg['codigo']))) {
                throw new ErroNegocio("Já existe uma fracção ou vaga com o código {$reg['codigo']} neste imóvel.", 'PARQUE_CODIGO');
            }
            $reg['ordem'] = (int) $existentes->max('ordem') + 1;
            $estado = (string) ($d['estado'] ?? 'DISPONIVEL');
            $reg['estado'] = in_array($estado, ['DISPONIVEL', 'EM_OBRA', 'INDISPONIVEL'], true) ? $estado : 'DISPONIVEL';

            return $this->gravarNova($imovel, $reg);
        });
    }

    /** @param  array<string, mixed>  $d  o estado não muda por aqui (usar POST fracoes/{id}/estado, com trilho); o código, o bloco e o número mantêm-se */
    public function actualizarVaga(Fracao $vaga, array $d): Fracao
    {
        return DB::transaction(function () use ($vaga, $d) {
            $v = Fracao::query()->whereKey($vaga->id)->lockForUpdate()->firstOrFail();
            $this->exigirVaga($v);
            $imovel = Imovel::query()->findOrFail($v->imovel_id);
            $reg = $this->normalizarVaga($d + ['designacao' => $v->designacao], $imovel, $v);
            $existentes = Fracao::query()->where('imovel_id', $imovel->id)->get(['id', 'codigo', 'designacao', 'ordem']);
            $this->conferirDesignacao($existentes, $reg['designacao'], $v);
            if (! CatalogoParqueamento::PARK_AFECTACOES[$reg['park_afectacao']]['arrendavel'] && $this->temContratoVivo((int) $v->id)) {
                throw new ErroNegocio('A vaga está num contrato. Uma vaga de uso comum não se arrenda — termine o contrato primeiro.', 'PARQUE_OCUPADA');
            }
            if ($reg['park_afectacao'] === 'PERMANENTE' && (int) $v->park_fracao_id !== (int) $reg['park_fracao_id'] && $this->temContratoVivo((int) $v->id)) {
                throw new ErroNegocio('A vaga tem contrato próprio: não se afecta a uma fracção (o mesmo lugar ficaria prometido a duas pessoas).', 'PARQUE_PROMETIDA_DUAS');
            }
            unset($reg['codigo'], $reg['bloco'], $reg['numero'], $reg['andar'], $reg['ordem'], $reg['estado']);
            if (array_key_exists('piso', $d)) {
                $reg['andar'] = $reg['piso'] === null ? $v->andar : CatalogoImobiliario::nomePiso((int) $reg['piso']);
            } else {
                unset($reg['piso']);
            }
            $v->update($reg + ['atualizado_por' => $this->utilizador()]);

            return $v->refresh();
        });
    }

    public function eliminarVaga(Fracao $vaga): void
    {
        $this->exigirVaga($vaga);
        if (in_array($vaga->estado, CatalogoImobiliario::EXIGEM_MOTIVO_AO_SAIR, true) || ContratoFracao::query()->where('fracao_id', $vaga->id)->exists()) {
            throw new ErroNegocio('A vaga tem ou já teve contrato: não se apaga. Ponha-a como indisponível.', 'PARQUE_EM_USO');
        }
        try {
            DB::transaction(function () use ($vaga) {
                HistoricoEstadoFracao::query()->where('fracao_id', $vaga->id)->delete();
                $vaga->delete();
            });
        } catch (QueryException) {
            throw new ErroNegocio('A vaga tem registos ligados e não pode ser apagada. Ponha-a como indisponível.', 'PARQUE_EM_USO');
        }
    }

    // ---------------------------------------------------------------- afectação

    /**
     * Afecta vagas a uma fracção: arrendar a fracção leva os lugares junto. Recusa a vaga que já é de outra fracção (a menos que `$reatribuir`),
     * a de uso comum e a que tem contrato próprio vivo.
     *
     * @param  list<int>  $vagaIds
     * @return array{afectadas: int, avisos: list<string>}
     */
    public function afectar(Fracao $mae, array $vagaIds, ?bool $incluidaNaRenda, bool $reatribuir = false): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $vagaIds))));
        if ($ids === []) {
            throw new ErroNegocio('Escolha pelo menos um lugar.', 'PARQUE_SEM_VAGAS');
        }
        if (! CatalogoImobiliario::principal($mae->natureza)) {
            throw new ErroNegocio('Só se afectam lugares a uma fracção principal, não a outra vaga ou arrecadação.', 'PARQUE_MAE_INVALIDA');
        }

        return DB::transaction(function () use ($mae, $ids, $incluidaNaRenda, $reatribuir) {
            $mae = Fracao::query()->whereKey($mae->id)->lockForUpdate()->firstOrFail();
            $vagas = Fracao::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            if ($vagas->count() !== count($ids)) {
                throw new ErroNegocio('Há lugares escolhidos que não existem.', 'PARQUE_VAGA_INEXISTENTE', 404);
            }
            $avisos = [];
            $proprios = $this->idsComContratoVivo($ids);
            $maesAntigas = Fracao::query()->whereIn('id', $vagas->pluck('park_fracao_id')->filter()->unique())->get(['id', 'designacao'])->keyBy('id');
            foreach ($vagas as $v) {
                if (! CatalogoParqueamento::ehVaga($v->natureza) || (int) $v->imovel_id !== (int) $mae->imovel_id) {
                    throw new ErroNegocio("«{$v->designacao}» não é uma vaga deste imóvel.", 'PARQUE_VAGA_OUTRO_IMOVEL');
                }
                if (! CatalogoParqueamento::PARK_AFECTACOES[$v->park_afectacao ?? 'AUTONOMA']['arrendavel']) {
                    throw new ErroNegocio("«{$v->designacao}» é de uso comum (".mb_strtolower(CatalogoParqueamento::PARK_AFECTACOES[$v->park_afectacao]['rot']).'): não se afecta a uma fracção.', 'PARQUE_VAGA_USO_COMUM');
                }
                if (isset($proprios[(int) $v->id])) {
                    throw new ErroNegocio("«{$v->designacao}» tem contrato próprio: o mesmo lugar ficaria prometido a duas pessoas.", 'PARQUE_PROMETIDA_DUAS');
                }
                if ($v->park_afectacao === 'PERMANENTE' && $v->park_fracao_id && (int) $v->park_fracao_id !== (int) $mae->id) {
                    $de = $maesAntigas[$v->park_fracao_id]->designacao ?? 'outra fracção';
                    if (! $reatribuir) {
                        throw new ErroNegocio("«{$v->designacao}» já está afecta a {$de}. Liberte-a primeiro ou confirme a mudança para esta fracção.", 'PARQUE_JA_AFECTA', 409,
                            ['vaga_id' => (int) $v->id, 'afecta_a' => $de]);
                    }
                    $avisos[] = "«{$v->designacao}» passou de {$de} para {$mae->designacao}. Reveja os encargos dos contratos de ambas.";
                }
                $v->update(['park_afectacao' => 'PERMANENTE', 'park_fracao_id' => $mae->id,
                    'park_incluido_na_renda' => $incluidaNaRenda === null ? $v->park_incluido_na_renda : $incluidaNaRenda, 'atualizado_por' => $this->utilizador()]);
            }

            return ['afectadas' => $vagas->count(), 'avisos' => $avisos];
        });
    }

    /**
     * Liberta vagas: voltam a autónomas. Avisa se estavam a ser usadas por um contrato da fracção a que estavam afectas.
     *
     * @param  list<int>  $vagaIds
     * @return array{libertadas: int, avisos: list<string>}
     */
    public function libertar(array $vagaIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $vagaIds))));
        if ($ids === []) {
            throw new ErroNegocio('Escolha pelo menos um lugar.', 'PARQUE_SEM_VAGAS');
        }

        return DB::transaction(function () use ($ids) {
            $vagas = Fracao::query()->whereIn('id', $ids)->where('natureza', 'ESTACIONAMENTO')->orderBy('id')->lockForUpdate()->get();
            $ocup = $this->ocupacoes($vagas);
            $avisos = [];
            foreach ($vagas as $v) {
                if (($ocup[(int) $v->id]['via'] ?? null) === 'FRACCAO') {
                    $avisos[] = "«{$v->designacao}» estava a ser usada pelo contrato {$ocup[(int) $v->id]['contrato']} da fracção. Reveja os encargos desse contrato.";
                }
                $v->update(['park_afectacao' => 'AUTONOMA', 'park_fracao_id' => null, 'park_incluido_na_renda' => null, 'atualizado_por' => $this->utilizador()]);
            }

            return ['libertadas' => $vagas->count(), 'avisos' => $avisos];
        });
    }

    // ---------------------------------------------------------------- internos

    private function exigirVaga(Fracao $f): void
    {
        if (! CatalogoParqueamento::ehVaga($f->natureza)) {
            throw new ErroNegocio('Este registo não é uma vaga de estacionamento.', 'PARQUE_NAO_VAGA', 404);
        }
    }

    private function temContratoVivo(int $fracaoId): bool
    {
        return $this->idsComContratoVivo([$fracaoId]) !== [];
    }

    /** @param  Collection<int, Fracao>  $irmas */
    private function conferirDesignacao(Collection $irmas, string $designacao, ?Fracao $actual): void
    {
        $outras = $actual ? $irmas->where('id', '!=', $actual->id) : $irmas;
        if ($outras->contains(fn ($u) => mb_strtolower($u->designacao) === mb_strtolower($designacao))) {
            throw new ErroNegocio("Já existe «{$designacao}» neste imóvel.", 'PARQUE_DESIGNACAO');
        }
    }

    /** Código `<imóvel>-V001`: maior sufixo `V` já usado + 1. @param  Collection<int, Fracao>  $irmas */
    private function proximoCodigo(Imovel $imovel, Collection $irmas): string
    {
        $prefixo = mb_strtolower($imovel->codigo).'-v';
        $maior = 0;
        foreach ($irmas as $u) {
            $c = mb_strtolower((string) $u->codigo);
            if (str_starts_with($c, $prefixo) && ctype_digit(substr($c, strlen($prefixo)))) {
                $maior = max($maior, (int) substr($c, strlen($prefixo)));
            }
        }

        return $imovel->codigo.'-V'.str_pad((string) ($maior + 1), 3, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    private function normalizarVaga(array $d, Imovel $imovel, ?Fracao $actual): array
    {
        $designacao = Normaliza::texto($d['designacao'] ?? null);
        if ($designacao === null) {
            throw new ErroNegocio('Dê uma designação à vaga (ex.: «G-101»).', 'PARQUE_SEM_DESIGNACAO');
        }
        $afectacao = (string) ($d['afectacao'] ?? $d['park_afectacao'] ?? $actual?->park_afectacao ?? 'AUTONOMA');
        if (! isset(CatalogoParqueamento::PARK_AFECTACOES[$afectacao])) {
            throw new ErroNegocio('Afectação desconhecida.', 'PARQUE_AFECTACAO');
        }
        $piso = Normaliza::inteiro($d['piso'] ?? $actual?->piso, true);
        $tipo = $d['park_tipo'] ?? $actual?->park_tipo ?? null;
        $tipo = $tipo === null || $tipo === '' ? (($piso ?? 0) < 0 ? 'SUBTERRANEO' : 'DESCOBERTO') : (string) $tipo;
        $porte = (string) (($d['park_porte'] ?? null) ?: ($actual?->park_porte ?? 'LIGEIRO'));
        if (! isset(CatalogoParqueamento::PARK_TIPOS[$tipo]) || ! isset(CatalogoParqueamento::PARK_PORTES[$porte])) {
            throw new ErroNegocio('Tipo ou porte da vaga inválidos.', 'PARQUE_LISTA');
        }
        $maeId = null;
        if ($afectacao === 'PERMANENTE') {
            $maeId = Normaliza::inteiro($d['fracao_id'] ?? $d['park_fracao_id'] ?? $actual?->park_fracao_id, true);
            if (! $maeId) {
                throw new ErroNegocio('Escolha a fracção a que a vaga fica afecta.', 'PARQUE_SEM_MAE');
            }
            $mae = Fracao::query()->find($maeId) ?? throw new ErroNegocio('Fracção não encontrada.', 'PARQUE_MAE_INEXISTENTE', 404);
            if ((int) $mae->imovel_id !== (int) $imovel->id || ! CatalogoImobiliario::principal($mae->natureza)) {
                throw new ErroNegocio('A fracção tem de ser uma fracção principal deste imóvel.', 'PARQUE_MAE_INVALIDA');
            }
        }
        $positivo = fn ($k) => ($x = Normaliza::r2(array_key_exists($k, $d) ? $d[$k] : $actual?->{$k}, true)) !== null && $x > 0 ? $x : null;
        $area = Normaliza::r2($d['area_bruta'] ?? $d['area_util'] ?? $actual?->area_bruta);
        $renda = Normaliza::r2($d['renda_referencia'] ?? null, true);
        if ($renda !== null && $renda < 0) {
            throw new ErroNegocio('A renda de referência não pode ser negativa.', 'PARQUE_VALORES');
        }
        if ($actual !== null && ! array_key_exists('renda_referencia', $d)) {
            $renda = $actual->renda_referencia;
        }
        $incluida = array_key_exists('incluida_na_renda', $d) ? ($d['incluida_na_renda'] === null || $d['incluida_na_renda'] === '' ? null : filter_var($d['incluida_na_renda'], FILTER_VALIDATE_BOOLEAN)) : $actual?->park_incluido_na_renda;

        return [
            'codigo' => Normaliza::texto($d['codigo'] ?? null), 'designacao' => mb_substr($designacao, 0, 120), 'natureza' => 'ESTACIONAMENTO', 'bloco' => Normaliza::texto($d['bloco'] ?? null),
            'andar' => $piso === null ? null : CatalogoImobiliario::nomePiso($piso), 'numero' => Normaliza::texto($d['numero'] ?? null), 'piso' => $piso,
            'park_afectacao' => $afectacao, 'park_fracao_id' => $maeId, 'park_tipo' => $tipo, 'park_porte' => $porte, 'park_altura_max' => $positivo('park_altura_max'),
            'park_comprimento' => $positivo('park_comprimento'), 'park_largura' => $positivo('park_largura'),
            'park_tomada' => array_key_exists('park_tomada', $d) ? filter_var($d['park_tomada'], FILTER_VALIDATE_BOOLEAN) : (bool) ($actual?->park_tomada),
            'park_distico' => array_key_exists('park_distico', $d) ? mb_substr((string) Normaliza::texto($d['park_distico']), 0, 60) ?: null : $actual?->park_distico,
            'park_incluido_na_renda' => $afectacao === 'PERMANENTE' ? $incluida : null, 'tipologia' => 'GARAGEM', 'finalidade' => 'ESTACIONAMENTO', 'area_bruta' => $area, 'area_util' => $area,
            'mobilada' => 'NAO', 'renda_referencia' => $renda, 'notas' => array_key_exists('notas', $d) ? Normaliza::texto($d['notas']) : $actual?->notas,
        ];
    }

    /** @param  array<string, mixed>  $reg */
    private function gravarNova(Imovel $imovel, array $reg): Fracao
    {
        try {
            $f = Fracao::query()->create($reg + ['empresa_id' => $imovel->empresa_id, 'imovel_id' => $imovel->id, 'estado_desde' => now()->toDateString(), 'documentos' => [],
                'criado_por' => $this->utilizador(), 'atualizado_por' => $this->utilizador()]);
        } catch (UniqueConstraintViolationException) {
            throw new ErroNegocio('Já existe uma fracção ou vaga com esse código ou designação neste imóvel.', 'PARQUE_DUPLICADA');
        }
        HistoricoEstadoFracao::query()->create(['empresa_id' => $f->empresa_id, 'fracao_id' => $f->id, 'estado_de' => null, 'estado_para' => $f->estado, 'motivo' => null,
            'utilizador_id' => Auth::id(), 'em' => now()]);

        return $f;
    }

    private function utilizador(): string
    {
        return Autor::nome();
    }
}
