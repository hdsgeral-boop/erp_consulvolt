<?php

namespace App\Services\Contabilidade;

use App\Exceptions\ErroNegocio;
use App\Models\LancamentoContabil;
use App\Services\Sistema\ServicoAuditoria;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A-05 — classificação dos lançamentos já gravados (legado: applyBulkNotes, editJournalLine/saveJournalLine,
 * js/ui_lancamentos.js:2147-2267 e 2618-2701).
 *
 * Só altera campos NÃO financeiros: notas às demonstrações e de fluxo de caixa, unidade de negócio, centro de custo
 * e, na ficha, a descrição e o terceiro. Nunca altera a conta, o valor, o D/C, a data nem o diário (essa correcção
 * faz-se por estorno + novo lançamento, ADR-016).
 *
 * Regras (melhorias face ao legado, que escrevia directamente no IndexedDB):
 *   - linhas de exercícios encerrados recusam-se (a operação é atómica: nada é gravado);
 *   - original e estorno mantêm a mesma classificação: a alteração propaga-se ao par (senão a DR e o fluxo de caixa
 *     ficariam com +X numa nota e −X noutra);
 *   - o terceiro não muda em linhas compensadas/reconciliadas (o legado quebrava a compensação em silêncio);
 *   - auditoria com os valores anteriores e novos.
 */
final class ServicoClassificacaoLancamentos
{
    /** Campos de classificação (notas em massa e ficha): coluna => tabela de referência da empresa. */
    public const CAMPOS_CLASSIFICACAO = [
        'nota_demonstracao_id' => 'notas_demonstracao_resultados',
        'nota_fluxo_caixa_id' => 'notas_fluxo_caixa',
        'unidade_negocio_id' => 'unidades_negocio',
        'centro_custo_id' => 'centros_custo',
    ];

    /** Campos só editáveis na ficha da linha (tarefa lancamentos_editar). */
    public const CAMPOS_FICHA = ['descricao', 'terceiro_id'];

    /** Máximo de linhas numa operação (lotes maiores: restringir os filtros). */
    public const MAXIMO = 5000;

    /** Filtro «linhas sem …» do legado (filterByEmpty): chave => coluna. */
    public const SEM = ['demo' => 'nota_demonstracao_id', 'fluxo' => 'nota_fluxo_caixa_id', 'un' => 'unidade_negocio_id', 'cc' => 'centro_custo_id'];

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoExercicios $exercicios,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    /**
     * Filtros da lista de lançamentos (GET /contabilidade/lancamentos), partilhados com a classificação por filtro.
     *
     * @param  Builder<LancamentoContabil>  $q
     * @param  array<string, mixed>  $f
     * @return Builder<LancamentoContabil>
     */
    public static function filtrar(Builder $q, array $f): Builder
    {
        $like = fn (string $v) => str_replace(['%', '_'], ['\%', '\_'], $v);

        return $q
            ->when($f['diario_id'] ?? null, fn ($q, $v) => $q->where('diario_id', $v))
            ->when($f['codigo_conta'] ?? null, fn ($q, $v) => $q->where('codigo_conta', 'like', $like($v).'%'))
            ->when($f['filtro_contas'] ?? null, function ($q, $v) {
                [$sql, $p] = FiltroMapas::contas('codigo_conta', (string) $v);

                return $q->whereRaw($sql, $p);
            })
            ->when($f['numero_lan'] ?? null, fn ($q, $v) => $q->where('numero_lan', $v))
            ->when($f['numero_documento'] ?? null, fn ($q, $v) => $q->where('numero_documento', $v))
            ->when($f['referencia'] ?? null, fn ($q, $v) => $q->where('referencia', 'ilike', '%'.$like($v).'%'))
            ->when($f['terceiro_id'] ?? null, fn ($q, $v) => $q->where('terceiro_id', $v))
            ->when($f['unidade_negocio_id'] ?? null, fn ($q, $v) => $q->where('unidade_negocio_id', $v))
            ->when($f['centro_custo_id'] ?? null, fn ($q, $v) => $q->where('centro_custo_id', $v))
            ->when($f['data_inicio'] ?? null, fn ($q, $v) => $q->where('data_documento', '>=', $v))
            ->when($f['data_fim'] ?? null, fn ($q, $v) => $q->where('data_documento', '<=', $v))
            ->when($f['pesquisa'] ?? null, fn ($q, $v) => $q->where('descricao', 'ilike', '%'.$like($v).'%'))
            ->when($f['sem'] ?? null, function ($q, $sem) {
                foreach ((array) $sem as $s) {
                    if (isset(self::SEM[$s])) {
                        $q->whereNull(self::SEM[$s]);
                    }
                }

                return $q;
            })
            ->when(empty($f['incluir_classe_9']), fn ($q) => $q->where('codigo_conta', 'not like', '9%'));
    }

    /**
     * Aplica os campos às linhas indicadas (ids) ou às linhas que satisfazem os filtros.
     * Em $campos, uma chave ausente mantém o valor; null remove-o (o «[REMOVER NOTA]» do legado).
     *
     * @param  list<int>|null  $ids
     * @param  array<string, mixed>|null  $filtros
     * @param  array<string, mixed>  $campos
     * @return array{actualizadas: int, propagadas: int}
     */
    public function aplicar(?array $ids, ?array $filtros, array $campos): array
    {
        $empresa = $this->contexto->obrigatorio();
        $campos = array_intersect_key($campos, self::CAMPOS_CLASSIFICACAO + array_flip(self::CAMPOS_FICHA));
        if (! $campos) {
            throw new ErroNegocio('Escolha pelo menos um campo para alterar.', 'CLASSIFICACAO_SEM_CAMPOS', 422);
        }
        if (array_key_exists('descricao', $campos)) {
            $campos['descricao'] = $campos['descricao'] === null ? null : mb_substr(trim((string) $campos['descricao']), 0, 1000);
        }

        return DB::transaction(function () use ($empresa, $ids, $filtros, $campos) {
            $q = LancamentoContabil::query();
            $ids !== null ? $q->whereKey($ids) : self::filtrar($q, $filtros ?? []);
            $alvo = $q->limit(self::MAXIMO + 1)->pluck('id')->all();
            if (! $alvo) {
                throw new ErroNegocio('Nenhuma linha corresponde à selecção.', 'CLASSIFICACAO_SEM_LINHAS', 422);
            }
            if (count($alvo) > self::MAXIMO) {
                throw new ErroNegocio('A selecção tem mais de '.self::MAXIMO.' linhas: restrinja os filtros.', 'CLASSIFICACAO_DEMASIADAS_LINHAS', 422);
            }
            // original ⇄ estorno: a classificação do par segue a da linha (só nos campos de classificação)
            $pares = LancamentoContabil::query()->whereKey($alvo)->get(['estorno_de_id', 'estornado_por_id'])
                ->flatMap(fn ($l) => [$l->estorno_de_id, $l->estornado_por_id])->filter()->unique()->diff($alvo)->values()->all();
            $todas = array_merge($alvo, $pares);
            $linhas = LancamentoContabil::query()->whereKey($todas)->lockForUpdate()->orderBy('id')->get();

            $anos = $linhas->map(fn ($l) => (int) $l->data_documento?->format('Y'))->filter()->unique()
                ->filter(fn ($a) => $this->exercicios->encerrado($empresa, $a))->sort()->values()->all();
            if ($anos) {
                throw new ErroNegocio('Há linhas de exercícios encerrados ('.implode(', ', $anos).'): restrinja a selecção ao exercício aberto.',
                    'EXERCICIO_ENCERRADO', 422, ['anos' => $anos]);
            }
            if (array_key_exists('terceiro_id', $campos)) {
                $compensadas = $linhas->filter(fn ($l) => in_array($l->id, $alvo, true) && (int) $l->terceiro_id !== (int) $campos['terceiro_id']
                    && $l->reconciliacao_codigo !== null && $l->reconciliacao_codigo !== '');
                if ($compensadas->isNotEmpty()) {
                    throw new ErroNegocio('Há linhas compensadas ou reconciliadas: reverta primeiro a compensação para mudar o terceiro.',
                        'LINHA_COMPENSADA', 422, ['linhas' => $compensadas->pluck('id')->values()->all()]);
                }
            }

            $classificacao = array_intersect_key($campos, self::CAMPOS_CLASSIFICACAO);
            $anteriores = $novos = [];
            $actualizadas = $propagadas = 0;
            foreach ($linhas as $l) {
                $doAlvo = in_array($l->id, $alvo, true);
                $mudar = $doAlvo ? $campos : $classificacao;   // o par só recebe a classificação, nunca a descrição/terceiro
                $diff = array_filter($mudar, fn ($v, $k) => $l->getAttribute($k) != $v, ARRAY_FILTER_USE_BOTH);
                if (! $diff) {
                    continue;
                }
                $anteriores[$l->id] = array_intersect_key($l->only(array_keys($diff)), $diff);
                $novos[$l->id] = $diff;
                // sem eventos de modelo: a auditoria fica num único registo (lotes até 5 000 linhas)
                LancamentoContabil::query()->whereKey($l->id)->update($diff + ['atualizado_em' => now()]);
                $doAlvo ? $actualizadas++ : $propagadas++;
            }
            if ($novos) {
                $this->auditoria->registar('Contabilidade', count($alvo) === 1 ? 'Editou a classificação do lançamento' : 'Classificou lançamentos em massa',
                    "{$actualizadas} linha(s) alterada(s)".($propagadas ? " e {$propagadas} linha(s) do estorno/original" : '').': '.implode(', ', array_keys($campos)),
                    'lancamentos_contabeis', null, $anteriores, $novos);
            }

            return ['actualizadas' => $actualizadas, 'propagadas' => $propagadas];
        });
    }
}
