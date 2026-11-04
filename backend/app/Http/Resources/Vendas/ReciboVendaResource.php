<?php

namespace App\Http\Resources\Vendas;

use App\Models\ReciboVenda;
use App\Services\Vendas\ServicoRetencaoFonte;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ReciboVenda */
final class ReciboVendaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $retencoes = app(ServicoRetencaoFonte::class);

        return [
            'id' => $this->id,
            'numero_recibo' => $this->numero_recibo,
            'data' => $this->data?->toDateString(),
            'cliente_id' => $this->cliente_id,
            'cliente' => $this->whenLoaded('cliente', fn () => ['id' => $this->cliente?->id, 'nome' => $this->cliente?->nome, 'nif' => $this->cliente?->nif]),
            'montante_total' => $this->montante_total,
            // retenção na fonte do cliente: o recibo liquida pelo bruto (montante_total); em caixa/banco entra o líquido
            'valor_retido' => $retencoes->valorRetido($this->resource),
            'valor_liquido' => $retencoes->valorLiquido($this->resource),
            'retencao' => $retencoes->paraApi($this->resource),
            'meio_pagamento' => $this->meio_pagamento,
            'codigo_conta' => $this->codigo_conta,
            'referencia_pagamento' => $this->referencia_pagamento,
            'estado' => $this->estado ?? 'EMITIDO',
            // M-18: NORMAL ou ADIANTAMENTO (coluna da ronda 2; lida em bruto até o model ser regenerado)
            'tipo_recibo' => $this->resource->getAttributes()['tipo_recibo'] ?? 'NORMAL',
            'referencia' => $this->referencia,
            'contabilizado' => (bool) $this->contabilizado,
            'numero_lan_contabilizacao' => $this->numero_lan_contabilizacao,
            'venda_origem_id' => $this->venda_origem_id,
            'anulado_em' => $this->anulado_em?->toIso8601String(),
            'motivo_anulacao' => $this->motivo_anulacao,
            'anulado_por' => $this->getAttribute('anulado_por'),
            'alocacoes' => $this->whenLoaded('itensReciboVenda', fn () => $this->itensReciboVenda->map(fn ($i) => [
                'venda_id' => $i->venda_id, 'numero_documento' => $i->venda?->numero_documento, 'montante' => $i->montante_pago,
                'data_alocacao' => $i->getAttributes()['data_alocacao'] ?? null, 'numero_lan' => $i->getAttributes()['numero_lan_contabilizacao'] ?? null,
            ])->values()),
        ];
    }
}
