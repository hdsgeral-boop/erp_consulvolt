<?php

namespace App\Services\Gestao\Paineis;

use App\Services\Contabilidade\ServicoDemonstracoesFinanceiras;
use App\Services\Contabilidade\ServicoRelatoriosContabeis;
use Illuminate\Support\Facades\DB;

/**
 * Contabilidade (CONSTRUTORES.contabilidade, ui_painel_modulos.js:390-432): proveitos (6), custos (7), resultado e margem do
 * ano, lançamentos do mês e do ano; proveitos × custos (12 meses), estrutura de custos por conta de 2 dígitos e balancete por
 * classe até ao fim do mês.
 *
 * Reutiliza: o balancete por classe é o do módulo (ServicoRelatoriosContabeis::balancete, nível 1, desde o início) e o
 * resultado líquido da DR pelas notas (ServicoDemonstracoesFinanceiras::calcularDR) é mostrado ao lado do resultado por classes,
 * para conferência (as linhas sem nota não entram na DR — ADR-055).
 * Correcções: proveitos e custos sem o apuramento (períodos 13/14), que zerava o resultado depois do encerramento; o balancete
 * por classe segue as regras dos mapas (classe 9 e apuramento do ano excluídos) — o legado somava tudo.
 * Lançamentos: n.º de lançamentos distintos (numero_lan; sem ele, diário + documento), como o legado.
 */
final class PainelContabilidade implements Painel
{
    private const CLASSES = ['1' => 'Meios Fixos e Investimentos', '2' => 'Existências', '3' => 'Terceiros', '4' => 'Meios Monetários', '5' => 'Capital e Reservas',
        '6' => 'Proveitos e Ganhos', '7' => 'Custos e Perdas', '8' => 'Resultados'];

    public function __construct(
        private readonly ConsultasPaineis $consultas,
        private readonly ServicoRelatoriosContabeis $relatorios,
        private readonly ServicoDemonstracoesFinanceiras $demonstracoes,
    ) {}

    public function filtraDimensoes(): bool
    {
        return true;
    }

    public function construir(PeriodoPainel $p, array $f): array
    {
        $empresa = $this->consultas->empresa();
        $pc = $this->consultas->resultadoPorMes($p->inicioJanela, $p->fimMes, $f);
        $proveitos = $p->somaAno($pc['proveitos']);
        $custos = $p->somaAno($pc['custos']);
        $resultado = bcsub($proveitos, $custos, 2);
        [$fs, $fp] = ConsultasPaineis::filtroDimensoes('l', $f);
        if (! empty($f['origem_holding'])) {
            $fs .= " AND l.empresa_origem_id = ? AND COALESCE(l.tipo_consolidacao, '') NOT IN ('ELIMINACAO', 'CONVERSAO')";
            $fp[] = (int) $f['origem_holding'];
        }
        $chaveLan = "COALESCE(l.numero_lan, l.diario_id::text || '-' || COALESCE(l.numero_documento, ''))";
        $lanc = DB::selectOne("SELECT COUNT(DISTINCT {$chaveLan}) FILTER (WHERE l.data_documento >= ?) AS mes, COUNT(DISTINCT {$chaveLan}) AS ano
            FROM lancamentos_contabeis l WHERE l.empresa_id = ? AND l.data_documento BETWEEN ? AND ?{$fs}", array_merge([$p->inicioMes, $empresa, $p->inicioAno, $p->fimMes], $fp));
        $estrutura = DB::select("SELECT left(l.codigo_conta, 2) AS conta, MAX(pc.descricao) AS descricao, SUM(CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE -l.valor END) AS total
            FROM lancamentos_contabeis l LEFT JOIN plano_contas pc ON pc.empresa_id = l.empresa_id AND pc.codigo = left(l.codigo_conta, 2) AND pc.eliminado_em IS NULL
            WHERE l.empresa_id = ? AND l.codigo_conta LIKE '7%' AND l.data_documento BETWEEN ? AND ? AND NOT (COALESCE(l.periodo_id, 0) IN (13, 14) AND NOT EXISTS (SELECT 1 FROM diarios_contabeis d_sal WHERE d_sal.id = l.diario_id AND d_sal.codigo = 'SAL')){$fs}
            GROUP BY 1 ORDER BY total DESC LIMIT 8", array_merge([$empresa, $p->inicioAno, $p->fimMes], $fp));
        $filtrosMapas = array_filter(['unidade_negocio_id' => $f['unidade_negocio_id'] ?? null, 'centro_custo_id' => $f['centro_custo_id'] ?? null]);
        $classes = [];
        $dr = null;
        if (empty($f['origem_holding'])) {
            $balancete = $this->relatorios->balancete(['data_inicio' => '1900-01-01', 'data_fim' => $p->fimMes, 'nivel' => 1] + $filtrosMapas);
            foreach ($balancete['linhas'] as $l) {
                if (isset(self::CLASSES[$l['codigo_conta']])) {
                    $classes[] = ['classe' => $l['codigo_conta'], 'descricao' => self::CLASSES[$l['codigo_conta']], 'debito' => $l['debito'], 'credito' => $l['credito'],
                        'saldo_devedor' => $l['saldo_devedor'], 'saldo_credor' => $l['saldo_credor']];
                }
            }
            $dr = $this->demonstracoes->calcularDR($p->inicioAno, $p->fimMes, $filtrosMapas)['resultado_liquido'];
        }
        $kpis = [
            Indicadores::kpi('proveitos_ano', "Proveitos {$p->ano}", $proveitos, 'kz', 'Classe 6'),
            Indicadores::kpi('custos_ano', "Custos {$p->ano}", $custos, 'kz', 'Classe 7'),
            Indicadores::kpi('resultado_ano', 'Resultado do Exercício', $resultado, 'kz', bccomp($resultado, '0', 2) >= 0 ? 'Lucro' : 'Prejuízo'),
            Indicadores::kpi('margem', 'Margem', Indicadores::pct($resultado, $proveitos), 'pct', 'Resultado / Proveitos'),
            Indicadores::kpi('lancamentos_mes', 'Lançamentos do Mês', (int) $lanc->mes, 'num'),
            Indicadores::kpi('lancamentos_ano', "Lançamentos {$p->ano}", (int) $lanc->ano, 'num'),
        ];
        if ($dr !== null) {
            $kpis[] = Indicadores::kpi('resultado_liquido_dr', 'Resultado Líquido (DR)', $dr, 'kz', 'Pelas notas da Demonstração de Resultados');
        }

        return [
            'kpis' => $kpis,
            'graficos' => [
                Indicadores::grafico('proveitos_custos', 'Proveitos vs Custos (12 meses)', 'linhas', $p->rotulos(),
                    [Indicadores::serie('proveitos', 'Proveitos', $p->serie($pc['proveitos'])), Indicadores::serie('custos', 'Custos', $p->serie($pc['custos']))]),
                Indicadores::grafico('estrutura_custos', "Estrutura de Custos ({$p->ano})", 'circular', array_map(fn ($r) => trim("{$r->conta} ".($r->descricao ?? '')), $estrutura),
                    [Indicadores::serie('custos', 'Custos', array_map(fn ($r) => Indicadores::dinheiro($r->total), $estrutura))]),
            ],
            'tabelas' => $classes ? [Indicadores::tabela('balancete_classes', "Balancete por Classe até {$p->nomeMes()} {$p->ano}",
                [['classe', 'Classe'], ['descricao', 'Descrição'], ['debito', 'Débitos', 'kz'], ['credito', 'Créditos', 'kz'], ['saldo_devedor', 'Saldo Devedor', 'kz'], ['saldo_credor', 'Saldo Credor', 'kz']],
                $classes)] : [],
            'atalhos' => [['rotulo' => 'Lançamentos', 'vista' => 'lancamentos'], ['rotulo' => 'Balancetes e Mapas', 'vista' => 'relatorios_contabeis'], ['rotulo' => 'Rotinas de Fecho', 'vista' => 'contab_rotinas']],
        ];
    }
}
