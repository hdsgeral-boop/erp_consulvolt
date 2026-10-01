<?php

namespace App\Services\Gestao\Paineis;

use App\Services\Orcamento\ServicoControloOrcamental;
use Illuminate\Support\Facades\DB;

/**
 * Gestão orçamental (CONSTRUTORES.orcamento, ui_painel_modulos.js:561-593). Só por empresa (não na holding).
 *
 * Reutiliza o monitor do controlo orçamental (ServicoControloOrcamental::monitor — realizado + compromissos face ao orçado de
 * cada rubrica dos orçamentos APROVADOS do ano, até ao mês, ADR-044/045). Indicadores: gastos orçados e consumidos das rubricas
 * de exploração, disponível, rubricas em aviso e excedidas, excessos por decidir (valor), orçamentos do ano por estado;
 * gráfico orçado × consumido das 8 maiores rubricas de exploração; tabela das rubricas em aviso ou excedidas (15).
 */
final class PainelOrcamento implements Painel
{
    public function __construct(private readonly ConsultasPaineis $consultas, private readonly ServicoControloOrcamental $controlo) {}

    public function filtraDimensoes(): bool
    {
        return false;
    }

    public function construir(PeriodoPainel $p, array $f): array
    {
        $empresa = $this->consultas->empresa();
        $monitor = $this->controlo->monitor($p->ano, $p->mes);
        $orcs = DB::table('orcamentos_anuais')->where('empresa_id', $empresa)->where('ano', $p->ano)->get(['id', 'tipo', 'versao', 'estado'])->keyBy('id');
        $tipo = fn ($l) => $orcs[$l['orcamento_anual_id']]->tipo ?? null;
        $exploracao = array_values(array_filter($monitor, fn ($l) => $tipo($l) === 'EXPLORACAO'));
        $orcado = array_reduce($exploracao, fn ($s, $l) => bcadd($s, Indicadores::dinheiro($l['orcado']), 2), '0.00');
        $consumido = array_reduce($exploracao, fn ($s, $l) => bcadd($s, Indicadores::dinheiro($l['consumido']), 2), '0.00');
        $aviso = array_values(array_filter($monitor, fn ($l) => $l['estado'] === 'AVISO'));
        $excedidas = array_values(array_filter($monitor, fn ($l) => $l['estado'] === 'EXCEDIDO'));
        $pedidos = DB::selectOne("SELECT COUNT(*) AS n, COALESCE(SUM(valor_excesso), 0) AS v FROM pedidos_extrapolacao_orcamento WHERE empresa_id = ? AND estado = 'PENDENTE'", [$empresa]);
        $estados = $orcs->countBy('estado');
        $top = $exploracao;
        usort($top, fn ($a, $b) => $b['orcado'] <=> $a['orcado']);
        $top = array_slice($top, 0, 8);
        $codigo = fn ($l) => strtok((string) $l['rubrica'], ' ');

        return [
            'aviso' => ($estados['APROVADO'] ?? 0) ? null : "Não há orçamentos aprovados para {$p->ano}. O controlo só usa orçamentos aprovados.",
            'kpis' => [
                Indicadores::kpi('orcado', 'Gastos Orçados até '.PeriodoPainel::MESES[$p->mes - 1], $orcado, 'kz', 'Exploração (base de cada rubrica)'),
                Indicadores::kpi('consumido', 'Gastos Realizados e Comprometidos', $consumido, 'kz', ($x = Indicadores::pct($consumido, $orcado)) !== null ? "{$x} % do orçado" : 'Sem orçado'),
                Indicadores::kpi('disponivel', 'Disponível', bcsub($orcado, $consumido, 2)),
                Indicadores::kpi('rubricas_aviso', 'Rubricas em Aviso', count($aviso), 'num'),
                Indicadores::kpi('rubricas_excedidas', 'Rubricas Excedidas', count($excedidas), 'num'),
                Indicadores::kpi('excessos_por_decidir', 'Excessos por Decidir', (int) $pedidos->n, 'num', (int) $pedidos->n ? Indicadores::dinheiro($pedidos->v).' Kz' : null),
                Indicadores::kpi('orcamentos_aprovados', "Orçamentos {$p->ano} aprovados", $estados['APROVADO'] ?? 0, 'num',
                    ($estados['SUBMETIDO'] ?? 0).' submetidos · '.($estados['RASCUNHO'] ?? 0).' em rascunho'),
            ],
            'graficos' => $top ? [Indicadores::grafico('orcado_consumido', 'Orçado vs Consumido por Rubrica (até '.PeriodoPainel::MESES[$p->mes - 1].')', 'barras', array_map($codigo, $top), [
                Indicadores::serie('orcado', 'Orçado', array_map(fn ($l) => Indicadores::dinheiro($l['orcado']), $top)),
                Indicadores::serie('consumido', 'Consumido', array_map(fn ($l) => Indicadores::dinheiro($l['consumido']), $top))])] : [],
            'tabelas' => [
                Indicadores::tabela('rubricas_alerta', 'Rubricas em Aviso ou Excedidas', [['rubrica', 'Rubrica'], ['orcamento', 'Orçamento'], ['modo', 'Controlo'], ['orcado', 'Orçado', 'kz'],
                    ['consumido', 'Consumido', 'kz'], ['percentagem', 'Execução', 'pct'], ['estado', 'Estado']],
                    array_map(fn ($l) => ['rubrica_orcamental_id' => $l['rubrica_orcamental_id'], 'rubrica' => $l['rubrica'],
                        'orcamento' => ($tipo($l) === 'EXPLORACAO' ? 'Exploração' : 'Tesouraria').' v'.($orcs[$l['orcamento_anual_id']]->versao ?? 1), 'modo' => $l['modo'],
                        'orcado' => Indicadores::dinheiro($l['orcado']), 'consumido' => Indicadores::dinheiro($l['consumido']), 'percentagem' => $l['percentagem'], 'estado' => $l['estado']],
                        array_slice(array_merge($excedidas, $aviso), 0, 15))),
            ],
            'atalhos' => [['rotulo' => 'Orçamentos', 'vista' => 'orc_orcamentos'], ['rotulo' => 'Controlo Orçamental', 'vista' => 'orc_controlo'],
                ['rotulo' => 'Previsões', 'vista' => 'orc_previsoes'], ['rotulo' => 'Alertas e Aprovações', 'vista' => 'orc_alertas']],
        ];
    }
}
