<?php

namespace App\Http\Resources\Vendas;

use App\Models\ReciboVenda;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ReciboVenda */
final class ReciboVendaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'numero_recibo' => $this->numero_recibo,
            'data' => $this->data?->toDateString(),
            'cliente_id' => $this->cliente_id,
            'cliente' => $this->whenLoaded('cliente', fn () => ['id' => $this->cliente?->id, 'nome' => $this->cliente?->nome, 'nif' => $this->cliente?->nif]),
            'montante_total' => $this->montante_total,
            'meio_pagamento' => $this->meio_pagamento,
            'codigo_conta' => $this->codigo_conta,
            'referencia_pagamento' => $this->referencia_pagamento,
            'estado' => $this->estado ?? 'EMITIDO',
            'contabilizado' => (bool) $this->contabilizado,
            'numero_lan_contabilizacao' => $this->numero_lan_contabilizacao,
            'venda_origem_id' => $this->venda_origem_id,
            'anulado_em' => $this->anulado_em?->toIso8601String(),
            'motivo_anulacao' => $this->motivo_anulacao,
            'alocacoes' => $this->whenLoaded('itensReciboVenda', fn () => $this->itensReciboVenda->map(fn ($i) => [
                'venda_id' => $i->venda_id, 'numero_documento' => $i->venda?->numero_documento, 'montante' => $i->montante_pago,
            ])->values()),
        ];
    }
}
