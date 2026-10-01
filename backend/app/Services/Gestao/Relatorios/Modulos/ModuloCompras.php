<?php

namespace App\Services\Gestao\Relatorios\Modulos;

use App\Services\Gestao\Relatorios\PeriodosGestao;
use Illuminate\Support\Facades\DB;

/**
 * Compras (relatorios_gestao.js:237-275): volume de compras, fornecedores e processo de aprovisionamento.
 * Regras do legado: facturas de fornecedor válidas com data no período (sem IVA = total − imposto); pedidos, cotações e
 * encomendas válidos do período; valor das encomendas = Σ quantidade × preço dos artigos da encomenda; encomendas
 * «totalmente recebidas» = estado RECEBIDO.
 */
final class ModuloCompras extends ModuloGestao
{
    public function id(): string
    {
        return 'compras';
    }

    public function nome(): string
    {
        return 'Compras';
    }

    public function descricao(): string
    {
        return 'Volume de compras, fornecedores, processo de aprovisionamento.';
    }

    public function calcular(array $p): array
    {
        $e = $this->empresa();
        $faturas = fn () => DB::table('faturas_compra as f')->where('f.empresa_id', $e)->whereRaw(self::sqlValido('f.estado'))->whereBetween('f.data', [$p['inicio'], $p['fim']]);
        $t = $faturas()->selectRaw('COALESCE(SUM(f.montante_total - COALESCE(f.total_imposto, 0)), 0) AS liq, COALESCE(SUM(f.montante_total), 0) AS iva, COUNT(*) AS n,
            COUNT(DISTINCT COALESCE(f.fornecedor_id, 0)) AS forn')->first();
        $porForn = $faturas()->leftJoin('terceiros as t', 't.id', '=', 'f.fornecedor_id')->groupBy('f.fornecedor_id')
            ->selectRaw("COALESCE(MAX(t.nome), 'Sem fornecedor') AS nome, COUNT(*) AS docs, SUM(f.montante_total - COALESCE(f.total_imposto, 0)) AS valor")
            ->orderByDesc('valor')->get()->map(fn ($r) => ['nome' => $r->nome, 'docs' => (int) $r->docs, 'valor' => self::dinheiro($r->valor)]);
        $top5 = $porForn->take(5)->reduce(fn ($s, $x) => bcadd($s, $x['valor'], 2), '0.00');
        $liq = self::dinheiro($t->liq);
        $n = (int) $t->n;
        $pedidos = DB::table('pedidos_compra')->where('empresa_id', $e)->whereRaw(self::sqlValido('estado'))->whereRaw('data::date BETWEEN ? AND ?', [$p['inicio'], $p['fim']])->count();
        $cot = DB::table('cotacoes_compra')->where('empresa_id', $e)->whereRaw(self::sqlValido('estado'))->whereBetween('data', [$p['inicio'], $p['fim']])
            ->selectRaw("COUNT(*) AS n, COUNT(*) FILTER (WHERE estado ~* 'ADJUDICADO') AS adj")->first();
        $encs = DB::table('encomendas_compra as o')->where('o.empresa_id', $e)->whereRaw(self::sqlValido('o.estado'))->whereRaw('o.data::date BETWEEN ? AND ?', [$p['inicio'], $p['fim']]);
        $enc = (clone $encs)->selectRaw("COUNT(*) AS n, COUNT(*) FILTER (WHERE o.estado ~* 'RECEBIDO') AS recebidas")->first();
        $valorEnc = (clone $encs)->join('itens_compra as i', fn ($j) => $j->on('i.encomenda_compra_id', '=', 'o.id')->where('i.tipo_documento_origem', 'ENCOMENDA'))
            ->selectRaw('COALESCE(SUM(i.quantidade * i.preco_unitario), 0) AS v')->value('v');
        $mensal = $faturas()->groupBy(DB::raw("to_char(f.data, 'YYYY-MM')"))->selectRaw("to_char(f.data, 'YYYY-MM') AS m, SUM(f.montante_total - COALESCE(f.total_imposto, 0)) AS t")->pluck('t', 'm');
        $meses = PeriodosGestao::meses($p);

        return [
            'kpis' => [
                self::k('liq', 'Compras facturadas (s/ IVA)', $liq, 'kz', 'neutro', 'Facturas de fornecedor do período, sem IVA.'),
                self::k('iva', 'Compras com IVA', $t->iva, 'kz', 'neutro', 'Total das facturas de fornecedor.'),
                self::k('n_fact', 'Nº de facturas de fornecedor', $n, 'num', 'neutro'),
                self::k('fornecedores', 'Fornecedores activos', $porForn->count(), 'num', 'neutro', 'Fornecedores distintos com factura no período.'),
                self::k('concentracao', 'Concentração top 5 fornecedores', self::pct($top5, $liq), 'pct', 'desce', 'Peso dos 5 maiores fornecedores (risco de dependência).'),
                self::k('media_fact', 'Valor médio por factura', $n ? bcdiv($liq, (string) $n, 2) : null, 'kz', 'neutro'),
                self::k('pedidos', 'Pedidos de compra', $pedidos, 'num', 'neutro', 'Requisições / pedidos criados no período.'),
                self::k('cotacoes', 'Cotações recebidas', (int) $cot->n, 'num', 'sobe', 'Mais cotações por pedido = melhor concorrência.'),
                self::k('cot_por_pedido', 'Cotações por pedido', self::div($cot->n, $pedidos), 'num', 'sobe', 'Boa prática: pelo menos 3 cotações por compra relevante.', ['casas' => 1]),
                self::k('adjudicacao', 'Taxa de adjudicação', self::pct($cot->adj, $cot->n), 'pct', 'neutro', 'Cotações adjudicadas ÷ cotações.'),
                self::k('encomendas', 'Encomendas emitidas', $valorEnc, 'kz', 'neutro', 'Valor das encomendas a fornecedores (sem IVA).'),
                self::k('recebidas_pct', 'Encomendas totalmente recebidas', self::pct($enc->recebidas, $enc->n), 'pct', 'sobe', 'Encomendas do período com estado RECEBIDO.'),
            ],
            'tabelas' => [self::tabela('top_fornecedores', 'Top 10 fornecedores', 'nome', [['nome', 'Fornecedor'], ['docs', 'Facturas', 'num'], ['valor', 'Compras s/ IVA', 'kz']], $porForn->take(10)->all())],
            'graficos' => [['id' => 'compras_mensal', 'titulo' => 'Compras por mês (s/ IVA)', 'tipo' => 'bar', 'rotulos' => array_column($meses, 'rotulo'),
                'series' => [['rotulo' => 'Compras', 'valores' => array_map(fn ($m) => self::dinheiro($mensal[$m['chave']] ?? 0), $meses)]]]],
            'notas' => [],
        ];
    }
}
