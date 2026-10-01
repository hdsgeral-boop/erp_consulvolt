<?php

namespace App\Services\Gestao\Paineis;

use Illuminate\Support\Facades\DB;

/**
 * Tesouraria (CONSTRUTORES.tesouraria, ui_painel_modulos.js:335-388): saldos em bancos (43) e caixa (45) até ao fim do mês
 * (contravalor em Kz), recebimentos e pagamentos do mês, documentos por integrar, movimentos de extracto por conciliar e
 * estado da folha de caixa; entradas × saídas e evolução do saldo das disponibilidades (12 meses); saldos por conta, com o
 * saldo na moeda das contas em moeda estrangeira (moeda da conta no plano; valor_moeda das linhas nessa moeda).
 *
 * Correcções: documentos de tesouraria anulados não contam nos recebimentos/pagamentos nem nos "por integrar"; linhas de
 * extracto anuladas não contam como "por conciliar" (o legado contava tudo o que não estava conciliado).
 */
final class PainelTesouraria implements Painel
{
    public function __construct(private readonly ConsultasPaineis $consultas) {}

    public function filtraDimensoes(): bool
    {
        return true;
    }

    public function construir(PeriodoPainel $p, array $f): array
    {
        $empresa = $this->consultas->empresa();
        [$fs, $fp] = ConsultasPaineis::filtroDimensoes('l', $f);
        $contas = DB::select("SELECT l.codigo_conta AS conta, MAX(pc.descricao) AS descricao, COALESCE(MAX(pc.codigo_moeda), 'AOA') AS moeda,
                SUM(CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE -l.valor END) AS saldo_kz,
                SUM(CASE WHEN COALESCE(pc.codigo_moeda, 'AOA') <> 'AOA' AND l.codigo_moeda = pc.codigo_moeda THEN CASE WHEN l.tipo_dc = 'D' THEN l.valor_moeda ELSE -l.valor_moeda END ELSE 0 END) AS saldo_moeda
            FROM lancamentos_contabeis l LEFT JOIN plano_contas pc ON pc.empresa_id = l.empresa_id AND pc.codigo = l.codigo_conta AND pc.eliminado_em IS NULL
            WHERE l.empresa_id = ? AND l.data_documento <= ? AND (l.codigo_conta LIKE '43%' OR l.codigo_conta LIKE '45%'){$fs}
            GROUP BY l.codigo_conta ORDER BY l.codigo_conta COLLATE \"C\"", array_merge([$empresa, $p->fimMes], $fp));
        $soma = fn (string $px) => array_reduce(array_filter($contas, fn ($c) => str_starts_with($c->conta, $px)), fn ($s, $c) => bcadd($s, Indicadores::dinheiro($c->saldo_kz), 2), '0.00');
        $docs = DB::selectOne("SELECT
                COALESCE(SUM(valor_total) FILTER (WHERE tipo = 'RECEBIMENTO' AND data_documento BETWEEN ? AND ?), 0) AS recebimentos,
                COALESCE(SUM(valor_total) FILTER (WHERE tipo = 'PAGAMENTO' AND data_documento BETWEEN ? AND ?), 0) AS pagamentos,
                COUNT(*) FILTER (WHERE estado = 'PENDENTE') AS por_integrar
            FROM documentos_tesouraria WHERE empresa_id = ? AND anulado_em IS NULL AND COALESCE(estado, '') <> 'ANULADO'",
            [$p->inicioMes, $p->fimMes, $p->inicioMes, $p->fimMes, $empresa]);
        $porConciliar = (int) DB::selectOne("SELECT COUNT(*) AS n FROM linhas_extrato_bancario WHERE empresa_id = ? AND COALESCE(estado, '') NOT IN ('CONCILIADO', 'ANULADO')", [$empresa])->n;
        $caixa = DB::selectOne("SELECT COUNT(*) FILTER (WHERE estado = 'ABERTA') AS abertas, COUNT(*) FILTER (WHERE estado = 'FECHADA') AS fechadas FROM sessoes_caixa WHERE empresa_id = ?", [$empresa]);
        $mov = $this->consultas->disponibilidadesPorMes($p, $f);
        $acum = $mov['saldo_inicial'];
        $saldos = [];
        foreach ($p->chaves() as $k) {
            $acum = bcsub(bcadd($acum, $mov['entradas'][$k] ?? '0', 2), $mov['saidas'][$k] ?? '0', 2);
            $saldos[] = $acum;
        }

        return [
            'kpis' => [
                Indicadores::kpi('saldo_bancos', 'Saldo em Bancos', $soma('43'), 'kz', 'Conta 43 (contravalor Kz)'),
                Indicadores::kpi('saldo_caixa', 'Saldo em Caixa', $soma('45'), 'kz', 'Conta 45 (contravalor Kz)'),
                Indicadores::kpi('recebimentos_mes', 'Recebimentos do Mês', Indicadores::dinheiro($docs->recebimentos)),
                Indicadores::kpi('pagamentos_mes', 'Pagamentos do Mês', Indicadores::dinheiro($docs->pagamentos)),
                Indicadores::kpi('documentos_por_integrar', 'Documentos por Integrar', (int) $docs->por_integrar, 'num'),
                Indicadores::kpi('movimentos_por_conciliar', 'Movimentos por Conciliar', $porConciliar, 'num', 'Extractos bancários'),
                Indicadores::kpi('sessao_caixa', 'Sessão de Caixa', (int) $caixa->abertas > 0 ? 'Aberta' : 'Fechada', 'texto', ((int) $caixa->fechadas).' por contabilizar'),
            ],
            'graficos' => [
                Indicadores::grafico('entradas_saidas', 'Entradas vs Saídas de Disponibilidades (12 meses)', 'barras', $p->rotulos(),
                    [Indicadores::serie('entradas', 'Entradas', $p->serie($mov['entradas'])), Indicadores::serie('saidas', 'Saídas', $p->serie($mov['saidas']))]),
                Indicadores::grafico('evolucao_saldo', 'Evolução do Saldo de Disponibilidades', 'linhas', $p->rotulos(), [Indicadores::serie('saldo', 'Saldo (Kz)', $saldos)]),
            ],
            'tabelas' => [
                Indicadores::tabela('saldos_conta', "Saldos por Conta em {$p->nomeMes()} {$p->ano}",
                    [['conta', 'Conta'], ['descricao', 'Descrição'], ['moeda', 'Moeda'], ['saldo_moeda', 'Saldo na Moeda', 'moeda'], ['saldo_kz', 'Saldo (Kz)', 'kz']],
                    array_map(fn ($c) => ['conta' => $c->conta, 'descricao' => $c->descricao, 'moeda' => $c->moeda,
                        'saldo_moeda' => $c->moeda !== 'AOA' ? Indicadores::dinheiro($c->saldo_moeda) : null, 'saldo_kz' => Indicadores::dinheiro($c->saldo_kz)], $contas)),
            ],
            'atalhos' => [['rotulo' => 'Recebimentos', 'vista' => 'teso_gestao_recebimentos'], ['rotulo' => 'Pagamentos', 'vista' => 'teso_gestao_pagamentos'],
                ['rotulo' => 'Folha de Caixa', 'vista' => 'teso_folha_caixa'], ['rotulo' => 'Extractos e Saldos', 'vista' => 'teso_gestao_mapas']],
        ];
    }
}
