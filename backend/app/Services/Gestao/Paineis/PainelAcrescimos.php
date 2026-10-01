<?php

namespace App\Services\Gestao\Paineis;

use App\Services\Acrescimos\ServicoPropostaAcrescimos;
use Illuminate\Support\Facades\DB;

/**
 * Acréscimos e diferimentos (CONSTRUTORES.acrescimos, ui_painel_modulos.js:626-653). Só por empresa (não na holding).
 *
 * Indicadores: acréscimos e diferimentos em aberto (registos não anulados, concluídos nem regularizados), reconhecido no mês
 * (períodos contabilizados), registos por regularizar/terminar e contas de balanço com diferença módulo × diário; reconhecimentos
 * contabilizados por mês (12 meses) e a reconciliação das contas no fim do mês — reutiliza ServicoPropostaAcrescimos::reconciliacao
 * (ADR-053). Filtros por unidade de negócio e centro de custo nos registos.
 * Correcção: o "reconhecido" não inclui o lançamento INICIAL (a passagem do documento para o balanço, que não é reconhecimento
 * em resultados) — o legado somava todos os lançamentos contabilizados do mês (ui_painel_modulos.js:640, 647).
 */
final class PainelAcrescimos implements Painel
{
    public function __construct(private readonly ConsultasPaineis $consultas, private readonly ServicoPropostaAcrescimos $proposta) {}

    public function filtraDimensoes(): bool
    {
        return true;
    }

    public function construir(PeriodoPainel $p, array $f): array
    {
        $empresa = $this->consultas->empresa();
        [$fs, $fp] = ConsultasPaineis::filtroDimensoes('i', $f);
        $vivos = "COALESCE(i.estado, '') NOT IN ('ANULADO', 'CONCLUIDO', 'REGULARIZADO')";
        $t = DB::selectOne("SELECT COALESCE(SUM(i.valor) FILTER (WHERE i.tipo = 'ACRESCIMO' AND {$vivos}), 0) AS acrescimos, COUNT(*) FILTER (WHERE i.tipo = 'ACRESCIMO' AND {$vivos}) AS n_acrescimos,
                COALESCE(SUM(i.valor) FILTER (WHERE i.tipo = 'DIFERIMENTO' AND {$vivos}), 0) AS diferimentos, COUNT(*) FILTER (WHERE i.tipo = 'DIFERIMENTO' AND {$vivos}) AS n_diferimentos,
                COUNT(*) FILTER (WHERE i.estado IN ('A_REGULARIZAR', 'A_TERMINAR')) AS por_regularizar
            FROM itens_acrescimos_diferimentos i WHERE i.empresa_id = ?{$fs}", array_merge([$empresa], $fp));
        $rec = DB::select("SELECT pl.periodo, SUM(pl.valor) AS total FROM periodos_lancamento_acrescimos pl JOIN itens_acrescimos_diferimentos i ON i.id = pl.item_acrescimo_diferimento_id
            WHERE pl.empresa_id = ? AND pl.estado = 'CONTABILIZADO' AND COALESCE(pl.tipo, '') <> 'INICIAL' AND pl.periodo BETWEEN ? AND ?{$fs} GROUP BY 1",
            array_merge([$empresa, substr($p->inicioJanela, 0, 7), $p->chaveMes], $fp));
        $porMes = array_column(array_map(fn ($r) => [$r->periodo, $r->total], $rec), 1, 0);
        $recon = $this->proposta->reconciliacao($p->fimMes);
        $difs = count(array_filter($recon, fn ($r) => bccomp(ltrim($r['diferenca'], '-'), '0.01', 2) > 0));

        return [
            'kpis' => [
                Indicadores::kpi('acrescimos_aberto', 'Acréscimos em Aberto', Indicadores::dinheiro($t->acrescimos), 'kz', ((int) $t->n_acrescimos).' registo(s)'),
                Indicadores::kpi('diferimentos_aberto', 'Diferimentos em Aberto', Indicadores::dinheiro($t->diferimentos), 'kz', ((int) $t->n_diferimentos).' registo(s)'),
                Indicadores::kpi('reconhecido_mes', "Reconhecido em {$p->nomeMes()}", Indicadores::dinheiro($porMes[$p->chaveMes] ?? '0')),
                Indicadores::kpi('por_regularizar', 'Por Regularizar / Terminar', (int) $t->por_regularizar, 'num'),
                Indicadores::kpi('contas_diferenca', 'Contas com Diferenças', $difs, 'num', $difs ? 'Módulo ≠ Diário' : 'Módulo = Diário'),
            ],
            'graficos' => [
                Indicadores::grafico('reconhecimentos', 'Reconhecimentos Contabilizados (12 meses)', 'barras', $p->rotulos(), [Indicadores::serie('valor', 'Valor', $p->serie($porMes))]),
            ],
            'tabelas' => [
                Indicadores::tabela('reconciliacao', 'Reconciliação das Contas em '.date('d/m/Y', strtotime($p->fimMes)), [['conta', 'Conta'], ['modulo', 'Módulo', 'kz'], ['diario', 'Diário', 'kz'],
                    ['diferenca', 'Diferença', 'kz']], $recon),
            ],
            'atalhos' => [['rotulo' => 'Registos', 'vista' => 'ad_registos'], ['rotulo' => 'Proposta Mensal', 'vista' => 'ad_propostas'], ['rotulo' => 'Recolher Documentos', 'vista' => 'ad_recolher']],
        ];
    }
}
