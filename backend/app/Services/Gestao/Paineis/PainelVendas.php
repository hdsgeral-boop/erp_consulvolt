<?php

namespace App\Services\Gestao\Paineis;

use Illuminate\Support\Facades\DB;

/**
 * Vendas (CONSTRUTORES.vendas, ui_painel_modulos.js:237-282): facturação do mês e do ano (líquida de notas de crédito),
 * facturas emitidas e valor médio, notas de crédito, clientes a receber (31), proformas/encomendas por converter; facturação
 * mensal, por tipo de documento, top 5 clientes e facturação em moeda estrangeira.
 *
 * Correcções: base sem IVA por omissão (ConsultasPaineis); o legado contava como "por converter" os documentos cujo estado
 * não continha CONVERT/FATUR/FECHAD — com os estados normalizados (ADR-004) contam PF, NE e OR não anulados e não CONCLUIDO.
 */
final class PainelVendas implements Painel
{
    private const TIPOS = ['FT' => 'Factura', 'FR' => 'Factura-Recibo', 'NC' => 'Nota de Crédito'];

    public function __construct(private readonly ConsultasPaineis $consultas) {}

    public function filtraDimensoes(): bool
    {
        return true;
    }

    public function construir(PeriodoPainel $p, array $f): array
    {
        $empresa = $this->consultas->empresa();
        $porMes = $this->consultas->vendasPorMes($p->inicioJanela, $p->fimMes, $f);
        $mes = $porMes[$p->chaveMes] ?? ['total' => '0.00', 'faturas' => 0, 'valor_faturas' => '0.00', 'notas_credito' => '0.00'];
        [$fs, $fp] = ConsultasPaineis::filtroDimensoes('v', $f);
        $valor = ConsultasPaineis::valorVenda('v', $f);
        $base = 'FROM vendas v WHERE v.empresa_id = ? AND '.ConsultasPaineis::condicaoFacturacao('v')." AND v.data_emissao >= ? AND v.data_emissao < ?{$fs}";
        $pb = array_merge([$empresa, $p->inicioAno, ConsultasPaineis::diaSeguinte($p->fimMes)], $fp);

        $top = DB::select("SELECT v.cliente_id, COALESCE(MAX(t.nome), 'Consumidor final') AS cliente, SUM({$valor}) AS total
            FROM vendas v LEFT JOIN terceiros t ON t.id = v.cliente_id
            WHERE v.empresa_id = ? AND ".ConsultasPaineis::condicaoFacturacao('v')." AND v.data_emissao >= ? AND v.data_emissao < ?{$fs}
            GROUP BY v.cliente_id ORDER BY total DESC LIMIT 5", $pb);
        $porTipo = DB::select('SELECT v.tipo_documento, SUM('.ConsultasPaineis::valorVenda('v', $f, false).") AS total {$base} GROUP BY 1 ORDER BY total DESC LIMIT 6", $pb);
        $moedas = DB::select("SELECT v.codigo_moeda AS moeda, COUNT(*) AS documentos,
                SUM(CASE WHEN v.tipo_documento = 'NC' THEN -1 ELSE 1 END * COALESCE(".(($f['iva'] ?? 'sem') === 'com' ? 'v.total_bruto_moeda' : 'v.total_liquido_moeda').", 0)) AS total_moeda,
                SUM({$valor}) AS contravalor {$base} AND v.codigo_moeda IS NOT NULL AND v.codigo_moeda <> 'AOA' GROUP BY 1 ORDER BY 1", $pb);
        $abertos = (int) DB::selectOne("SELECT COUNT(*) AS n FROM vendas v WHERE v.empresa_id = ? AND v.tipo_documento IN ('PF', 'NE', 'OR')
            AND COALESCE(v.estado, '') NOT IN ('ANULADO', 'CANCELADO', 'CONCLUIDO', 'CONVERTIDO', 'FATURADO', 'FECHADO'){$fs}", array_merge([$empresa], $fp))->n;
        $saldoClientes = $this->consultas->saldos($p->fimMes, ['cli' => ['31']], $f)['cli'];

        $tabelas = [Indicadores::tabela('top_clientes', "Top 5 Clientes ({$p->ano})", [['cliente', 'Cliente'], ['total', 'Facturação', 'kz']],
            array_map(fn ($r) => ['cliente_id' => $r->cliente_id, 'cliente' => $r->cliente, 'total' => Indicadores::dinheiro($r->total)], $top))];
        if ($moedas) {
            $tabelas[] = Indicadores::tabela('moeda_estrangeira', "Facturação em Moeda Estrangeira ({$p->ano})",
                [['moeda', 'Moeda'], ['documentos', 'Documentos', 'num'], ['total_moeda', 'Total na Moeda', 'moeda'], ['contravalor', 'Contravalor (Kz)', 'kz']],
                array_map(fn ($r) => ['moeda' => $r->moeda, 'documentos' => (int) $r->documentos, 'total_moeda' => Indicadores::dinheiro($r->total_moeda),
                    'contravalor' => Indicadores::dinheiro($r->contravalor)], $moedas));
        }

        return [
            'kpis' => [
                Indicadores::kpi('faturacao_mes', 'Facturação do Mês', $mes['total'], 'kz', 'Líquida de notas de crédito'),
                Indicadores::kpi('faturacao_ano', "Facturação {$p->ano}", $p->somaAno(array_map(fn ($x) => $x['total'], $porMes)), 'kz', 'Janeiro a '.$p->nomeMes()),
                Indicadores::kpi('faturas_mes', 'Facturas Emitidas (Mês)', $mes['faturas'], 'num'),
                Indicadores::kpi('valor_medio_fatura', 'Valor Médio por Factura', $mes['faturas'] ? bcdiv($mes['valor_faturas'], (string) $mes['faturas'], 2) : '0.00'),
                Indicadores::kpi('notas_credito_mes', 'Notas de Crédito (Mês)', $mes['notas_credito']),
                Indicadores::kpi('clientes_receber', 'Clientes a Receber', $saldoClientes, 'kz', 'Saldo contabilístico da conta 31'),
                Indicadores::kpi('por_converter', 'Proformas / Encomendas', $abertos, 'num', 'Por converter'),
            ],
            'graficos' => [
                Indicadores::grafico('faturacao_mensal', 'Facturação Mensal (12 meses)', 'barras', $p->rotulos(),
                    [Indicadores::serie('faturacao', 'Facturação', $p->serie(array_map(fn ($x) => $x['total'], $porMes)))]),
                Indicadores::grafico('por_tipo', "Facturação por Tipo de Documento ({$p->ano})", 'circular', array_map(fn ($r) => self::TIPOS[$r->tipo_documento] ?? $r->tipo_documento, $porTipo),
                    [Indicadores::serie('total', 'Total', array_map(fn ($r) => Indicadores::dinheiro($r->total), $porTipo))]),
            ],
            'tabelas' => $tabelas,
            'atalhos' => [['rotulo' => 'Emitir Documento', 'vista' => 'vendas_faturacao'], ['rotulo' => 'Ponto de Venda', 'vista' => 'pos'], ['rotulo' => 'Relatórios de Vendas', 'vista' => 'vendas_relatorios']],
        ];
    }
}
