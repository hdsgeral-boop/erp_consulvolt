<?php

namespace App\Services\Gestao\Paineis;

use App\Models\FunilVendasCRM;
use App\Services\CRM\RegrasCRM;
use App\Services\CRM\ServicoIndicadoresCRM;
use Illuminate\Support\Facades\DB;

/**
 * CRM (CONSTRUTORES.crm, ui_painel_modulos.js:595-624). Só por empresa (não na holding).
 *
 * Reutiliza os indicadores do módulo (ServicoIndicadoresCRM::kpis no funil principal, de 1 de Janeiro ao fim do mês, e
 * ::previsao a 6 meses — ADR-054): valor do funil, previsão ponderada, ganho no ano, taxa de ganho, ciclo médio, ticket médio e
 * actividades em atraso; previsão de fecho, funil por etapa, motivos de perda e conversão entre etapas.
 * Sem funis configurados devolve um aviso: o painel é só de leitura e não cria os funis iniciais (o módulo cria-os ao abrir).
 */
final class PainelCRM implements Painel
{
    public function __construct(private readonly ConsultasPaineis $consultas, private readonly ServicoIndicadoresCRM $indicadores) {}

    public function filtraDimensoes(): bool
    {
        return false;
    }

    public function construir(PeriodoPainel $p, array $f): array
    {
        if (! FunilVendasCRM::query()->exists()) {
            return ['aviso' => 'O CRM ainda não tem funis de vendas configurados.', 'kpis' => [], 'graficos' => [], 'tabelas' => [],
                'atalhos' => [['rotulo' => 'Pipelines e Modelos', 'vista' => 'crm_config']]];
        }
        $k = $this->indicadores->kpis(null, $p->inicioAno, $p->fimMes);
        $prev = $this->indicadores->previsao(null, 6, null);
        $atrasadas = (int) DB::selectOne('SELECT COUNT(*) AS n FROM atividades_comerciais_crm WHERE empresa_id = ? AND NOT COALESCE(concluida, false) AND data_prevista < ?',
            [$this->consultas->empresa(), RegrasCRM::hoje()])->n;
        $rotuloMes = fn (string $m) => PeriodoPainel::MESES[(int) substr($m, 5, 2) - 1].'/'.substr($m, 2, 2);

        return [
            'kpis' => [
                Indicadores::kpi('valor_funil', 'Valor do Pipeline', $k['valor_funil'], 'kz', ($k['funil']['nome'] ?? '').' · em aberto'),
                Indicadores::kpi('ponderado', 'Previsão Ponderada', $k['ponderado'], 'kz', 'Valor × probabilidade da etapa'),
                Indicadores::kpi('valor_ganho', "Ganho em {$p->ano}", $k['valor_ganho'], 'kz', "{$k['ganhas']} oportunidade(s)"),
                Indicadores::kpi('taxa_ganho', 'Taxa de Ganho', $k['taxa_ganho'], 'pct', "{$k['ganhas']} ganhas · {$k['perdidas']} perdidas"),
                Indicadores::kpi('ciclo_medio', 'Ciclo Médio de Venda', $k['ciclo_medio'] === null ? null : (int) round($k['ciclo_medio']), 'dias'),
                Indicadores::kpi('ticket_medio', 'Ticket Médio', $k['ticket_medio']),
                Indicadores::kpi('atividades_atraso', 'Actividades em Atraso', $atrasadas, 'num'),
            ],
            'graficos' => [
                Indicadores::grafico('previsao_fecho', 'Previsão de Fecho (6 meses)', 'barras', array_map(fn ($m) => $rotuloMes($m['mes']), $prev['por_mes']), [
                    Indicadores::serie('bruto', 'Bruto', array_column($prev['por_mes'], 'bruto')),
                    Indicadores::serie('ponderado', 'Ponderado', array_column($prev['por_mes'], 'ponderado'))]),
                Indicadores::grafico('funil_etapa', 'Pipeline por Etapa', 'circular', array_column($k['por_etapa'], 'etapa'), [Indicadores::serie('valor', 'Valor', array_column($k['por_etapa'], 'valor'))]),
            ],
            'tabelas' => [
                Indicadores::tabela('motivos_perda', 'Motivos de Perda', [['motivo', 'Motivo'], ['n', 'Oportunidades', 'num'], ['valor', 'Valor', 'kz']], array_slice($k['motivos'], 0, 8)),
                Indicadores::tabela('conversao', 'Conversão entre Etapas', [['etapa', 'Etapa'], ['seguinte', 'Seguinte'], ['entraram', 'Entraram', 'num'], ['avancaram', 'Avançaram', 'num'], ['taxa', 'Taxa', 'pct']],
                    $k['conversao']),
            ],
            'atalhos' => [['rotulo' => 'Pipeline', 'vista' => 'crm_pipeline'], ['rotulo' => 'Agenda Comercial', 'vista' => 'crm_agenda'], ['rotulo' => 'Previsão e Indicadores', 'vista' => 'crm_previsao']],
        ];
    }
}
