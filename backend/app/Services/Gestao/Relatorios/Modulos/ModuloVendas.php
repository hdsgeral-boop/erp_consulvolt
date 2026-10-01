<?php

namespace App\Services\Gestao\Relatorios\Modulos;

use App\Services\Gestao\Relatorios\PeriodosGestao;
use Illuminate\Support\Facades\DB;

/**
 * Vendas (relatorios_gestao.js:177-235): facturação líquida, clientes, produtos e margem estimada.
 * Regras do legado: entram as FT e FR (sinal +) e as NC (sinal −) não anuladas emitidas no período, incluindo as do POS e
 * da lavandaria; valores sem IVA (total_liquido); recebimentos = recibos válidos do período + facturas-recibo; encomendas
 * (NE) e propostas (PF + OR) só como indicadores de carteira.
 * Correcções:
 *   - a margem usa o custo gravado na linha no momento da saída de stock (custo_unitario_kz × quantidade_stock, ADR-043),
 *     e só na falta dele o custo médio actual do produto que movimenta stock (o legado usava sempre o custo médio actual, que
 *     muda com as compras posteriores e alterava a margem de períodos já fechados);
 *   - o «valor líquido» por produto é o total da linha sem IVA (total_linha); o legado multiplicava quantidade × preço, que
 *     nas linhas do POS e da lavandaria é o preço com IVA.
 */
final class ModuloVendas extends ModuloGestao
{
    public function id(): string
    {
        return 'vendas';
    }

    public function nome(): string
    {
        return 'Vendas';
    }

    public function descricao(): string
    {
        return 'Facturação líquida, clientes, produtos e margem estimada.';
    }

    public function calcular(array $p): array
    {
        $e = $this->empresa();
        $sinal = "(CASE WHEN v.tipo_documento IN ('FT', 'FR') THEN 1 WHEN v.tipo_documento = 'NC' THEN -1 ELSE 0 END)";
        $base = fn () => DB::table('vendas as v')->where('v.empresa_id', $e)->whereRaw(self::sqlValido('v.estado'))
            ->whereRaw('v.data_emissao::date BETWEEN ? AND ?', [$p['inicio'], $p['fim']]);
        $docs = fn () => $base()->whereIn('v.tipo_documento', ['FT', 'FR', 'NC']);
        $t = $docs()->selectRaw("COALESCE(SUM({$sinal} * v.total_liquido), 0) AS liq, COALESCE(SUM({$sinal} * v.total_bruto), 0) AS bruto,
            COALESCE(SUM(CASE WHEN v.tipo_documento = 'NC' THEN v.total_liquido ELSE 0 END), 0) AS nc,
            COUNT(*) FILTER (WHERE v.tipo_documento IN ('FT', 'FR')) AS n_fact,
            COALESCE(SUM(CASE WHEN v.tipo_documento IN ('FT', 'FR') THEN v.total_liquido ELSE 0 END), 0) AS liq_fact,
            COUNT(DISTINCT v.cliente_id) FILTER (WHERE v.tipo_documento IN ('FT', 'FR')) AS clientes,
            COALESCE(SUM(CASE WHEN v.tipo_documento = 'FR' THEN v.total_bruto ELSE 0 END), 0) AS fr_bruto")->first();
        $liq = self::dinheiro($t->liq);
        $valorNC = self::dinheiro($t->nc);
        $nFact = (int) $t->n_fact;
        $custo = $docs()->join('itens_venda as i', 'i.venda_id', '=', 'v.id')->leftJoin('produtos as pr', 'pr.id', '=', 'i.produto_id')
            ->selectRaw("COALESCE(SUM({$sinal} * CASE WHEN i.custo_unitario_kz IS NOT NULL THEN COALESCE(i.quantidade_stock, i.quantidade) * i.custo_unitario_kz
                WHEN pr.movimenta_stock THEN i.quantidade * COALESCE(pr.custo_medio, 0) ELSE 0 END), 0) AS c")->value('c');
        $custo = self::dinheiro($custo);
        $porProduto = $docs()->join('itens_venda as i', 'i.venda_id', '=', 'v.id')->leftJoin('produtos as pr', 'pr.id', '=', 'i.produto_id')
            ->groupBy(DB::raw("COALESCE(i.produto_id::text, COALESCE(i.descricao, 'Sem produto'))"))
            ->selectRaw("COALESCE(i.produto_id::text, COALESCE(i.descricao, 'Sem produto')) AS k, COALESCE(MAX(pr.nome), MAX(i.descricao), 'Sem produto') AS nome,
                SUM({$sinal} * i.quantidade) AS qtd, SUM({$sinal} * COALESCE(i.total_linha, i.quantidade * i.preco_unitario)) AS valor")
            ->orderByDesc('valor')->limit(10)->get()
            ->map(fn ($r) => ['nome' => $r->nome, 'qtd' => round((float) $r->qtd, 3), 'valor' => self::dinheiro($r->valor)])->all();
        $porCliente = $docs()->leftJoin('terceiros as c', 'c.id', '=', 'v.cliente_id')->groupBy('v.cliente_id')
            ->selectRaw("v.cliente_id, COALESCE(MAX(c.nome), 'Consumidor final') AS nome, COUNT(*) FILTER (WHERE v.tipo_documento IN ('FT', 'FR')) AS docs,
                SUM({$sinal} * v.total_liquido) AS valor")
            ->orderByDesc('valor')->get()->map(fn ($r) => ['nome' => $r->nome, 'docs' => (int) $r->docs, 'valor' => self::dinheiro($r->valor)]);
        $top5 = $porCliente->take(5)->reduce(fn ($s, $c) => bcadd($s, $c['valor'], 2), '0.00');
        $recibos = DB::table('recibos_venda')->where('empresa_id', $e)->whereRaw(self::sqlValido('estado'))->whereBetween('data', [$p['inicio'], $p['fim']])->sum('montante_total');
        $recebimentos = bcadd(self::dinheiro($recibos), self::dinheiro($t->fr_bruto), 2);
        $outros = $base()->selectRaw("COALESCE(SUM(CASE WHEN v.tipo_documento = 'NE' THEN v.total_liquido ELSE 0 END), 0) AS enc,
            COUNT(*) FILTER (WHERE v.tipo_documento IN ('PF', 'OR')) AS prop")->first();
        $mensal = $docs()->groupBy(DB::raw("to_char(v.data_emissao, 'YYYY-MM')"))->selectRaw("to_char(v.data_emissao, 'YYYY-MM') AS m, SUM({$sinal} * v.total_liquido) AS t")->pluck('t', 'm');
        $meses = PeriodosGestao::meses($p);
        $margem = bcsub($liq, $custo, 2);

        return [
            'kpis' => [
                self::k('liq', 'Facturação líquida (s/ IVA)', $liq, 'kz', 'sobe', 'Facturas e facturas-recibo − notas de crédito, sem IVA; anulados excluídos.'),
                self::k('bruto', 'Facturação com IVA', $t->bruto, 'kz', 'sobe', 'Mesmo universo, com IVA.'),
                self::k('n_fact', 'Nº de facturas', $nFact, 'num', 'sobe', 'Facturas e facturas-recibo válidas.'),
                self::k('ticket', 'Valor médio por factura', $nFact ? bcdiv(self::dinheiro($t->liq_fact), (string) $nFact, 2) : null, 'kz', 'sobe', 'Facturação sem IVA (sem notas de crédito) ÷ nº de facturas.'),
                self::k('clientes', 'Clientes facturados', (int) $t->clientes, 'num', 'sobe', 'Clientes distintos com factura no período.'),
                self::k('nc_pct', 'Notas de crédito / facturação', self::pct($valorNC, bcadd($liq, $valorNC, 2)), 'pct', 'desce', 'Valor das notas de crédito ÷ facturação antes de notas de crédito.'),
                self::k('margem', 'Margem bruta estimada', $margem, 'kz', 'sobe', 'Facturação líquida − custo dos artigos de stock vendidos (custo gravado na saída; na falta, custo médio).'),
                self::k('margem_pct', 'Margem bruta estimada %', self::pct($margem, $liq), 'pct', 'sobe', 'Margem ÷ facturação líquida.'),
                self::k('concentracao', 'Concentração top 5 clientes', self::pct($top5, $liq), 'pct', 'desce', 'Peso dos 5 maiores clientes na facturação (risco de dependência).'),
                self::k('recebimentos', 'Recebimentos', $recebimentos, 'kz', 'sobe', 'Recibos + facturas-recibo emitidas no período.'),
                self::k('encomendas', 'Encomendas de clientes', $outros->enc, 'kz', 'sobe', 'Carteira de encomendas registadas no período (sem IVA).'),
                self::k('proformas', 'Propostas / proformas emitidas', (int) $outros->prop, 'num', 'sobe', 'Nº de proformas e orçamentos.'),
            ],
            'tabelas' => [
                self::tabela('top_clientes', 'Top 10 clientes', 'nome', [['nome', 'Cliente'], ['docs', 'Facturas', 'num'], ['valor', 'Facturação líquida', 'kz']], $porCliente->take(10)->all()),
                self::tabela('top_produtos', 'Top 10 produtos / serviços', 'nome', [['nome', 'Produto / serviço'], ['qtd', 'Quantidade', 'num'], ['valor', 'Valor líquido', 'kz']], $porProduto),
            ],
            'graficos' => [['id' => 'vendas_mensal', 'titulo' => 'Facturação líquida por mês', 'tipo' => 'bar', 'rotulos' => array_column($meses, 'rotulo'),
                'series' => [['rotulo' => 'Facturação', 'valores' => array_map(fn ($m) => self::dinheiro($mensal[$m['chave']] ?? 0), $meses)]]]],
            'notas' => [],
        ];
    }
}
