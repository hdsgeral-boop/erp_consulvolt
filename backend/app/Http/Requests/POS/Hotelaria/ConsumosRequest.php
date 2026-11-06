<?php

namespace App\Http\Requests\POS\Hotelaria;

use App\Http\Requests\POS\PedidoPOS;

/** Validação de HotelariaController::consumos (regras e mensagens tal como estavam no controlador). */
final class ConsumosRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['hotel_estadias', 'pos_venda'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['linhas' => ['present', 'array'], 'linhas.*.produto_id' => ['required', 'integer'], 'linhas.*.quantidade' => ['required', 'numeric', 'gt:0'],
            'linhas.*.preco_unitario' => ['nullable', 'numeric', 'decimal:0,2', 'min:0']];
    }
}
