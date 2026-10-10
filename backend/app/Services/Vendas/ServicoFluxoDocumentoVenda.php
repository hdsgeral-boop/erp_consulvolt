<?php

namespace App\Services\Vendas;

use App\Models\EncomendaCompra;
use App\Models\ItemReciboVenda;
use App\Models\LancamentoContabil;
use App\Models\PedidoCompra;
use App\Models\RececaoCompra;
use App\Models\Venda;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * «Fluxo Completo do Processo» de um documento de venda (legado: window.showDocumentWorkflow, js/ui_sales.js:5758).
 * Só leituras, sempre na empresa do documento (Eloquent com EscopoEmpresa; DB::table filtrado por empresa_id).
 *   - vendas: sobe pelas origens e desce pelos derivados (vendas_documentos_relacionados: venda_id = gerado,
 *     venda_relacionada_id = origem). O legado tinha uma só origem (related_doc_id) e descia 2 níveis; aqui a FT
 *     pode ter várias guias de origem, por isso percorre o grafo todo (com limite);
 *   - recibos: alocações (itens_recibo_venda) a qualquer documento da cadeia, com o valor aplicado;
 *   - lançamentos: activos (não estornados) pelo n.º do lançamento; os do legado sem n.º pela referência
 *     "<tipo original> <n.º>" (documentos) ou "<n.º do recibo>" (recibos), como o legado;
 *   - aprovisionamento: pedido de compra com origem num documento da cadeia → encomenda → recepção.
 */
final class ServicoFluxoDocumentoVenda
{
    private const LIMITE_DOCUMENTOS = 200;

    public function fluxo(Venda $venda): array
    {
        $cadeia = $this->cadeia($venda);
        $recibos = $this->recibos($cadeia);

        return [
            'vendas' => $cadeia->map(fn (Venda $v) => [
                'id' => $v->id, 'tipo_documento' => $v->tipo_documento, 'numero_documento' => $v->numero_documento,
                'data_emissao' => $v->data_emissao?->toDateString(), 'estado' => $v->estado, 'total_bruto' => $v->total_bruto,
                'contabilizado' => (bool) $v->contabilizado, 'actual' => $v->id === $venda->id,
            ])->values()->all(),
            'recibos' => $recibos->map(fn ($r) => array_diff_key($r, ['_lans' => 1, '_contabilizado' => 1]))->values()->all(),
            'lancamentos' => $this->lancamentos($cadeia, $recibos),
            'aprovisionamento' => $this->aprovisionamento($cadeia, $venda),
        ];
    }

    /** Documento + todas as origens e todos os derivados, por data e id (como o legado). */
    private function cadeia(Venda $venda): Collection
    {
        $relacoes = DB::table('vendas_documentos_relacionados')->where('empresa_id', $venda->empresa_id);
        $ids = [$venda->id => true];
        foreach ([['venda_id', 'venda_relacionada_id'], ['venda_relacionada_id', 'venda_id']] as [$de, $para]) {
            $fronteira = [$venda->id];
            while ($fronteira && count($ids) < self::LIMITE_DOCUMENTOS) {
                $novos = (clone $relacoes)->whereIn($de, $fronteira)->pluck($para)->map(fn ($id) => (int) $id)
                    ->reject(fn ($id) => isset($ids[$id]))->unique()->values()->all();
                foreach ($novos as $id) {
                    $ids[$id] = true;
                }
                $fronteira = $novos;
            }
        }

        return Venda::query()->whereIn('id', array_keys($ids))->orderBy('data_emissao')->orderBy('id')->limit(self::LIMITE_DOCUMENTOS)
            ->get(['id', 'empresa_id', 'tipo_documento', 'tipo_documento_original', 'numero_documento', 'data_emissao', 'estado', 'total_bruto',
                'contabilizado', 'numero_lan_contabilizacao']);
    }

    /** Uma linha por alocação (recibo × documento da cadeia), como os receipt_items do legado. */
    private function recibos(Collection $cadeia): Collection
    {
        $numeros = $cadeia->pluck('numero_documento', 'id');

        return ItemReciboVenda::query()->whereIn('venda_id', $cadeia->pluck('id'))->with('reciboVenda')->orderBy('id')->get()
            ->filter(fn (ItemReciboVenda $i) => $i->reciboVenda !== null)
            ->map(fn (ItemReciboVenda $i) => [
                'id' => $i->reciboVenda->id, 'numero' => $i->reciboVenda->numero_recibo, 'data' => $i->reciboVenda->data?->toDateString(),
                'valor' => $i->montante_pago, 'venda_id' => $i->venda_id, 'numero_documento' => $numeros[$i->venda_id] ?? null,
                'estado' => $i->reciboVenda->estado,
                '_contabilizado' => (bool) $i->reciboVenda->contabilizado,
                '_lans' => array_values(array_filter([$i->reciboVenda->numero_lan_contabilizacao, $i->numero_lan_contabilizacao])),
            ])->values();
    }

    /** Lançamentos activos de cada documento/recibo contabilizado; um lançamento partilhado (FR + recibo) aparece uma vez. */
    private function lancamentos(Collection $cadeia, Collection $recibos): array
    {
        $alvos = [];   // [referência mostrada, n.º do lançamento | null, referência do legado | null]
        foreach ($cadeia as $v) {
            if (! $v->contabilizado) {
                continue;
            }
            $legado = $v->tipo_documento_original ? "{$v->tipo_documento_original} {$v->numero_documento}" : null;
            $alvos[] = ["{$v->tipo_documento} {$v->numero_documento}", $v->numero_lan_contabilizacao, $v->numero_lan_contabilizacao ? null : $legado];
        }
        foreach ($recibos->unique('id') as $r) {
            $lans = $r['_lans'];
            if (! $r['_contabilizado'] && ! $lans) {
                continue;
            }
            foreach ($lans ?: [null] as $lan) {
                $alvos[] = [$r['numero'], $lan, $lan ? null : $r['numero']];
            }
        }

        $resultado = [];
        $vistos = [];
        foreach ($alvos as [$referencia, $lan, $legado]) {
            $chave = $lan ? "L:{$lan}" : ($legado ? "R:{$legado}" : null);
            if ($chave === null || isset($vistos[$chave])) {
                continue;
            }
            $vistos[$chave] = true;
            $linhas = LancamentoContabil::query()->whereNull('estorno_de_id')->whereNull('estornado_por_id')
                ->when($lan, fn ($q) => $q->where('numero_lan', $lan), fn ($q) => $q->where('referencia', $legado))
                ->orderBy('id')->limit(500)->get(['codigo_conta', 'tipo_dc', 'valor']);
            if ($linhas->isEmpty()) {
                continue;
            }
            $resultado[] = [
                'referencia' => $referencia, 'numero_lan' => $lan,
                'linhas' => $linhas->map(fn ($l) => [
                    'conta' => $l->codigo_conta,
                    'debito' => $l->tipo_dc === 'D' ? $l->valor : null,
                    'credito' => $l->tipo_dc === 'C' ? $l->valor : null,
                ])->all(),
            ];
        }

        return $resultado;
    }

    /** Back-to-back: pedido de compra com origem no documento (ou noutro da cadeia) → encomenda → recepção. */
    private function aprovisionamento(Collection $cadeia, Venda $venda): array
    {
        // o legado procurava pelo documento e pela raiz da cadeia; aqui por qualquer documento, preferindo o actual
        $ids = $cadeia->pluck('id')->all();
        $pedido = PedidoCompra::query()->whereIn('venda_origem_id', $ids)
            ->orderByRaw('CASE WHEN venda_origem_id = ? THEN 0 ELSE 1 END', [$venda->id])->orderBy('id')->first();
        $encomenda = $pedido
            ? EncomendaCompra::query()->where('pedido_compra_id', $pedido->id)->orderBy('id')->first()
            : EncomendaCompra::query()->whereIn('venda_origem_id', $ids)->orderBy('id')->first();

        $passos = [];
        if ($pedido) {
            $passos[] = ['tipo' => 'Pedido de Compra', 'id' => $pedido->id, 'documento' => $pedido->numero_pedido ?: "#{$pedido->id}",
                'estado' => $pedido->estado, 'data' => $pedido->data?->toDateString()];
        }
        if ($encomenda) {
            $passos[] = ['tipo' => 'Ordem de Encomenda', 'id' => $encomenda->id, 'documento' => $encomenda->numero_encomenda ?: "#{$encomenda->id}",
                'estado' => $encomenda->estado, 'data' => $encomenda->data?->toDateString()];
            $rececao = RececaoCompra::query()->where('encomenda_compra_id', $encomenda->id)->orderBy('id')->first();
            if ($rececao) {
                $passos[] = ['tipo' => 'Receção Armazém', 'id' => $rececao->id, 'documento' => $rececao->numero_rececao ?: ($rececao->numero_entrega ?: "#{$rececao->id}"),
                    'estado' => $rececao->validado ? 'VALIDADO' : 'PENDENTE', 'data' => $rececao->data?->toDateString()];
            }
        }

        return $passos;
    }
}
