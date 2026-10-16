<?php

namespace App\Services\Gestao\Relatorios\Modulos;

use App\Services\Gestao\ServicoVendaProjetos;
use App\Services\Projetos\ServicoAnaliticoProjetos;
use App\Support\Tenancy\ContextoEmpresa;

/**
 * Projectos (relatorios_gestao.js:468-530): proveitos, custos e margem por projecto; execução e desvio orçamental.
 * Os números vêm do módulo de Projectos (ServicoAnaliticoProjetos::rentabilidade, ADR-052), que já reproduz esta secção
 * do legado: razão do projecto + facturas da encomenda sem IVA (NC abatem) + compras imputadas; orçamento, aditamentos
 * aprovados e execução são situação actual.
 * Desvio orçamental como no legado (custoAcum, relatorios_gestao.js:502): só os custos do razão analítico do projecto até
 * ao fim do período ÷ orçamento − 1 — as compras imputadas contam nos custos do período mas não no custo acumulado
 * (verificação visual de 2026-10-08, linha 1.2.1: o novo mostrava «Desvio orçamental +20,97 %» que o antigo não mostra).
 * Versão Actual (ADR-071, G1 — relatorios_gestao.js:470-542): venda contratada, aditamentos, venda global, facturado
 * acumulado até ao fim do período, por facturar e % facturado (situação actual, sem IVA — ServicoVendaProjetos); os
 * proveitos passam a «Proveitos do período» e a tabela a «Venda, facturação e rentabilidade por projecto».
 */
final class ModuloProjetos extends ModuloGestao
{
    public function __construct(ContextoEmpresa $contexto, private readonly ServicoAnaliticoProjetos $analitico, private readonly ServicoVendaProjetos $vendaProjetos)
    {
        parent::__construct($contexto);
    }

    public function id(): string
    {
        return 'projectos';
    }

    public function nome(): string
    {
        return 'Projectos';
    }

    public function descricao(): string
    {
        return 'Venda contratada, facturação, custos e margem por projecto; execução e desvio orçamental.';
    }

    public function calcular(array $p): array
    {
        $r = $this->analitico->rentabilidade($p['inicio'], $p['fim']);
        $k = $r['kpis'];
        $acumRazao = $this->custoAcumuladoRazao(array_column($r['linhas'], 'projeto_id'), $p['fim']);
        $desvio = fn ($orcamento, $acum) => (float) $orcamento > 0 ? round(((float) $acum / (float) $orcamento - 1) * 100, 2) : null;
        $venda = $this->vendaProjetos->porProjeto(array_column($r['linhas'], 'projeto_id'), $p['fim']);
        $semVenda = ['venda' => '0.00', 'aditamentos' => '0.00', 'venda_global' => '0.00', 'faturado' => '0.00', 'por_faturar' => '0.00', 'faturado_pct' => null];
        $linhas = array_map(fn ($l) => ['nome' => $l['nome'], 'estado' => $l['estado']] + ($venda[$l['projeto_id']] ?? $semVenda) + [
            'proveitos' => $l['proveitos'], 'custos' => $l['custos'], 'margem' => $l['margem'],
            'orcamento' => $l['orcamento'], 'desvio' => $desvio($l['orcamento'], $acumRazao[$l['projeto_id']] ?? '0.00'), 'execucao' => $l['execucao'], 'horas' => $l['horas']], $r['linhas']);
        usort($linhas, fn ($a, $b) => (float) $b['venda_global'] <=> (float) $a['venda_global']);
        $k['desvio_pct'] = $desvio($k['orcamento'], array_reduce($r['linhas'], fn ($t, $l) => bcadd($t, $acumRazao[$l['projeto_id']] ?? '0.00', 2), '0.00'));
        $v = ServicoVendaProjetos::totais($venda);

        return [
            'kpis' => [
                self::k('activos', 'Projectos activos', $k['projetos_ativos'], 'num', 'neutro', '', ['actual' => true]),
                self::k('venda', 'Venda contratada (sem IVA)', $v['venda'], 'kz', 'sobe', 'Valor das encomendas associadas aos projectos, sem IVA.', ['actual' => true]),
                self::k('venda_global', 'Venda global (c/ aditamentos)', $v['venda_global'], 'kz', 'sobe', 'Encomendas + aditamentos aprovados, sem IVA.', ['actual' => true]),
                self::k('facturado', 'Facturado acumulado (sem IVA)', $v['faturado'], 'kz', 'sobe', 'Facturas e facturas-recibo dos projectos até ao fim do período, menos notas de crédito.', ['actual' => true]),
                self::k('por_facturar', 'Por facturar', $v['por_faturar'], 'kz', 'neutro', 'Venda global − facturado acumulado (carteira por emitir).', ['actual' => true]),
                self::k('facturado_pct', '% facturado da venda', $v['faturado_pct'], 'pct', 'sobe', '', ['actual' => true]),
                self::k('proveitos', 'Proveitos do período', $k['proveitos'], 'kz', 'sobe', 'Razão do projecto + facturas do período ligadas à encomenda do projecto.'),
                self::k('custos', 'Custos dos projectos', $k['custos'], 'kz', 'desce', 'Razão do projecto + compras imputadas.'),
                self::k('margem', 'Margem dos projectos', $k['margem'], 'kz', 'sobe', 'Proveitos − custos.'),
                self::k('margem_pct', 'Margem %', $k['margem_pct'], 'pct', 'sobe'),
                self::k('horas', 'Horas registadas', $k['horas'], 'horas', 'neutro', 'Folhas de horas no período.'),
                self::k('orcamento', 'Orçamento total', $k['orcamento'], 'kz', 'neutro', '', ['actual' => true]),
                self::k('desvio', 'Desvio orçamental (custo acumulado)', $k['desvio_pct'], 'pct', 'desce', 'Custo acumulado até ao fim ÷ orçamento − 1. Negativo = abaixo do orçamento.'),
                self::k('aditamentos', 'Aditamentos aprovados', $k['aditamentos_aprovados'], 'kz', 'neutro', 'Trabalhos a mais aprovados (valor total).', ['actual' => true]),
                self::k('atrasadas', 'Tarefas em atraso', $k['tarefas_atrasadas'], 'num', 'desce', 'Data de fim prevista anterior ao fim do período e não concluídas.'),
            ],
            'tabelas' => [self::tabela('rentabilidade', 'Venda, facturação e rentabilidade por projecto', 'nome', [['nome', 'Projecto'], ['estado', 'Estado'],
                ['venda', 'Venda contratada', 'kz'], ['aditamentos', 'Aditamentos', 'kz'], ['venda_global', 'Venda global', 'kz'], ['faturado', 'Facturado', 'kz'],
                ['por_faturar', 'Por facturar', 'kz'], ['faturado_pct', '% facturado', 'pct'], ['proveitos', 'Proveitos (período)', 'kz'],
                ['custos', 'Custos', 'kz'], ['margem', 'Margem', 'kz'], ['orcamento', 'Orçamento', 'kz'], ['desvio', 'Desvio orç.', 'pct'], ['execucao', 'Execução', 'pct'], ['horas', 'Horas', 'num']], $linhas)],
            'graficos' => [],
            'notas' => ['Estado, orçamento, aditamentos e execução das tarefas são a situação actual (não têm data).',
                'Venda contratada, venda global e facturado acumulado são valores sem IVA: a venda é a encomenda associada ao projecto e o facturado conta as facturas emitidas até ao fim do período, menos as notas de crédito.'],
        ];
    }

    /**
     * Custo acumulado (razão analítico, natureza CUSTO, data ≤ fim) por projecto.
     *
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function custoAcumuladoRazao(array $ids, string $fim): array
    {
        $t = [];
        if (! $ids) {
            return $t;
        }
        foreach ($this->analitico->movimentos($ids) as $m) {
            if (($m['fonte'] ?? null) === 'RAZAO' && $m['natureza'] === 'CUSTO' && $m['data'] && $m['data'] <= $fim) {
                $t[$m['projeto_id']] = bcadd($t[$m['projeto_id']] ?? '0.00', (string) $m['valor'], 2);
            }
        }

        return $t;
    }
}
