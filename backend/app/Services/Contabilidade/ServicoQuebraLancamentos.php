<?php

namespace App\Services\Contabilidade;

use App\Exceptions\ErroNegocio;
use App\Models\LancamentoContabil;
use Illuminate\Support\Facades\DB;

/**
 * Contabilidade › Lançamentos — «Quebra por Terceiro» e «Quebra por Unidade de Negócio» (e por Centro de Custo):
 * as linhas filtradas na lista (os mesmos filtros de GET /contabilidade/lancamentos) agrupadas pela dimensão, com
 * n.º de linhas, Σ débito, Σ crédito e saldo (D − C) por grupo e o total geral.
 *
 * O legado não tinha esta vista na lista (o «Quebrar por Terceiros» só existia no balancete e na evolução,
 * js/ui_reports.js:203-204 e 614-650); o critério é o mesmo: o grupo «sem …» junta as linhas sem a dimensão.
 *
 * - Agregação no PostgreSQL (uma consulta para os grupos da página, uma para o total), com o isolamento por empresa do
 *   modelo (PertenceEmpresa) e os filtros validados no controlador — nunca SQL com texto do pedido.
 * - Paginação pelos GRUPOS (estável: nome/código pela ordenação portuguesa, o grupo «sem …» no fim e o id a desempatar);
 *   o total geral é sempre o de todas as linhas filtradas, não só o da página.
 * - `com_linhas` (impressão/Excel): devolve todos os grupos com as linhas de cada um, até MAXIMO_LINHAS.
 */
final class ServicoQuebraLancamentos
{
    /** Dimensão → coluna da linha, tabela de referência e colunas de rótulo. */
    public const DIMENSOES = [
        'terceiro' => ['coluna' => 'terceiro_id', 'tabela' => 'terceiros', 'codigo' => 'nif', 'nome' => 'nome', 'sem' => 'Sem terceiro'],
        'unidade' => ['coluna' => 'unidade_negocio_id', 'tabela' => 'unidades_negocio', 'codigo' => 'codigo', 'nome' => 'nome', 'sem' => 'Sem unidade de negócio'],
        'centro' => ['coluna' => 'centro_custo_id', 'tabela' => 'centros_custo', 'codigo' => 'codigo', 'nome' => 'descricao', 'sem' => 'Sem centro de custo'],
    ];

    /** Máximo de linhas na impressão/exportação com o detalhe (acima disto: restringir os filtros ou imprimir só os subtotais). */
    public const MAXIMO_LINHAS = 5000;

    /**
     * @param  array<string, mixed>  $f  filtros da lista (já validados)
     * @return array{por: string, grupos: list<array<string, mixed>>, total: array<string, mixed>, paginacao: array<string, int>}
     */
    public function quebra(array $f, string $por, int $pagina = 1, int $porPagina = 50, bool $comLinhas = false): array
    {
        $dim = self::DIMENSOES[$por] ?? throw new ErroNegocio('Quebra desconhecida.', 'QUEBRA_INVALIDA', 422);
        $col = $dim['coluna'];

        // linhas filtradas (com o âmbito da empresa activa do modelo) → agregadas por dimensão
        $base = ServicoClassificacaoLancamentos::filtrar(LancamentoContabil::query(), $f)->toBase()->reorder();
        $agregado = (clone $base)->selectRaw("{$col} AS chave, COUNT(*) AS linhas,
                COALESCE(SUM(CASE WHEN tipo_dc = 'D' THEN valor ELSE 0 END), 0) AS debito,
                COALESCE(SUM(CASE WHEN tipo_dc = 'C' THEN valor ELSE 0 END), 0) AS credito")
            ->groupBy($col);

        $total = DB::query()->fromSub(clone $agregado, 'g')
            ->selectRaw('COUNT(*) AS grupos, COALESCE(SUM(g.linhas), 0) AS linhas, COALESCE(SUM(g.debito), 0) AS debito, COALESCE(SUM(g.credito), 0) AS credito')
            ->first();

        $grupos = DB::query()->fromSub($agregado, 'g')
            ->leftJoin("{$dim['tabela']} as r", 'r.id', '=', 'g.chave')
            ->select(['g.chave', 'g.linhas', 'g.debito', 'g.credito', "r.{$dim['codigo']} as codigo", "r.{$dim['nome']} as nome"])
            ->orderByRaw('g.chave IS NULL')
            ->when($por === 'terceiro', fn ($q) => $q->ordenarPorNome('r.nome'), fn ($q) => $q->orderBy('r.codigo')->ordenarPorNome("r.{$dim['nome']}"))
            ->orderBy('g.chave');

        $nGrupos = (int) $total->grupos;
        if ($comLinhas) {
            if ((int) $total->linhas > self::MAXIMO_LINHAS) {
                throw new ErroNegocio('A filtragem tem '.number_format((int) $total->linhas, 0, ',', '.').' linhas: o detalhe imprime-se até '
                    .number_format(self::MAXIMO_LINHAS, 0, ',', '.').'. Restrinja os filtros ou imprima só os subtotais.', 'QUEBRA_DEMASIADAS_LINHAS', 422);
            }
            $pagina = 1;
            $porPagina = max(1, $nGrupos);
        } else {
            $grupos->forPage(max(1, $pagina), $porPagina);
        }

        $lista = $grupos->get()->map(fn ($g) => $this->grupo($g, $dim))->all();
        if ($comLinhas && $lista) {
            $lista = $this->comLinhas($lista, $f, $col);
        }

        $td = FiltroMapas::dinheiro($total->debito);
        $tc = FiltroMapas::dinheiro($total->credito);

        return [
            'por' => $por,
            'grupos' => $lista,
            'total' => ['grupos' => $nGrupos, 'linhas' => (int) $total->linhas, 'debito' => $td, 'credito' => $tc, 'saldo' => bcsub($td, $tc, 2)],
            'paginacao' => ['pagina_atual' => $comLinhas ? 1 : max(1, $pagina), 'por_pagina' => $porPagina, 'total' => $nGrupos,
                'ultima_pagina' => max(1, (int) ceil($nGrupos / max(1, $porPagina)))],
        ];
    }

    /** @param  array<string, string>  $dim */
    private function grupo(object $g, array $dim): array
    {
        $d = FiltroMapas::dinheiro($g->debito);
        $c = FiltroMapas::dinheiro($g->credito);
        $nome = $g->nome !== null ? trim(preg_replace('/\s+/u', ' ', (string) $g->nome)) : null;

        return [
            'chave' => $g->chave === null ? null : (int) $g->chave,
            'codigo' => $g->codigo !== null ? trim((string) $g->codigo) : null,
            'nome' => $g->chave === null ? $dim['sem'] : ($nome ?: "#{$g->chave}"),
            'linhas' => (int) $g->linhas, 'debito' => $d, 'credito' => $c, 'saldo' => bcsub($d, $c, 2),
        ];
    }

    /**
     * Linhas de cada grupo (impressão/Excel), pela ordem da lista (data fiscal, id).
     *
     * @param  list<array<string, mixed>>  $grupos
     * @return list<array<string, mixed>>
     */
    private function comLinhas(array $grupos, array $f, string $col): array
    {
        $porGrupo = [];
        $linhas = ServicoClassificacaoLancamentos::filtrar(LancamentoContabil::query(), $f)
            ->orderBy('data_documento')->orderBy('id')
            ->get(['id', $col, 'diario_id', 'data_documento', 'numero_lan', 'numero_documento', 'referencia', 'codigo_conta', 'descricao', 'tipo_dc', 'valor']);
        // códigos dos diários numa só consulta (sem N+1)
        $diarios = DB::table('diarios_contabeis')->whereIn('id', $linhas->pluck('diario_id')->filter()->unique()->values())->pluck('codigo', 'id');
        foreach ($linhas as $l) {
            $chave = $l->getAttribute($col);
            $porGrupo[$chave === null ? '' : (string) $chave][] = [
                'id' => $l->id, 'data_documento' => $l->data_documento?->toDateString(), 'diario' => $diarios[$l->diario_id] ?? null,
                'numero_lan' => $l->numero_lan ?: $l->referencia, 'numero_documento' => $l->numero_documento, 'codigo_conta' => $l->codigo_conta,
                'descricao' => $l->descricao, 'tipo_dc' => $l->tipo_dc, 'valor' => FiltroMapas::dinheiro($l->valor),
            ];
        }

        return array_map(fn ($g) => $g + ['detalhe' => $porGrupo[$g['chave'] === null ? '' : (string) $g['chave']] ?? []], $grupos);
    }
}
