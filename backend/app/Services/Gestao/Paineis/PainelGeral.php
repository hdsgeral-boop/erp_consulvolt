<?php

namespace App\Services\Gestao\Paineis;

use App\Services\Logistica\ServicoArmazens;

/**
 * Visão geral (CONSTRUTORES.geral, ui_painel_modulos.js:132-165): vendas e compras do mês, resultado do ano, disponibilidades
 * (43 e 45), clientes (31) e fornecedores (32) até ao fim do mês, valor do inventário e colaboradores activos; gráficos de
 * vendas × compras e de proveitos × custos em 12 meses.
 *
 * Correcções face ao legado:
 *   - cada cartão e cada série só aparece se o utilizador tiver acesso ao painel do módulo de origem (o legado mostrava os
 *     números de todos os módulos a quem tivesse o Dashboard);
 *   - resultado sem os lançamentos de apuramento; compras sem as facturas anuladas; base sem IVA por omissão (ConsultasPaineis);
 *   - valor do inventário pela valorização do módulo de Logística (custo médio real por armazém, ADR-042).
 */
final class PainelGeral implements Painel
{
    public function __construct(private readonly ConsultasPaineis $consultas, private readonly ServicoArmazens $armazens) {}

    public function filtraDimensoes(): bool
    {
        return true;
    }

    public function construir(PeriodoPainel $p, array $f): array
    {
        $ve = fn (string $m) => in_array($m, $f['modulos_visiveis'] ?? [], true);
        $kpis = [];
        $series = ['vc' => [], 'pc' => []];
        $vendas = $ve('vendas') ? $this->consultas->vendasPorMes($p->inicioJanela, $p->fimMes, $f) : [];
        $compras = $ve('compras') ? $this->consultas->comprasPorMes($p->inicioJanela, $p->fimMes, $f) : [];
        if ($ve('vendas')) {
            $kpis[] = Indicadores::kpi('vendas_mes', 'Vendas do Mês', $vendas[$p->chaveMes]['total'] ?? '0.00', 'kz', null, 'vendas');
            $series['vc'][] = Indicadores::serie('vendas', 'Vendas', $p->serie(array_map(fn ($x) => $x['total'], $vendas)));
        }
        if ($ve('compras')) {
            $kpis[] = Indicadores::kpi('compras_mes', 'Compras do Mês', $compras[$p->chaveMes] ?? '0.00', 'kz', null, 'compras');
            $series['vc'][] = Indicadores::serie('compras', 'Compras', $p->serie($compras));
        }
        if ($ve('contabilidade')) {
            $pc = $this->consultas->resultadoPorMes($p->inicioJanela, $p->fimMes, $f);
            $resultado = bcsub($p->somaAno($pc['proveitos']), $p->somaAno($pc['custos']), 2);
            $kpis[] = Indicadores::kpi('resultado_ano', 'Resultado do Ano', $resultado, 'kz', bccomp($resultado, '0', 2) >= 0 ? 'Lucro acumulado' : 'Prejuízo acumulado', 'contabilidade');
            $series['pc'] = [Indicadores::serie('proveitos', 'Proveitos (classe 6)', $p->serie($pc['proveitos'])), Indicadores::serie('custos', 'Custos (classe 7)', $p->serie($pc['custos']))];
        }
        $grupos = array_filter(['disp' => $ve('tesouraria') ? ['43', '45'] : null, 'cli' => $ve('vendas') ? ['31'] : null, 'forn' => $ve('compras') ? ['32'] : null]);
        $saldos = $grupos ? $this->consultas->saldos($p->fimMes, $grupos, $f) : [];
        if (isset($saldos['disp'])) {
            $kpis[] = Indicadores::kpi('disponibilidades', 'Disponibilidades', $saldos['disp'], 'kz', 'Bancos (43) e Caixa (45)', 'tesouraria');
        }
        if (isset($saldos['cli'])) {
            $kpis[] = Indicadores::kpi('clientes_receber', 'Clientes a Receber', $saldos['cli'], 'kz', 'Saldo da conta 31', 'vendas');
        }
        if (isset($saldos['forn'])) {
            $kpis[] = Indicadores::kpi('fornecedores_pagar', 'Fornecedores a Pagar', Indicadores::negar($saldos['forn']), 'kz', 'Saldo da conta 32', 'compras');
        }
        if ($ve('armazem')) {
            $kpis[] = Indicadores::kpi('valor_inventario', 'Valor do Inventário', $this->armazens->valorizacao()['total'], 'kz', 'Stock × custo médio', 'armazem');
        }
        if ($ve('rh')) {
            $kpis[] = Indicadores::kpi('colaboradores_activos', 'Colaboradores Activos', $this->consultas->colaboradoresActivos($f), 'num', null, 'rh');
        }
        $graficos = [];
        if ($series['vc']) {
            $graficos[] = Indicadores::grafico('vendas_compras', 'Vendas vs Compras (12 meses)', 'barras', $p->rotulos(), $series['vc']);
        }
        if ($series['pc']) {
            $graficos[] = Indicadores::grafico('proveitos_custos', 'Proveitos vs Custos (12 meses)', 'linhas', $p->rotulos(), $series['pc']);
        }

        return ['kpis' => $kpis, 'graficos' => $graficos, 'tabelas' => [], 'atalhos' => []];
    }
}
