<?php

namespace App\Http\Requests\POS\Hotelaria;

use App\Http\Requests\POS\PedidoPOS;

/** Validação de HotelariaController::alterar (regras e mensagens tal como estavam no controlador). */
final class AlterarRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['hotel_estadias'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cliente_hospede_id' => ['nullable', 'integer'], 'entrada_em' => ['nullable', 'date'], 'quantidade' => ['nullable', 'numeric', 'gt:0'],
            'preco_unitario' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'], 'numero_hospedes' => ['nullable', 'integer', 'min:1'], 'observacoes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
