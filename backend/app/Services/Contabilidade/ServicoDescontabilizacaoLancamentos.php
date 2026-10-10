<?php

namespace App\Services\Contabilidade;

use App\Exceptions\ErroNegocio;
use App\Models\LancamentoContabil;
use App\Services\Sistema\ServicoAuditoria;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 8.1.1 — «Descontabilizar Selecionados» (legado: unpostSelectedLines, js/ui_lancamentos.js:2703-2878).
 *
 * O legado apagava as linhas (para a «lixeira») de todos os documentos seleccionados/filtrados e tentava repor o estado
 * das origens; parava tudo se algum documento pertencesse a Vendas, Compras ou Salários. Aqui (ADR-016, nada se apaga):
 *   - as linhas seleccionadas (ou TODAS as filtradas) agrupam-se nos seus lançamentos (diário + n.º do lançamento);
 *   - cada lançamento da própria Contabilidade (manual, importado, transferido) é ESTORNADO (lançamento simétrico ligado
 *     ao original) com o motivo indicado;
 *   - um lançamento gerado por um módulo (ServicoOrigemLancamentos) BLOQUEIA a operação inteira, com a indicação do
 *     módulo onde se descontabiliza (é esse módulo que repõe o documento); idem exercício encerrado, reconciliação
 *     bancária ou activos imobilizados;
 *   - estornos e lançamentos já estornados são ignorados;
 *   - tudo numa só transacção (ou todos, ou nenhum), com auditoria; `simular` só analisa (pré-visualização).
 */
final class ServicoDescontabilizacaoLancamentos
{
    /** Máximo de lançamentos (documentos) numa operação. */
    public const MAXIMO_LANCAMENTOS = 200;

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoLancamentos $lancamentos,
        private readonly ServicoOrigemLancamentos $origens,
        private readonly ServicoExercicios $exercicios,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    /**
     * @param  list<int>|null  $ids  linhas seleccionadas (ou null para usar os filtros)
     * @param  array<string, mixed>|null  $filtros
     * @return array{lancamentos: list<array<string, mixed>>, a_estornar: int, bloqueados: int, ignorados: int, linhas: int}
     */
    public function analisar(?array $ids, ?array $filtros): array
    {
        $empresa = $this->contexto->obrigatorio();
        $q = LancamentoContabil::query();
        $ids !== null ? $q->whereKey($ids) : ServicoClassificacaoLancamentos::filtrar($q, $filtros ?? []);
        $linhas = $q->limit(ServicoClassificacaoLancamentos::MAXIMO + 1)->get(['id', 'empresa_id', 'diario_id', 'numero_lan', 'referencia', 'numero_documento']);
        if ($linhas->isEmpty()) {
            throw new ErroNegocio('Nenhum lançamento seleccionado ou filtrado.', 'DESCONTABILIZACAO_SEM_LINHAS', 422);
        }
        if ($linhas->count() > ServicoClassificacaoLancamentos::MAXIMO) {
            throw new ErroNegocio('A selecção tem mais de '.ServicoClassificacaoLancamentos::MAXIMO.' linhas: restrinja os filtros.', 'DESCONTABILIZACAO_DEMASIADAS_LINHAS', 422);
        }
        $grupos = $linhas->groupBy(fn (LancamentoContabil $l) => $l->diario_id.'|'.$l->chave());
        if ($grupos->count() > self::MAXIMO_LANCAMENTOS) {
            throw new ErroNegocio('A selecção abrange '.$grupos->count().' lançamentos (máximo '.self::MAXIMO_LANCAMENTOS.' por operação): restrinja os filtros.',
                'DESCONTABILIZACAO_DEMASIADOS_LANCAMENTOS', 422);
        }

        $resultado = [];
        foreach ($grupos as $grupo) {
            /** @var Collection<int, LancamentoContabil> $doc */
            $doc = LancamentoContabil::query()->doMesmoLancamento($grupo->first())->orderBy('id')->get();
            $l0 = $doc->first();
            $item = ['linha_id' => (int) $l0->id, 'numero_lan' => (string) $l0->chave(), 'numero_documento' => $l0->numero_documento,
                'data_documento' => $l0->data_documento?->toDateString(), 'linhas' => $doc->count(),
                'valor' => $doc->where('tipo_dc', 'D')->reduce(fn ($a, $l) => bcadd($a, (string) $l->valor, 2), '0.00'),
                'estado' => 'ESTORNAR', 'motivo' => null, 'origem' => null];
            $ano = (int) $l0->data_documento?->format('Y');
            if ($doc->contains(fn ($l) => $l->eEstorno())) {
                $item = ['estado' => 'IGNORADO', 'motivo' => 'É um estorno (não se descontabiliza).'] + $item;
            } elseif ($doc->contains(fn ($l) => $l->estaEstornado())) {
                $item = ['estado' => 'IGNORADO', 'motivo' => 'Já foi estornado (descontabilizado).'] + $item;
            } elseif ($ano && $this->exercicios->encerrado($empresa, $ano)) {
                $item = ['estado' => 'BLOQUEADO', 'motivo' => "O exercício de {$ano} está encerrado."] + $item;
            } elseif ($origem = $this->origens->origem($doc)) {
                $item = ['estado' => 'BLOQUEADO', 'motivo' => "Pertence ao módulo {$origem['nome']}. {$origem['instrucoes']}", 'origem' => $origem] + $item;
            } elseif ($b = $this->lancamentos->bloqueioEstorno($doc)) {
                $item = ['estado' => 'BLOQUEADO', 'motivo' => $b['mensagem']] + $item;
            }
            $resultado[] = $item;
        }
        $conta = fn (string $e) => count(array_filter($resultado, fn ($r) => $r['estado'] === $e));

        return ['lancamentos' => $resultado, 'a_estornar' => $conta('ESTORNAR'), 'bloqueados' => $conta('BLOQUEADO'), 'ignorados' => $conta('IGNORADO'), 'linhas' => $linhas->count()];
    }

    /**
     * @param  list<int>|null  $ids
     * @param  array<string, mixed>|null  $filtros
     * @return array{estornados: list<array{numero_lan: string, estorno: string}>, ignorados: int}
     */
    public function executar(?array $ids, ?array $filtros, string $motivo): array
    {
        return DB::transaction(function () use ($ids, $filtros, $motivo) {
            $analise = $this->analisar($ids, $filtros);
            if ($analise['bloqueados'] > 0) {
                $bloqueados = array_values(array_filter($analise['lancamentos'], fn ($r) => $r['estado'] === 'BLOQUEADO'));
                throw new ErroNegocio('Há lançamentos que não podem ser descontabilizados aqui: nada foi alterado. '.$bloqueados[0]['numero_lan'].': '.$bloqueados[0]['motivo'],
                    'DESCONTABILIZACAO_BLOQUEADA', 422, ['bloqueados' => $bloqueados]);
            }
            if ($analise['a_estornar'] === 0) {
                throw new ErroNegocio('Os lançamentos seleccionados já estão descontabilizados (estornados) ou são estornos.', 'DESCONTABILIZACAO_NADA_A_FAZER', 422);
            }
            $feitos = [];
            foreach ($analise['lancamentos'] as $r) {
                if ($r['estado'] !== 'ESTORNAR') {
                    continue;
                }
                $estorno = $this->lancamentos->estornar(LancamentoContabil::query()->findOrFail($r['linha_id']), mb_substr("Descontabilização: {$motivo}", 0, 500));
                $feitos[] = ['numero_lan' => $r['numero_lan'], 'estorno' => (string) $estorno->first()->numero_lan];
            }
            $this->auditoria->registar('Contabilidade', 'Descontabilização em massa',
                'Estornados '.count($feitos).' lançamento(s): '.implode(', ', array_column($feitos, 'numero_lan')).'. Motivo: '.$motivo, 'lancamentos_contabeis');

            return ['estornados' => $feitos, 'ignorados' => $analise['ignorados']];
        });
    }
}
