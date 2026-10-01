<?php

namespace App\Services\Gestao\Paineis;

use Illuminate\Support\Facades\DB;

/**
 * Recursos Humanos (CONSTRUTORES.rh, ui_painel_modulos.js:167-235): totais do último processamento fechado até ao mês
 * (ilíquido, INSS do trabalhador e da empresa, IRT, líquido, custo total = ilíquido + INSS empresa), colaboradores activos e
 * períodos abertos; evolução salarial dos últimos 12 processamentos, colaboradores por unidade de negócio e impostos por período.
 *
 * Fonte: a fotografia do cálculo (resultados_folha_salarial) dos períodos FECHADO e VALIDADO — o legado recalculava cada
 * período (calculatePeriodData) sempre que abria o painel; a fotografia é o que foi pago e contabilizado (ADR-036).
 * Vários períodos do mesmo mês somam. Filtros por unidade de negócio e centro de custo pelos campos do resultado.
 */
final class PainelRH implements Painel
{
    public function __construct(private readonly ConsultasPaineis $consultas) {}

    public function filtraDimensoes(): bool
    {
        return true;
    }

    public function construir(PeriodoPainel $p, array $f): array
    {
        $empresa = $this->consultas->empresa();
        [$fs, $fp] = ConsultasPaineis::filtroDimensoes('r', $f);
        $chave = "to_char(to_date(pp.mes_ano, 'MM/YYYY'), 'YYYY-MM')";
        $serie = DB::select("SELECT {$chave} AS chave, string_agg(DISTINCT pp.mes_ano, ', ') AS periodo, COUNT(r.id) AS n,
                COALESCE(SUM(r.bruto), 0) AS bruto, COALESCE(SUM(r.inss_trabalhador), 0) AS inss_t, COALESCE(SUM(r.inss_patronal), 0) AS inss_e,
                COALESCE(SUM(r.irt), 0) AS irt, COALESCE(SUM(r.liquido), 0) AS liquido
            FROM periodos_processamento_salarial pp LEFT JOIN resultados_folha_salarial r ON r.periodo_processamento_salarial_id = pp.id{$fs}
            WHERE pp.empresa_id = ? AND pp.estado IN ('FECHADO', 'VALIDADO') AND {$chave} <= ?
            GROUP BY 1 ORDER BY 1 DESC LIMIT 12", array_merge($fp, [$empresa, $p->chaveMes]));
        $serie = array_reverse(array_map(fn ($s) => ['chave' => $s->chave, 'periodo' => $s->periodo, 'n' => (int) $s->n, 'bruto' => Indicadores::dinheiro($s->bruto),
            'inss_t' => Indicadores::dinheiro($s->inss_t), 'inss_e' => Indicadores::dinheiro($s->inss_e), 'irt' => Indicadores::dinheiro($s->irt),
            'liquido' => Indicadores::dinheiro($s->liquido)], $serie));
        $ult = $serie ? $serie[count($serie) - 1] : null;
        $abertos = (int) DB::selectOne("SELECT COUNT(*) AS n FROM periodos_processamento_salarial WHERE empresa_id = ? AND estado = 'ABERTO'", [$empresa])->n;
        [$fc, $pcol] = ConsultasPaineis::filtroDimensoes('c', $f);
        $porUN = DB::select("SELECT COALESCE(MAX(TRIM(COALESCE(u.codigo, '') || CASE WHEN u.nome IS NOT NULL THEN ' - ' || u.nome ELSE '' END)), 'Sem unidade de negócio') AS unidade, COUNT(*) AS n
            FROM colaboradores c LEFT JOIN unidades_negocio u ON u.id = c.unidade_negocio_id
            WHERE c.empresa_id = ? AND c.eliminado_em IS NULL AND COALESCE(c.estado, '') <> 'INACTIVO' AND NOT COALESCE(c.reformado, false){$fc}
            GROUP BY c.unidade_negocio_id ORDER BY n DESC LIMIT 10", array_merge([$empresa], $pcol));
        $v = fn (string $k) => $ult[$k] ?? '0.00';
        $custo = fn (array $s) => bcadd($s['bruto'], $s['inss_e'], 2);

        return [
            'aviso' => $ult ? "Último processamento fechado até {$p->nomeMes()} {$p->ano}: {$ult['periodo']}" : 'Nenhum processamento fechado até ao período seleccionado.',
            'kpis' => [
                Indicadores::kpi('iliquido', 'Total Ilíquido', $v('bruto')),
                Indicadores::kpi('inss_trabalhador', 'INSS Trabalhador', $v('inss_t')),
                Indicadores::kpi('inss_empresa', 'INSS Empresa', $v('inss_e')),
                Indicadores::kpi('irt', 'IRT Retido', $v('irt')),
                Indicadores::kpi('liquido', 'Líquido a Pagar', $v('liquido')),
                Indicadores::kpi('custo_total', 'Custo Total Empresa', $ult ? $custo($ult) : '0.00', 'kz', 'Ilíquido + INSS empresa'),
                Indicadores::kpi('colaboradores_activos', 'Colaboradores Activos', $this->consultas->colaboradoresActivos($f), 'num', $ult ? "{$ult['n']} processados no período" : null),
                Indicadores::kpi('periodos_abertos', 'Períodos Abertos', $abertos, 'num'),
            ],
            'graficos' => [
                Indicadores::grafico('evolucao_salarial', 'Evolução Salarial por Período', 'barras', array_column($serie, 'periodo'), [
                    Indicadores::serie('iliquido', 'Total Ilíquido', array_column($serie, 'bruto')),
                    Indicadores::serie('liquido', 'Líquido a Pagar', array_column($serie, 'liquido')),
                    Indicadores::serie('custo_total', 'Custo Total Empresa', array_map($custo, $serie))]),
                Indicadores::grafico('por_unidade_negocio', 'Colaboradores por Unidade de Negócio', 'circular', array_map(fn ($r) => $r->unidade, $porUN),
                    [Indicadores::serie('colaboradores', 'Colaboradores', array_map(fn ($r) => (int) $r->n, $porUN))], false),
            ],
            'tabelas' => [
                Indicadores::tabela('impostos_periodo', 'Impostos e Contribuições por Período', [['periodo', 'Período'], ['iliquido', 'Ilíquido', 'kz'], ['inss_trabalhador', 'INSS trabalhador', 'kz'],
                    ['inss_empresa', 'INSS empresa', 'kz'], ['irt', 'IRT', 'kz'], ['liquido', 'Líquido', 'kz']],
                    array_map(fn ($s) => ['periodo' => $s['periodo'], 'iliquido' => $s['bruto'], 'inss_trabalhador' => $s['inss_t'], 'inss_empresa' => $s['inss_e'], 'irt' => $s['irt'],
                        'liquido' => $s['liquido']], array_reverse($serie))),
            ],
            'atalhos' => [['rotulo' => 'Calcular Salários', 'vista' => 'calcular'], ['rotulo' => 'Recibos e Mapas', 'vista' => 'relatorios'], ['rotulo' => 'Colaboradores', 'vista' => 'colaboradores']],
        ];
    }
}
