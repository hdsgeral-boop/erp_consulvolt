<?php

namespace App\Services\Gestao\Relatorios\Modulos;

use Illuminate\Support\Facades\DB;

/**
 * Recursos Humanos (relatorios_gestao.js:368-425): efectivo, custo do trabalho, horas extra, absentismo, férias e desempenho.
 * Regras do legado: efectivo = colaboradores activos admitidos até ao fim (sem data de admissão contam sempre); custos dos
 * processamentos FECHADOS ou VALIDADOS com mês dentro do período; horas extra e descontos por faltas = rubricas calculadas
 * «H. Extras» e «Desc. Falta» (pro rata de dias e por hora); horas contratadas = dias de contrato (22 por omissão) × horas
 * por dia (8 por omissão); férias = dias dos períodos não cancelados iniciados no período; avaliações concluídas com data no
 * período (sem data: do ano do fim).
 * Fonte dos valores: a fotografia dos resultados gravada no encerramento (resultados_folha_salarial, ADR-036), que é a
 * base dos recibos e da contabilização. O legado recalculava cada processamento ao vivo (calculatePeriodData), pelo que os
 * números de meses antigos mudavam com os contratos actuais.
 */
final class ModuloRH extends ModuloGestao
{
    public function id(): string
    {
        return 'rh';
    }

    public function nome(): string
    {
        return 'Recursos Humanos';
    }

    public function descricao(): string
    {
        return 'Efectivo, custo do trabalho, horas extra, absentismo, férias e desempenho.';
    }

    public function calcular(array $p): array
    {
        $e = $this->empresa();
        $colab = DB::table('colaboradores')->where('empresa_id', $e)->whereNull('eliminado_em')
            ->selectRaw("COUNT(*) FILTER (WHERE estado IS DISTINCT FROM 'INACTIVO' AND (data_admissao IS NULL OR data_admissao <= ?)) AS efectivo,
                COUNT(*) FILTER (WHERE data_admissao BETWEEN ? AND ?) AS admissoes,
                COUNT(*) FILTER (WHERE estado IS DISTINCT FROM 'INACTIVO' AND (data_admissao IS NULL OR data_admissao <= ?) AND sexo = 'F') AS mulheres,
                COUNT(*) FILTER (WHERE estado IS DISTINCT FROM 'INACTIVO' AND (data_admissao IS NULL OR data_admissao <= ?) AND COALESCE(sexo, '') <> '') AS com_sexo",
                [$p['fim'], $p['inicio'], $p['fim'], $p['fim'], $p['fim']])->first();
        $periodos = DB::table('periodos_processamento_salarial')->where('empresa_id', $e)->whereIn('estado', ['FECHADO', 'VALIDADO'])->get(['id', 'mes_ano'])
            ->filter(function ($x) use ($p) {
                if (! preg_match('/^(\d{1,2})[\/-](\d{4})$/', (string) $x->mes_ano, $m)) {
                    return false;
                }
                $chave = $m[2].'-'.str_pad($m[1], 2, '0', STR_PAD_LEFT);

                return $chave >= substr($p['inicio'], 0, 7) && $chave <= substr($p['fim'], 0, 7);
            })->values();
        $ids = $periodos->pluck('id')->all();
        $res = $ids ? DB::table('resultados_folha_salarial as r')->whereIn('r.periodo_processamento_salarial_id', $ids)->where('r.empresa_id', $e)
            ->selectRaw("r.periodo_processamento_salarial_id AS periodo, r.colaborador_id, r.bruto, r.liquido, r.inss_trabalhador, r.inss_patronal, r.irt, r.avencado, r.dias_contrato,
                (SELECT COALESCE(SUM((x->>'valor')::numeric), 0) FROM jsonb_array_elements(COALESCE(r.rubricas, '[]'::jsonb)) x WHERE x->>'nome' = 'H. Extras') AS hextra_v,
                (SELECT COALESCE(SUM(NULLIF(x->>'horas', '')::numeric), 0) FROM jsonb_array_elements(COALESCE(r.rubricas, '[]'::jsonb)) x WHERE x->>'nome' = 'H. Extras') AS hextra_h,
                (SELECT COALESCE(SUM((x->>'valor')::numeric), 0) FROM jsonb_array_elements(COALESCE(r.rubricas, '[]'::jsonb)) x WHERE x->>'nome' = 'Desc. Falta') AS faltas_v,
                (SELECT COALESCE(SUM(NULLIF(x->>'horas', '')::numeric), 0) FROM jsonb_array_elements(COALESCE(r.rubricas, '[]'::jsonb)) x WHERE x->>'nome' = 'Desc. Falta') AS faltas_h,
                (SELECT c.horas_por_dia FROM contratos_trabalho c WHERE c.colaborador_id = r.colaborador_id ORDER BY c.data_inicio DESC NULLS LAST, c.id DESC LIMIT 1) AS horas_dia")
            ->get() : collect();
        $z = '0.00';
        [$bruto, $liquido, $inssE, $inssT, $irt, $hxV, $ftV, $aven] = [$z, $z, $z, $z, $z, $z, $z, $z];
        $hxH = 0.0;
        $ftH = 0.0;
        $horasContr = 0.0;
        $porMes = [];
        $mes = $periodos->pluck('mes_ano', 'id');
        foreach ($res as $r) {
            $bruto = bcadd($bruto, self::dinheiro($r->bruto), 2);
            $liquido = bcadd($liquido, self::dinheiro($r->liquido), 2);
            $inssE = bcadd($inssE, self::dinheiro($r->inss_patronal), 2);
            $inssT = bcadd($inssT, self::dinheiro($r->inss_trabalhador), 2);
            $irt = bcadd($irt, self::dinheiro($r->irt), 2);
            $hxV = bcadd($hxV, self::dinheiro($r->hextra_v), 2);
            $ftV = bcadd($ftV, self::dinheiro($r->faltas_v), 2);
            $hxH += (float) $r->hextra_h;
            $ftH += (float) $r->faltas_h;
            if ($r->avencado) {
                $aven = bcadd($aven, self::dinheiro($r->bruto), 2);
            }
            $horasContr += ((float) $r->dias_contrato ?: 22) * ((float) $r->horas_dia ?: 8);
            $k = (string) $mes[$r->periodo];
            $porMes[$k] = bcadd($porMes[$k] ?? '0.00', bcadd(self::dinheiro($r->bruto), self::dinheiro($r->inss_patronal), 2), 2);
        }
        $colabs = $res->pluck('colaborador_id')->unique()->count();
        $custoTotal = bcadd($bruto, $inssE, 2);
        $ferias = DB::table('plano_ferias_colaboradores')->where('empresa_id', $e)->where(fn ($q) => $q->whereNull('estado')->orWhere('estado', 'not ilike', '%CANCELADO%'))
            ->whereBetween('data_inicio', [$p['inicio'], $p['fim']])->sum('dias');
        $aval = DB::table('avaliacoes_desempenho_rh')->where('empresa_id', $e)->where('estado', 'ilike', '%CONCLUIDA%')
            ->where(fn ($q) => $q->whereBetween('data_avaliacao', [$p['inicio'], $p['fim']])->orWhere(fn ($q2) => $q2->whereNull('data_avaliacao')->where('ano', (int) substr($p['fim'], 0, 4))))
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(pontuacao), 0) AS s')->first();
        $meses = max(1, $periodos->count());
        $linhasMes = [];
        foreach ($periodos as $per) {
            if (isset($porMes[$per->mes_ano])) {
                $linhasMes[] = ['mes' => $per->mes_ano, 'custo' => $porMes[$per->mes_ano]];
            }
        }

        return [
            'kpis' => [
                self::k('efectivo', 'Efectivo no fim do período', (int) $colab->efectivo, 'num', 'neutro', 'Colaboradores activos admitidos até à data de fim (sem data de admissão contam sempre).'),
                self::k('admissoes', 'Admissões', (int) $colab->admissoes, 'num', 'neutro', 'Data de admissão dentro do período.'),
                self::k('processados', 'Colaboradores processados', $colabs, 'num', 'neutro', 'Com salário em processamentos fechados do período.'),
                self::k('massa', 'Massa salarial bruta', $bruto, 'kz', 'neutro', 'Total ilíquido dos processamentos do período.'),
                self::k('custo', 'Custo total do trabalho', $custoTotal, 'kz', 'desce', 'Ilíquido + INSS a cargo da empresa.'),
                self::k('custo_medio', 'Custo médio mensal por colaborador', $colabs ? bcdiv($custoTotal, (string) ($colabs * $meses), 2) : null, 'kz', 'neutro', 'Custo total ÷ (colaboradores × meses processados).'),
                self::k('liquido', 'Salários líquidos pagos', $liquido, 'kz', 'neutro'),
                self::k('encargos', 'INSS + IRT retidos/entregues', self::soma($inssE, $inssT, $irt), 'kz', 'neutro', 'INSS empresa + INSS trabalhador + IRT.'),
                self::k('hextra', 'Horas extra (valor)', $hxV, 'kz', 'desce', 'Boa prática: acompanhar o peso das horas extra no custo.'),
                self::k('hextra_pct', 'Peso das horas extra', self::pct($hxV, $bruto), 'pct', 'desce', 'Valor das horas extra ÷ massa salarial.'),
                self::k('hextra_h', 'Horas extra (horas)', $hxH, 'horas', 'desce'),
                self::k('absentismo', 'Taxa de absentismo', self::pct($ftH, $horasContr), 'pct', 'desce', 'Horas de falta ÷ horas contratadas nos processamentos.', ['casas' => 2]),
                self::k('faltas', 'Descontos por faltas', $ftV, 'kz', 'desce'),
                self::k('avencados', 'Peso dos avençados', self::pct($aven, $bruto), 'pct', 'neutro', 'Valor pago a prestadores avençados ÷ massa salarial.'),
                self::k('ferias', 'Dias de férias', (int) $ferias, 'num', 'neutro', 'Dias úteis dos períodos de férias iniciados no período (não cancelados).'),
                self::k('aval_media', 'Nota média de desempenho', self::div($aval->s, $aval->n), 'nota', 'sobe', 'Avaliações concluídas (escala 1 a 5).', ['casas' => 2]),
                self::k('mulheres', 'Mulheres no efectivo', self::pct($colab->mulheres, $colab->com_sexo), 'pct', 'neutro', 'Sobre colaboradores com sexo indicado na ficha.'),
            ],
            'tabelas' => [self::tabela('custo_mes', 'Custo do trabalho por processamento', 'mes', [['mes', 'Mês'], ['custo', 'Custo (ilíquido + INSS empresa)', 'kz']], $linhasMes)],
            'graficos' => [],
            'notas' => $periodos->isEmpty() ? ['Não há processamentos salariais FECHADOS ou VALIDADOS com mês dentro deste período.'] : [],
        ];
    }
}
