<?php

namespace App\Services\Gestao\Paineis;

use App\Services\Logistica\ServicoArmazens;
use Illuminate\Support\Facades\DB;

/**
 * Armazém (CONSTRUTORES.armazem, ui_painel_modulos.js:316-348): valor do inventário, artigos de stock, stock baixo, sem stock,
 * recepções por validar, guias de saída do mês; entradas × saídas em valor (12 meses), top 10 artigos por valor e artigos com
 * stock baixo.
 *
 * Fonte: o mapa de stock do módulo de Logística (ServicoArmazens::stock — stock por armazém × custo médio ponderado real).
 * Correcções:
 *   - "stock baixo" pelo stock mínimo do produto (regra do módulo, ADR-042); o legado usava ≤ 5 fixo;
 *   - entradas e saídas pelo sentido do movimento (E/S): o legado classificava pelo texto do tipo e contava os acertos
 *     negativos como entradas; valor do movimento quando gravado, senão quantidade × preço unitário;
 *   - recepções e guias anuladas não contam.
 */
final class PainelArmazem implements Painel
{
    public function __construct(private readonly ConsultasPaineis $consultas, private readonly ServicoArmazens $armazens) {}

    public function filtraDimensoes(): bool
    {
        return false;
    }

    public function construir(PeriodoPainel $p, array $f): array
    {
        $empresa = $this->consultas->empresa();
        $porProduto = [];
        foreach ($this->armazens->stock() as $l) {
            $x = $porProduto[$l['produto_id']] ??= ['produto_id' => $l['produto_id'], 'codigo' => $l['codigo'], 'nome' => $l['nome'], 'quantidade' => '0', 'valor' => '0.00',
                'custo_medio' => $l['custo_medio'], 'stock_minimo' => $l['stock_minimo']];
            $porProduto[$l['produto_id']] = ['quantidade' => bcadd($x['quantidade'], $l['quantidade'], 3), 'valor' => bcadd($x['valor'], $l['valor'], 2)] + $x;
        }
        $artigos = DB::table('produtos')->where('empresa_id', $empresa)->whereNull('eliminado_em')->where('movimenta_stock', true)->pluck('id')->all();
        $linhas = array_values(array_intersect_key($porProduto, array_flip($artigos)));
        $semFicha = count(array_diff($artigos, array_keys($porProduto)));
        $baixo = array_values(array_filter($linhas, fn ($l) => (float) $l['quantidade'] > 0 && $l['stock_minimo'] !== null && (float) $l['quantidade'] <= (float) $l['stock_minimo']));
        usort($baixo, fn ($a, $b) => (float) $a['quantidade'] <=> (float) $b['quantidade']);
        $semStock = count(array_filter($linhas, fn ($l) => (float) $l['quantidade'] <= 0)) + $semFicha;
        $top = $linhas;
        usort($top, fn ($a, $b) => (float) $b['valor'] <=> (float) $a['valor']);
        $top = array_slice($top, 0, 10);

        $mov = DB::select("SELECT to_char(m.data, 'YYYY-MM') AS mes,
                SUM(CASE WHEN m.sentido = 'E' THEN ABS(COALESCE(m.valor, m.quantidade * m.preco_unitario, 0)) ELSE 0 END) AS entradas,
                SUM(CASE WHEN m.sentido = 'S' THEN ABS(COALESCE(m.valor, m.quantidade * m.preco_unitario, 0)) ELSE 0 END) AS saidas
            FROM movimentos_inventario m WHERE m.empresa_id = ? AND m.data >= ? AND m.data < ? GROUP BY 1", [$empresa, $p->inicioJanela, ConsultasPaineis::diaSeguinte($p->fimMes)]);
        $entradas = array_column(array_map(fn ($r) => [$r->mes, $r->entradas], $mov), 1, 0);
        $saidas = array_column(array_map(fn ($r) => [$r->mes, $r->saidas], $mov), 1, 0);
        $rececoes = (int) DB::selectOne('SELECT COUNT(*) AS n FROM rececoes_compra WHERE empresa_id = ? AND anulado_em IS NULL AND NOT COALESCE(validado, false)', [$empresa])->n;
        $guias = (int) DB::selectOne('SELECT COUNT(*) AS n FROM guias_saida WHERE empresa_id = ? AND anulado_em IS NULL AND data BETWEEN ? AND ?', [$empresa, $p->inicioMes, $p->fimMes])->n;

        return [
            'kpis' => [
                Indicadores::kpi('valor_inventario', 'Valor do Inventário', $this->armazens->valorizacao()['total'], 'kz', 'Stock × custo médio ponderado'),
                Indicadores::kpi('artigos_stock', 'Artigos de Stock', count($artigos), 'num'),
                Indicadores::kpi('stock_baixo', 'Stock Baixo', count($baixo), 'num', 'Igual ou abaixo do stock mínimo'),
                Indicadores::kpi('sem_stock', 'Sem Stock', $semStock, 'num'),
                Indicadores::kpi('rececoes_por_validar', 'Recepções por Validar', $rececoes, 'num', 'Guias de fornecedor pendentes'),
                Indicadores::kpi('guias_saida_mes', 'Guias de Saída (Mês)', $guias, 'num'),
            ],
            'graficos' => [
                Indicadores::grafico('entradas_saidas', 'Entradas vs Saídas de Stock (valor, 12 meses)', 'barras', $p->rotulos(),
                    [Indicadores::serie('entradas', 'Entradas', $p->serie($entradas)), Indicadores::serie('saidas', 'Saídas', $p->serie($saidas))]),
                Indicadores::grafico('top_artigos', 'Top 10 Artigos por Valor em Stock', 'barras', array_map(fn ($l) => $l['nome'], $top),
                    [Indicadores::serie('valor', 'Valor', array_map(fn ($l) => $l['valor'], $top))], true, ['horizontal' => true]),
            ],
            'tabelas' => [
                Indicadores::tabela('stock_baixo', 'Artigos com Stock Baixo', [['nome', 'Artigo'], ['quantidade', 'Quantidade', 'num'], ['stock_minimo', 'Stock mínimo', 'num'],
                    ['custo_medio', 'Custo Médio', 'kz'], ['valor', 'Valor', 'kz']], array_slice($baixo, 0, 10)),
            ],
            'atalhos' => [['rotulo' => 'Níveis de Stock', 'vista' => 'armazem_stock'], ['rotulo' => 'Validar Entradas', 'vista' => 'armazem_rececoes'], ['rotulo' => 'Guias de Saída', 'vista' => 'armazem_guias']],
        ];
    }
}
