<?php

namespace App\Services\Gestao\Paineis;

use Illuminate\Support\Facades\DB;

/**
 * Compras (CONSTRUTORES.compras, ui_painel_modulos.js:284-314): compras do mês e do ano, fornecedores a pagar (32), pedidos por
 * aprovar, encomendas em curso, facturas por contabilizar; compras mensais, top 5 fornecedores e encomendas em curso.
 *
 * Correcções: facturas, encomendas e pedidos anulados não contam (o legado não tinha anulação); base sem IVA por omissão.
 */
final class PainelCompras implements Painel
{
    public function __construct(private readonly ConsultasPaineis $consultas) {}

    public function filtraDimensoes(): bool
    {
        return true;
    }

    public function construir(PeriodoPainel $p, array $f): array
    {
        $empresa = $this->consultas->empresa();
        $porMes = $this->consultas->comprasPorMes($p->inicioJanela, $p->fimMes, $f);
        [$fs, $fp] = ConsultasPaineis::filtroDimensoes('c', $f);
        $valor = ConsultasPaineis::valorCompra('c', $f);
        $top = DB::select("SELECT c.fornecedor_id, COALESCE(MAX(t.nome), 'N/D') AS fornecedor, SUM({$valor}) AS total FROM faturas_compra c LEFT JOIN terceiros t ON t.id = c.fornecedor_id
            WHERE c.empresa_id = ? AND c.anulado_em IS NULL AND c.data BETWEEN ? AND ?{$fs} GROUP BY c.fornecedor_id ORDER BY total DESC LIMIT 5",
            array_merge([$empresa, $p->inicioAno, $p->fimMes], $fp));
        $porContabilizar = (int) DB::selectOne("SELECT COUNT(*) AS n FROM faturas_compra c WHERE c.empresa_id = ? AND c.anulado_em IS NULL AND NOT COALESCE(c.contabilizado, false){$fs}",
            array_merge([$empresa], $fp))->n;
        $pedidos = (int) DB::selectOne("SELECT COUNT(*) AS n FROM pedidos_compra c WHERE c.empresa_id = ? AND c.anulado_em IS NULL AND c.estado = 'PENDENTE'{$fs}", array_merge([$empresa], $fp))->n;
        $condEnc = "c.empresa_id = ? AND c.anulado_em IS NULL AND COALESCE(c.estado, '') NOT IN ('RECEBIDO', 'ANULADO', 'ANULADA'){$fs}";
        $emCurso = (int) DB::selectOne("SELECT COUNT(*) AS n FROM encomendas_compra c WHERE {$condEnc}", array_merge([$empresa], $fp))->n;
        $encomendas = DB::select("SELECT c.id, c.numero_encomenda, c.data::date AS data, t.nome AS fornecedor, c.estado, COALESCE(c.codigo_moeda, 'AOA') AS moeda
            FROM encomendas_compra c LEFT JOIN terceiros t ON t.id = c.fornecedor_id WHERE {$condEnc} ORDER BY c.data DESC NULLS LAST, c.id DESC LIMIT 8", array_merge([$empresa], $fp));
        $fornecedores = Indicadores::negar($this->consultas->saldos($p->fimMes, ['forn' => ['32']], $f)['forn']);

        return [
            'kpis' => [
                Indicadores::kpi('compras_mes', 'Compras do Mês', $porMes[$p->chaveMes] ?? '0.00', 'kz', 'Facturas de fornecedor'),
                Indicadores::kpi('compras_ano', "Compras {$p->ano}", $p->somaAno($porMes)),
                Indicadores::kpi('fornecedores_pagar', 'Fornecedores a Pagar', $fornecedores, 'kz', 'Saldo contabilístico da conta 32'),
                Indicadores::kpi('pedidos_por_aprovar', 'Pedidos por Aprovar', $pedidos, 'num'),
                Indicadores::kpi('encomendas_em_curso', 'Encomendas em Curso', $emCurso, 'num', 'Por receber no Armazém'),
                Indicadores::kpi('faturas_por_contabilizar', 'Facturas por Contabilizar', $porContabilizar, 'num'),
            ],
            'graficos' => [
                Indicadores::grafico('compras_mensais', 'Compras Mensais (12 meses)', 'barras', $p->rotulos(), [Indicadores::serie('compras', 'Compras', $p->serie($porMes))]),
                Indicadores::grafico('por_fornecedor', "Compras por Fornecedor ({$p->ano})", 'circular', array_map(fn ($r) => $r->fornecedor, $top),
                    [Indicadores::serie('total', 'Total', array_map(fn ($r) => Indicadores::dinheiro($r->total), $top))]),
            ],
            'tabelas' => [
                Indicadores::tabela('top_fornecedores', "Top 5 Fornecedores ({$p->ano})", [['fornecedor', 'Fornecedor'], ['total', 'Compras', 'kz']],
                    array_map(fn ($r) => ['fornecedor_id' => $r->fornecedor_id, 'fornecedor' => $r->fornecedor, 'total' => Indicadores::dinheiro($r->total)], $top)),
                Indicadores::tabela('encomendas_em_curso', 'Encomendas em Curso', [['numero_encomenda', 'Encomenda'], ['data', 'Data', 'data'], ['fornecedor', 'Fornecedor'], ['estado', 'Estado'], ['moeda', 'Moeda']],
                    array_map(fn ($r) => (array) $r, $encomendas)),
            ],
            'atalhos' => [['rotulo' => 'Compras', 'vista' => 'compras'], ['rotulo' => 'Encomendas', 'vista' => 'compras_encomendas'], ['rotulo' => 'Facturas de Fornecedor', 'vista' => 'compras_faturacao']],
        ];
    }
}
