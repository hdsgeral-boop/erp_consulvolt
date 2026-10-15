<?php

namespace App\Services\Ativos;

use App\Exceptions\ErroNegocio;
use App\Models\AtivoImobilizado;
use App\Models\CategoriaAtivo;
use App\Models\OrdemManutencaoAtivo;
use App\Models\PlanoManutencaoAtivo;
use App\Models\Produto;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Planos de manutenção (Fase F, Versão Actual — js/activos_manutencao.js:116-217; UI activos_manutencao_ui.js:108-318).
 * Regras do legado mantidas:
 *   - código automático PM-AAAA/NNNN; aplica-se a um activo ou a toda uma categoria (excluindo os abatidos);
 *   - periodicidade por TEMPO (dias/semanas/meses/anos, exige a próxima data) ou por CONTADOR (horas/km, exige a leitura
 *     em que é devida); antecedência em dias, duração prevista, responsável, instruções, tarefas e itens previstos;
 *   - situação: TEMPO d = próxima − hoje (d < 0 vencido; d ≤ antecedência a vencer); CONTADOR falta = próximo − actual
 *     (falta ≤ 0 vencido; falta ≤ max(1, 10 % do intervalo) a vencer); inactivo; sem data (situacao :124-138);
 *   - um plano que já gerou ordens não se elimina: desactiva-se (eliminarPlano :202-209);
 *   - a edição não perde as execuções nem a data da última (gravarPlano :185-191).
 * Melhorias: a unidade tem de ser do modo escolhido (dias… para TEMPO, horas/km para CONTADOR); activo/categoria e artigos
 * dos itens verificados na empresa; código único.
 */
final class ServicoPlanosManutencao
{
    public function __construct(private readonly NumeracaoManutencao $numeracao) {}

    /** Situação do plano (situacao :124-138). */
    public static function situacao(PlanoManutencaoAtivo $p, ?string $hoje = null): array
    {
        if ($p->ativo === false) {
            return ['chave' => 'INACTIVO', 'rotulo' => 'Inactivo'];
        }
        $per = (array) ($p->periodicidade ?? []);
        if (($per['modo'] ?? 'TEMPO') === 'CONTADOR') {
            $falta = (float) $p->proximo_contador - (float) $p->contador_atual;
            $unidade = ($per['unidade'] ?? '') === 'KM' ? 'quilómetros' : 'horas de trabalho';
            if ($falta <= 0) {
                return ['chave' => 'VENCIDO', 'rotulo' => 'Vencido', 'falta' => $falta];
            }
            $aviso = max(1, (float) ($per['intervalo'] ?? 0) * 0.1);

            return ['chave' => $falta <= $aviso ? 'A_VENCER' : 'EM_DIA', 'rotulo' => 'Faltam '.round($falta)." {$unidade}", 'falta' => $falta];
        }
        if (! $p->proxima_data) {
            return ['chave' => 'SEM_DATA', 'rotulo' => 'Sem data'];
        }
        $d = (int) CarbonImmutable::parse($hoje ?? now()->toDateString())->startOfDay()->diffInDays(CarbonImmutable::parse($p->proxima_data->toDateString())->startOfDay(), false);
        if ($d < 0) {
            return ['chave' => 'VENCIDO', 'rotulo' => 'Em atraso '.abs($d).' dia(s)', 'dias' => $d];
        }
        if ($d <= (int) $p->antecedencia_dias) {
            return ['chave' => 'A_VENCER', 'rotulo' => $d === 0 ? 'Vence hoje' : "Faltam {$d} dia(s)", 'dias' => $d];
        }

        return ['chave' => 'EM_DIA', 'rotulo' => "Faltam {$d} dia(s)", 'dias' => $d];
    }

    /** Soma o intervalo a uma data (somar :84-94). */
    public static function somar(string $data, int $intervalo, string $unidade): ?string
    {
        $d = CarbonImmutable::parse(substr($data, 0, 10));
        $n = max(1, $intervalo);

        return match ($unidade) {
            'DIAS' => $d->addDays($n)->toDateString(),
            'SEMANAS' => $d->addWeeks($n)->toDateString(),
            'MESES' => $d->addMonthsNoOverflow($n)->toDateString(),
            'ANOS' => $d->addYearsNoOverflow($n)->toDateString(),
            default => null,
        };
    }

    /** Planos ordenados pela próxima data e nome (planos :117-121), com situação, alvo e n.º de ordens. */
    public function listar(): array
    {
        $planos = PlanoManutencaoAtivo::query()->with(['ativoImobilizado' => fn ($q) => $q->withTrashed()->select('id', 'codigo', 'descricao'), 'categoriaAtivo:id,nome'])->get();
        $ordens = OrdemManutencaoAtivo::query()->whereIn('plano_manutencao_id', $planos->pluck('id'))->selectRaw('plano_manutencao_id, COUNT(*) AS n')
            ->groupBy('plano_manutencao_id')->pluck('n', 'plano_manutencao_id');

        return $planos->sort(fn ($a, $b) => [(string) $a->proxima_data?->toDateString(), mb_strtolower((string) $a->nome)] <=> [(string) $b->proxima_data?->toDateString(), mb_strtolower((string) $b->nome)])
            ->values()->map(fn (PlanoManutencaoAtivo $p) => $p->toArray() + [
                'situacao' => self::situacao($p),
                'alvo' => $p->ativo_imobilizado_id ? trim(($p->ativoImobilizado?->codigo ?? '').' — '.($p->ativoImobilizado?->descricao ?? ''), ' —')
                    : 'Categoria: '.($p->categoriaAtivo?->nome ?? '—'),
                'ordens' => (int) ($ordens[$p->id] ?? 0),
            ])->all();
    }

    public function guardar(array $d, ?PlanoManutencaoAtivo $p = null): PlanoManutencaoAtivo
    {
        $reg = $this->validar($d);

        return DB::transaction(function () use ($reg, $p) {
            if ($reg['codigo'] !== '' && PlanoManutencaoAtivo::query()->where('codigo', $reg['codigo'])->when($p, fn ($q) => $q->whereKeyNot($p->id))->exists()) {
                throw new ErroNegocio("O código «{$reg['codigo']}» já está em uso.", 'CODIGO_DUPLICADO', 422);
            }
            if ($p) {
                $p = PlanoManutencaoAtivo::query()->lockForUpdate()->findOrFail($p->id);
                // a contagem de execuções, a data da última e quem criou não se perdem na edição (:188-189)
                $p->update(['codigo' => $reg['codigo'] !== '' ? $reg['codigo'] : $p->codigo] + $reg);

                return $p->refresh();
            }
            if ($reg['codigo'] === '') {
                $reg['codigo'] = $this->numeracao->proximo('PM');
            }

            return PlanoManutencaoAtivo::create($reg + ['ultima_data' => null, 'execucoes' => 0, 'criado_por' => Auth::user()?->nome_utilizador]);
        });
    }

    /** Activar / desactivar (activar-plano, UI :855-860). */
    public function alternar(PlanoManutencaoAtivo $p): PlanoManutencaoAtivo
    {
        $p->update(['ativo' => $p->ativo === false]);

        return $p->refresh();
    }

    public function eliminar(PlanoManutencaoAtivo $p): void
    {
        $usadas = OrdemManutencaoAtivo::query()->where('plano_manutencao_id', $p->id)->count();
        if ($usadas) {
            throw new ErroNegocio("O plano já gerou {$usadas} ordem(ns) de manutenção e não pode ser eliminado. Desactive-o para deixar de gerar ordens.", 'PLANO_COM_ORDENS', 422);
        }
        $p->delete();
    }

    /** Activos a que o plano se aplica: o activo indicado ou os da categoria que não estão abatidos (activosDoPlano :212-217). */
    public function alvos(PlanoManutencaoAtivo $p): Collection
    {
        if ($p->ativo_imobilizado_id) {
            return AtivoImobilizado::query()->whereKey($p->ativo_imobilizado_id)->get();
        }
        if ($p->categoria_ativo_id) {
            return AtivoImobilizado::query()->where('categoria_ativo_id', $p->categoria_ativo_id)
                ->where(fn ($q) => $q->whereNull('estado')->orWhere('estado', '<>', AtivoImobilizado::ESTADO_ABATIDO))->orderBy('codigo')->get();
        }

        return collect();
    }

    /** Validação e normalização (validarPlano :140-149 e gravarPlano :155-183). */
    private function validar(array $d): array
    {
        $nome = trim((string) ($d['nome'] ?? ''));
        if ($nome === '') {
            throw new ErroNegocio('Dê um nome ao plano (ex.: «Revisão trimestral do gerador»).', 'DADOS_INVALIDOS', 422);
        }
        $ativo = ! empty($d['ativo_imobilizado_id']) ? (int) $d['ativo_imobilizado_id'] : null;
        $categoria = ! $ativo && ! empty($d['categoria_ativo_id']) ? (int) $d['categoria_ativo_id'] : null;
        if (! $ativo && ! $categoria) {
            throw new ErroNegocio('Escolha o activo ou a categoria de activos a que o plano se aplica.', 'DADOS_INVALIDOS', 422);
        }
        if ($ativo && ! AtivoImobilizado::query()->whereKey($ativo)->exists()) {
            throw new ErroNegocio('Activo inexistente.', 'DADOS_INVALIDOS', 422);
        }
        if ($categoria && ! CategoriaAtivo::query()->whereKey($categoria)->exists()) {
            throw new ErroNegocio('Categoria inexistente.', 'DADOS_INVALIDOS', 422);
        }
        if (! in_array($d['tipo'] ?? null, PlanoManutencaoAtivo::TIPOS, true)) {
            throw new ErroNegocio('Escolha o tipo de manutenção.', 'DADOS_INVALIDOS', 422);
        }
        $per = (array) ($d['periodicidade'] ?? []);
        $modo = ($per['modo'] ?? 'TEMPO') === 'CONTADOR' ? 'CONTADOR' : 'TEMPO';
        $intervalo = (int) round((float) ($per['intervalo'] ?? 0));
        if ($intervalo < 1) {
            throw new ErroNegocio('Indique de quanto em quanto tempo (ou de quantas em quantas unidades) a manutenção se repete.', 'DADOS_INVALIDOS', 422);
        }
        $unidades = $modo === 'TEMPO' ? PlanoManutencaoAtivo::UNIDADES_TEMPO : PlanoManutencaoAtivo::UNIDADES_CONTADOR;
        if (! in_array($per['unidade'] ?? null, $unidades, true)) {
            throw new ErroNegocio('Escolha a unidade da periodicidade ('.implode(', ', $unidades).').', 'DADOS_INVALIDOS', 422);
        }
        $proximaData = ! empty($d['proxima_data']) ? substr((string) $d['proxima_data'], 0, 10) : null;
        if ($modo === 'TEMPO' && ! $proximaData) {
            throw new ErroNegocio('Indique a data da próxima manutenção.', 'DADOS_INVALIDOS', 422);
        }
        $proximoContador = isset($d['proximo_contador']) && $d['proximo_contador'] !== '' ? (float) $d['proximo_contador'] : null;
        if ($modo === 'CONTADOR' && ! ($proximoContador > 0)) {
            throw new ErroNegocio('Indique a leitura do contador em que a próxima manutenção é devida.', 'DADOS_INVALIDOS', 422);
        }
        $itens = [];
        foreach (array_values((array) ($d['itens'] ?? [])) as $n => $i) {
            $produto = ! empty($i['produto_id']) ? (int) $i['produto_id'] : null;
            $descricao = trim((string) ($i['descricao'] ?? ''));
            if (! $produto && $descricao === '') {
                continue;
            }
            if ($produto && ! Produto::query()->whereKey($produto)->exists()) {
                throw new ErroNegocio('Item '.($n + 1).': artigo inexistente.', 'DADOS_INVALIDOS', 422);
            }
            $itens[] = ['tipo' => ($i['tipo'] ?? '') === 'SERVICO' ? 'SERVICO' : 'MATERIAL', 'produto_id' => $produto, 'descricao' => $descricao,
                'quantidade' => (float) ($i['quantidade'] ?? 0) > 0 ? (float) $i['quantidade'] : 1.0];
        }

        return [
            'codigo' => trim((string) ($d['codigo'] ?? '')),
            'nome' => mb_substr($nome, 0, 255),
            'ativo_imobilizado_id' => $ativo,
            'categoria_ativo_id' => $categoria,
            'tipo' => $d['tipo'],
            'periodicidade' => ['modo' => $modo, 'intervalo' => $intervalo, 'unidade' => $per['unidade']],
            'proxima_data' => $proximaData,
            'proximo_contador' => $proximoContador,
            'contador_atual' => isset($d['contador_atual']) && $d['contador_atual'] !== '' ? max(0, (float) $d['contador_atual']) : 0,
            'antecedencia_dias' => max(0, (int) round((float) ($d['antecedencia_dias'] ?? 0))),
            'duracao_horas' => max(0, (float) ($d['duracao_horas'] ?? 0)),
            'responsavel' => trim((string) ($d['responsavel'] ?? '')) ?: null,
            'instrucoes' => trim((string) ($d['instrucoes'] ?? '')) ?: null,
            'tarefas' => array_values(array_filter(array_map(fn ($t) => mb_substr(trim((string) $t), 0, 500), (array) ($d['tarefas'] ?? [])), fn ($t) => $t !== '')),
            'itens' => $itens,
            'ativo' => ($d['ativo'] ?? true) !== false,
        ];
    }
}
