<?php

namespace App\Services\POS;

use App\Models\PedidoLavandaria;
use App\Services\POS\Lavandaria\ServicoCaixaLavandaria;
use App\Services\Vendas\CalculadoraDocumento;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;

/**
 * Pós-carga da lavandaria (ETL): os JSON do legado (lav_orders.items/extras/pickup/delivery/history/assignments,
 * lav_payments.pos_payments, lav_pieces.service_prices) vêm com chaves em inglês; passam às chaves portuguesas lidas pelos
 * serviços da lavandaria. Idempotente (só traduz chaves conhecidas; só preenche o que falta). Também:
 *   - `valor` de cada linha/taxa = qtd × preço do legado (valorItem, js/lavandaria.js:126), quando falta;
 *   - linhas das facturas de lavandaria do legado com preço COM IVA e sem valor líquido (total_linha nulo): o valor líquido de
 *     cada linha é total / (1 + IVA), acertado ao total líquido do cabeçalho — sem isto a integração lançava o IVA em proveitos;
 *   - pago/pendente/estado das facturas das ordens recalculados a partir dos recebimentos da lavandaria (actualizarFacturas).
 */
final class ServicoMigracaoLavandaria
{
    private const ITEM = ['line_id' => 'linha_id', 'piece_id' => 'peca_id', 'piece_code' => 'codigo_peca', 'piece_name' => 'nome_peca', 'piece_desc' => 'descricao_peca',
        'color' => 'cor', 'fabric' => 'tecido', 'unit' => 'unidade', 'product_id' => 'produto_id', 'service_code' => 'codigo_servico', 'name' => 'nome', 'group' => 'grupo',
        'qty' => 'quantidade', 'pieces' => 'numero_pecas', 'price' => 'preco', 'tax_rate' => 'taxa_imposto', 'lead_days' => 'dias_entrega', 'entry_state' => 'estado_entrada',
        'entry_notes' => 'notas_entrada', 'condition' => 'condicao', 'declared_value' => 'valor_declarado', 'quote_required' => 'requer_orcamento',
        'quote_status' => 'estado_orcamento', 'quote_amount' => 'valor_orcamento', 'quote_channel' => 'canal_orcamento', 'quote_note' => 'nota_orcamento',
        'quote_approved_at' => 'orcamento_aprovado_em', 'quote_approved_by' => 'orcamento_aprovado_por', 'quote_refused_at' => 'orcamento_recusado_em',
        'quote_refused_by' => 'orcamento_recusado_por', 'cancel_reason' => 'motivo_cancelamento', 'status' => 'estado', 'tags' => 'etiquetas', 'materials' => 'materiais',
        'started_at' => 'iniciado_em', 'assigned_to' => 'executado_por', 'ready_at' => 'pronta_em', 'delivered_at' => 'entregue_em', 'delivered_by' => 'entregue_por',
        'invoice_sale_id' => 'venda_id'];

    private const MATERIAL = ['product_id' => 'produto_id', 'name' => 'nome', 'qty' => 'quantidade', 'at' => 'em', 'by' => 'por'];

    private const EXTRA = ['key' => 'chave', 'product_id' => 'produto_id', 'name' => 'nome', 'amount' => 'valor', 'tax_rate' => 'taxa_imposto', 'invoice_sale_id' => 'venda_id',
        'cancelled' => 'cancelado'];

    private const DOMICILIO = ['enabled' => 'ativa', 'address' => 'morada', 'date' => 'data', 'fee' => 'taxa'];

    private const HISTORICO = ['at' => 'em', 'by' => 'por', 'text' => 'texto'];

    private const ATRIBUICAO = ['employee_id' => 'colaborador_id', 'name' => 'nome', 'assigned_at' => 'atribuido_em', 'at' => 'em', 'by' => 'por', 'note' => 'nota'];

    private const PRECO_SERVICO = ['product_id' => 'produto_id', 'price' => 'preco'];

    private const PAGAMENTO = ['pm_id' => 'meio_id', 'kind' => 'tipo', 'name' => 'nome', 'amount' => 'valor', 'received' => 'recebido', 'reference' => 'referencia',
        'transit_account' => 'conta_transitoria', 'settlement_account' => 'conta_liquidacao', 'tpa_code' => 'codigo_tpa'];

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoCaixaLavandaria $caixa,
    ) {}

    /** @return array{pedidos: int, pagamentos: int, pecas: int, linhas_faturas: int, faturas: int} */
    public function normalizar(): array
    {
        $r = ['pedidos' => 0, 'pagamentos' => 0, 'pecas' => 0, 'linhas_faturas' => 0, 'faturas' => 0];
        $lista = fn (?string $j, array $mapa) => array_map(fn ($x) => self::traduzir($x, $mapa), json_decode((string) $j, true) ?: []);
        foreach (DB::table('pedidos_lavandaria')->orderBy('id')->get(['id', 'itens', 'extras', 'recolha', 'entrega', 'historico_alteracoes', 'atribuicoes']) as $p) {
            $itens = array_map(function ($i) {
                $i = self::traduzir($i, self::ITEM);
                $i['materiais'] = array_map(fn ($m) => self::traduzir($m, self::MATERIAL), $i['materiais'] ?? []);
                $i['unidade'] ??= 'PECA';
                if (! array_key_exists('valor', $i)) {   // valorItem do legado: qtd × preço
                    $i['valor'] = CalculadoraDocumento::arredondar(bcmul(number_format((float) ($i['quantidade'] ?? 0), 3, '.', ''), number_format((float) ($i['preco'] ?? 0), 2, '.', ''), 8));
                }

                return $i;
            }, json_decode((string) $p->itens, true) ?: []);
            $extras = array_map(function ($e) {
                $e = self::traduzir($e, self::EXTRA);
                $e['valor'] = number_format((float) ($e['valor'] ?? 0), 2, '.', '');

                return $e;
            }, json_decode((string) $p->extras, true) ?: []);
            $objeto = fn (?string $j) => $j === null ? null : json_encode(self::traduzir(json_decode($j, true) ?: [], self::DOMICILIO));
            DB::table('pedidos_lavandaria')->where('id', $p->id)->update(['itens' => json_encode($itens), 'extras' => json_encode($extras),
                'recolha' => $objeto($p->recolha), 'entrega' => $objeto($p->entrega),
                'historico_alteracoes' => json_encode($lista($p->historico_alteracoes, self::HISTORICO)), 'atribuicoes' => json_encode($lista($p->atribuicoes, self::ATRIBUICAO))]);
            $r['pedidos']++;
        }
        foreach (DB::table('pagamentos_lavandaria')->whereNotNull('pos_pagamentos')->get(['id', 'pos_pagamentos']) as $p) {
            DB::table('pagamentos_lavandaria')->where('id', $p->id)->update(['pos_pagamentos' => json_encode($lista($p->pos_pagamentos, self::PAGAMENTO))]);
            $r['pagamentos']++;
        }
        foreach (DB::table('pecas_lavandaria')->whereNotNull('precos_servico')->get(['id', 'precos_servico']) as $p) {
            DB::table('pecas_lavandaria')->where('id', $p->id)->update(['precos_servico' => json_encode($lista($p->precos_servico, self::PRECO_SERVICO))]);
            $r['pecas']++;
        }
        $r['linhas_faturas'] = $this->valorLiquidoLinhasLegado();
        foreach (DB::table('pedidos_lavandaria')->orderBy('id')->get(['id', 'empresa_id']) as $p) {
            $this->contexto->executarComo((int) $p->empresa_id, fn () => $this->caixa->actualizarFaturas(PedidoLavandaria::query()->findOrFail($p->id)));
            $r['faturas']++;
        }

        return $r;
    }

    /** Valor líquido das linhas das facturas de lavandaria e das vendas POS do legado (preço com IVA, total_linha nulo), acertado ao cabeçalho. */
    private function valorLiquidoLinhasLegado(): int
    {
        $n = 0;
        $vendas = DB::table('vendas')->where(fn ($q) => $q->whereNotNull('pedido_lavandaria_id')->orWhereNotNull('sessao_pos_id')->orWhereNotNull('sessao_pos_legado_codigo'))
            ->whereIn('tipo_documento', ['FT', 'FR'])
            ->whereExists(fn ($q) => $q->from('itens_venda as i')->whereColumn('i.venda_id', 'vendas.id')->whereNull('i.total_linha'))->get(['id', 'total_liquido']);
        foreach ($vendas as $v) {
            $itens = DB::table('itens_venda')->where('venda_id', $v->id)->orderBy('id')->get(['id', 'total', 'taxa_imposto', 'total_linha', 'fe_selado']);
            if ($itens->contains(fn ($i) => $i->total_linha !== null || $i->fe_selado)) {
                continue;   // documento já tratado ou selado: não se mexe
            }
            $bases = $itens->map(fn ($i) => CalculadoraDocumento::arredondar(bcdiv((string) $i->total, bcadd('1', bcdiv((string) $i->taxa_imposto, '100', 8), 8), 8)))->all();
            if ($v->total_liquido !== null && $bases) {   // o arredondamento acerta-se na última linha, para bater com o total líquido do documento
                $dif = bcsub((string) $v->total_liquido, array_reduce($bases, fn ($s, $b) => bcadd($s, $b, 2), '0.00'), 2);
                $bases[count($bases) - 1] = bcadd($bases[count($bases) - 1], $dif, 2);
            }
            foreach ($itens->values() as $k => $i) {
                DB::table('itens_venda')->where('id', $i->id)->update(['total_linha' => $bases[$k]]);
                $n++;
            }
        }

        return $n;
    }

    /** Traduz as chaves conhecidas; as já traduzidas ou desconhecidas ficam como estão (idempotente). */
    private static function traduzir(mixed $x, array $mapa): mixed
    {
        if (! is_array($x)) {
            return $x;
        }
        $saida = [];
        foreach ($x as $k => $v) {
            $saida[$mapa[$k] ?? $k] = $v;
        }

        return $saida;
    }
}
