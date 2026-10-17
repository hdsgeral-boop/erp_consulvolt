<?php

namespace App\Http\Controllers\Api\Logistica;

use App\Http\Controllers\Controller;
use App\Models\MovimentoInventario;
use App\Models\RececaoCompra;
use App\Services\Logistica\ServicoArmazens;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Leituras de paridade da Logística com o sistema anterior (verificacao_visual/RELATORIO.md, secção 2):
 *  - recepções a validar com o n.º da encomenda, o fornecedor e o armazém (2.2 — renderRececoesPendentes, ui_warehouse.js:262) e a encomenda
 *    de cliente (NE) de origem, para «Gerar Guia de Saída para Cliente (Back-to-Back)» (ui_warehouse.js:331);
 *  - histórico de movimentos com o terceiro e o nome do armazém (2.4 — renderMovimentosTab, ui_warehouse.js:1226);
 *  - valor em stock por armazém (2.5 — renderArmazensTab, ui_warehouse.js:1378).
 * Só leitura; os modelos já filtram pela empresa activa (PertenceEmpresa) e as junções exigem a mesma empresa.
 */
final class ParidadeStockController extends Controller
{
    /** Situação da recepção vista do armazém: pendente (recebida, por validar), validada ou anulada. */
    private const SITUACOES = ['PENDENTE', 'VALIDADO', 'ANULADO'];

    public function rececoes(Request $r): JsonResponse
    {
        $this->exigir('armazem_rececoes_view');
        $f = $r->validate(['situacao' => ['nullable', 'in:'.implode(',', self::SITUACOES)], 'pesquisa' => ['nullable', 'string', 'max:100'],
            'data_inicio' => ['nullable', 'date_format:Y-m-d'], 'data_fim' => ['nullable', 'date_format:Y-m-d'],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:500'], 'pagina' => ['nullable', 'integer', 'min:1']]);

        // Os dados migrados ficaram com estado RECEBIDO e validado = true depois de validados: a situação usa as duas colunas
        // (o filtro «estado=RECEBIDO» de /compras/rececoes devolvia também as já validadas — 2.2.3).
        $situacao = "CASE WHEN rececoes_compra.estado = 'ANULADO' THEN 'ANULADO'
            WHEN rececoes_compra.estado = 'VALIDADO' OR COALESCE(rececoes_compra.validado, false) THEN 'VALIDADO' ELSE 'PENDENTE' END";
        $pesquisa = trim((string) ($f['pesquisa'] ?? ''));

        $pagina = RececaoCompra::query()
            ->leftJoin('encomendas_compra as e', fn ($j) => $j->on('e.id', '=', 'rececoes_compra.encomenda_compra_id')->on('e.empresa_id', '=', 'rececoes_compra.empresa_id'))
            ->leftJoin('terceiros as t', fn ($j) => $j->on('t.id', '=', 'e.fornecedor_id')->on('t.empresa_id', '=', 'rececoes_compra.empresa_id'))
            ->leftJoin('armazens as a', fn ($j) => $j->on('a.id', '=', 'rececoes_compra.armazem_id')->on('a.empresa_id', '=', 'rececoes_compra.empresa_id'))
            // back-to-back (ui_warehouse.js:331, order.source_sale_id): a encomenda de cliente (NE) de onde veio a compra — directa ou pelo pedido
            ->leftJoin('pedidos_compra as pc', fn ($j) => $j->on('pc.id', '=', 'e.pedido_compra_id')->on('pc.empresa_id', '=', 'rececoes_compra.empresa_id'))
            ->leftJoin('vendas as ne', fn ($j) => $j->on('ne.id', '=', DB::raw('COALESCE(e.venda_origem_id, pc.venda_origem_id)'))
                ->on('ne.empresa_id', '=', 'rececoes_compra.empresa_id')->where('ne.tipo_documento', '=', 'NE'))
            ->when($f['situacao'] ?? null, fn ($q, $v) => $q->whereRaw("({$situacao}) = ?", [$v]))
            ->when($f['data_inicio'] ?? null, fn ($q, $v) => $q->where('rececoes_compra.data', '>=', $v))
            ->when($f['data_fim'] ?? null, fn ($q, $v) => $q->where('rececoes_compra.data', '<=', $v))
            ->when($pesquisa !== '', function ($q) use ($pesquisa) {
                $termo = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $pesquisa).'%';
                $q->where(fn ($w) => $w->where('rececoes_compra.numero_entrega', 'ilike', $termo)->orWhere('rececoes_compra.numero_rececao', 'ilike', $termo)
                    ->orWhere('e.numero_encomenda', 'ilike', $termo)->orWhere('t.nome', 'ilike', $termo));
            })
            ->select(['rececoes_compra.id', 'rececoes_compra.data', 'rececoes_compra.numero_entrega', 'rececoes_compra.numero_rececao', 'rececoes_compra.encomenda_compra_id',
                'rececoes_compra.armazem_id', 'rececoes_compra.estado', 'rececoes_compra.validado', 'rececoes_compra.contabilizado', 'rececoes_compra.valor_total_kz',
                'e.numero_encomenda', 'e.fornecedor_id', 't.nome as fornecedor', 'a.nome as armazem', 'ne.id as encomenda_cliente_id', 'ne.numero_documento as encomenda_cliente'])
            ->selectRaw("{$situacao} as situacao")
            ->orderByDesc('rececoes_compra.data')->orderByDesc('rececoes_compra.id')
            ->paginate(perPage: (int) ($f['por_pagina'] ?? 50), page: (int) ($f['pagina'] ?? 1));

        return RespostaApi::paginado($pagina, null, 'Recepções de compras.');
    }

    public function movimentos(Request $r): JsonResponse
    {
        $this->exigir('armazem_movimentos_view', 'armazem_stock_view');
        $f = $r->validate(['produto_id' => ['nullable', 'integer'], 'armazem_id' => ['nullable', 'integer'], 'tipo' => ['nullable', 'in:ENTRADA,SAIDA,TRANSFERENCIA,AJUSTE'],
            'de' => ['nullable', 'date_format:Y-m-d'], 'ate' => ['nullable', 'date_format:Y-m-d'], 'referencia' => ['nullable', 'string', 'max:100'],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:500'], 'pagina' => ['nullable', 'integer', 'min:1']]);

        $m = 'movimentos_inventario';
        $pagina = MovimentoInventario::query()
            ->leftJoin('armazens as a', fn ($j) => $j->on('a.id', '=', "{$m}.armazem_id")->on('a.empresa_id', '=', "{$m}.empresa_id"))
            ->leftJoin('armazens as ac', fn ($j) => $j->on('ac.id', '=', "{$m}.armazem_contraparte_id")->on('ac.empresa_id', '=', "{$m}.empresa_id"))
            ->leftJoin('guias_saida as g', fn ($j) => $j->on('g.id', '=', "{$m}.documento_id")->where("{$m}.documento_tipo", '=', 'GUIA_SAIDA')->on('g.empresa_id', '=', "{$m}.empresa_id"))
            ->leftJoin('terceiros as t', fn ($j) => $j->on('t.id', '=', DB::raw("COALESCE({$m}.terceiro_id, g.terceiro_id)"))->on('t.empresa_id', '=', "{$m}.empresa_id"))
            ->when($f['produto_id'] ?? null, fn ($q, $v) => $q->where("{$m}.produto_id", $v))
            ->when($f['armazem_id'] ?? null, fn ($q, $v) => $q->where("{$m}.armazem_id", $v))
            ->when($f['tipo'] ?? null, fn ($q, $v) => $q->where("{$m}.tipo", $v))
            ->when($f['de'] ?? null, fn ($q, $v) => $q->where("{$m}.data", '>=', $v))
            ->when($f['ate'] ?? null, fn ($q, $v) => $q->where("{$m}.data", '<=', $v.' 23:59:59'))
            ->when($f['referencia'] ?? null, fn ($q, $v) => $q->where("{$m}.referencia", 'ilike', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $v).'%'))
            ->select(["{$m}.id", "{$m}.produto_id", "{$m}.armazem_id", "{$m}.tipo", "{$m}.quantidade", "{$m}.data", "{$m}.referencia", "{$m}.preco_unitario", "{$m}.sentido",
                "{$m}.valor", "{$m}.custo_medio_apos", "{$m}.documento_tipo", "{$m}.documento_id", "{$m}.armazem_contraparte_id", "{$m}.criado_por",
                'a.nome as armazem', 'ac.nome as armazem_contraparte', 't.id as terceiro_id', 't.nome as terceiro'])
            ->orderByDesc("{$m}.data")->orderByDesc("{$m}.id")
            ->paginate(perPage: (int) ($f['por_pagina'] ?? 50), page: (int) ($f['pagina'] ?? 1));

        return RespostaApi::paginado($pagina, null, 'Movimentos de stock.');
    }

    public function valorArmazens(ServicoArmazens $armazens): JsonResponse
    {
        $this->exigir('armazem_armazens_view', 'armazem_stock_view');
        $v = $armazens->valorizacao();

        return RespostaApi::sucesso(['total' => $v['total'], 'por_armazem' => array_map(fn ($a) => ['armazem_id' => $a['armazem_id'], 'valor' => $a['valor']], $v['por_armazem'])],
            'Valor em stock por armazém.');
    }
}
