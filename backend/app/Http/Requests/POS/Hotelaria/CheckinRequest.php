<?php

namespace App\Http\Requests\POS\Hotelaria;

use App\Http\Requests\POS\PedidoPOS;
use App\Models\EstadiaHotel;
use Illuminate\Validation\Rule;

/** Validação de HotelariaController::checkin (regras e mensagens tal como estavam no controlador). */
final class CheckinRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['hotel_estadias'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'produto_quarto_id' => ['required', 'integer'], 'cliente_hospede_id' => ['required', 'integer'], 'modo' => ['required', Rule::in(EstadiaHotel::MODOS)],
            'entrada_em' => ['nullable', 'date'], 'quantidade' => ['required', 'numeric', 'gt:0'], 'preco_unitario' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            'numero_hospedes' => ['nullable', 'integer', 'min:1'], 'observacoes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
