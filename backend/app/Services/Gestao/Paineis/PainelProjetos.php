<?php

namespace App\Services\Gestao\Paineis;

use App\Services\Projetos\ServicoAnaliticoProjetos;

/**
 * Projectos e obras (CONSTRUTORES.projetos, ui_painel_modulos.js:434-518).
 *
 * Reutiliza a regra única do razão analítico (ServicoAnaliticoProjetos, ADR-052): a rentabilidade do ano (Janeiro..mês) dá os
 * indicadores e a carteira; os movimentos da janela de 12 meses dão os custos imputados por mês. Correcção (a do ADR-052): o
 * painel do legado somava as linhas dos autos E as facturas que esses autos geravam, e as linhas de compra de pedidos e
 * encomendas — agora custo, proveito e compromisso têm a mesma definição em todos os ecrãs; proveitos sem IVA.
 * A carteira mostra o orçamento, o custo acumulado até ao fim do mês, a execução física, os proveitos e a margem do ano.
 */
final class PainelProjetos implements Painel
{
    public function __construct(private readonly ServicoAnaliticoProjetos $analitico) {}

    /** Percentagem com 1 casa e vírgula, como o legado (toFixed(1).replace('.', ',')). */
    private static function pct1(float $v): string
    {
        return str_replace('.', ',', number_format($v, 1, '.', ''));
    }

    public function filtraDimensoes(): bool
    {
        return false;
    }

    public function construir(PeriodoPainel $p, array $f): array
    {
        $r = $this->analitico->rentabilidade($p->inicioAno, $p->fimMes);
        $k = $r['kpis'];
        $porMes = ['compras' => [], 'outros' => []];
        foreach ($this->analitico->movimentos(null, $p->inicioJanela, $p->fimMes) as $m) {
            if ($m['natureza'] === 'CUSTO' && $m['data']) {
                $g = $m['origem'] === 'COMPRAS' || $m['origem'] === 'AUTOS_SUBEMPREITADAS' ? 'compras' : 'outros';
                $mes = substr($m['data'], 0, 7);
                $porMes[$g][$mes] = bcadd($porMes[$g][$mes] ?? '0.00', $m['valor'], 2);
            }
        }
        $linhas = $r['linhas'];
        $top = $linhas;
        usort($top, fn ($a, $b) => ((float) $b['orcamento'] + (float) $b['custo_acumulado']) <=> ((float) $a['orcamento'] + (float) $a['custo_acumulado']));
        $top = array_slice($top, 0, 8);
        usort($linhas, fn ($a, $b) => strnatcmp((string) $a['nome'], (string) $b['nome']));

        return [
            'kpis' => [
                Indicadores::kpi('projetos_activos', 'Projectos Activos', $k['projetos_ativos'], 'num', count($r['linhas']).' no total'),
                Indicadores::kpi('orcamento', 'Orçamento Total', $k['orcamento']),
                // rótulos e textos do legado (ui_painel_modulos.js:485-489); valores pela regra única do razão analítico (ADR-052)
                Indicadores::kpi('custo_realizado', 'Custo Realizado', $k['custo_acumulado'], 'kz', (float) $k['orcamento'] > 0 ? self::pct1((float) $k['custo_acumulado'] / (float) $k['orcamento'] * 100).' % do orçamento' : 'Sem orçamento'),
                Indicadores::kpi('faturado', 'Faturado', $k['proveitos'], 'kz', 'Faturas ligadas às encomendas'),
                Indicadores::kpi('margem', 'Margem Real', $margem = bcsub($k['proveitos'], $k['custo_acumulado'], 2), 'kz', (float) $k['proveitos'] > 0 ? self::pct1((float) $margem / (float) $k['proveitos'] * 100).' % da faturação' : null),
                Indicadores::kpi('aditamentos', 'Aditamentos Aprovados', $k['aditamentos_aprovados']),
                Indicadores::kpi('tarefas_concluidas', 'Tarefas Concluídas', $k['tarefas_concluidas'].' / '.$k['tarefas_total'], 'texto', $k['tarefas_bloqueadas'].' bloqueadas'),
                Indicadores::kpi('horas', "Horas Registadas {$p->ano}", $k['horas'], 'num'),
            ],
            'graficos' => [
                Indicadores::grafico('custos_mensais', 'Custos Imputados a Projectos (12 meses)', 'barras', $p->rotulos(), [
                    Indicadores::serie('mao_obra_equipamento', 'Mão de obra, equipamento e outros', $p->serie($porMes['outros'])),
                    Indicadores::serie('compras', 'Faturas de compra e subempreitadas', $p->serie($porMes['compras']))]),
                Indicadores::grafico('orcamento_custo_proveito', 'Orçamento vs Custo vs Proveitos por Projecto', 'barras', array_map(fn ($l) => $l['nome'], $top), [
                    Indicadores::serie('orcamento', 'Orçamento', array_column($top, 'orcamento')),
                    Indicadores::serie('custo', 'Custo acumulado', array_column($top, 'custo_acumulado')),
                    Indicadores::serie('proveitos', "Proveitos {$p->ano}", array_column($top, 'proveitos'))]),
            ],
            'tabelas' => [
                Indicadores::tabela('carteira', 'Carteira de Projectos', [['nome', 'Projecto'], ['estado', 'Estado'], ['execucao', 'Execução física', 'pct'], ['orcamento', 'Orçamento', 'kz'],
                    ['custo_acumulado', 'Custo acumulado', 'kz'], ['execucao_orcamental', 'Execução orçamental', 'pct'], ['proveitos', "Proveitos {$p->ano}", 'kz'], ['margem', "Margem {$p->ano}", 'kz']],
                    array_map(fn ($l) => ['projeto_id' => $l['projeto_id'], 'nome' => $l['nome'], 'estado' => $l['estado'], 'execucao' => $l['execucao'], 'orcamento' => $l['orcamento'],
                        'custo_acumulado' => $l['custo_acumulado'], 'execucao_orcamental' => Indicadores::pct($l['custo_acumulado'], $l['orcamento']),
                        'proveitos' => $l['proveitos'], 'margem' => $l['margem']], $linhas)),
            ],
            'atalhos' => [['rotulo' => 'Carteira de Projectos', 'vista' => 'projectos_carteira'], ['rotulo' => 'Extracto de Projecto', 'vista' => 'projectos_extracto'],
                ['rotulo' => 'Planeamento (Gantt)', 'vista' => 'projectos_gantt']],
        ];
    }
}
