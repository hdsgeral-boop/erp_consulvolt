<?php

namespace App\Http\Requests\POS\Hotelaria;

use App\Http\Requests\POS\PedidoPOS;
use App\Models\EstadiaHotel;
use Illuminate\Validation\Rule;

/** Validação de HotelariaController::lista (regras e mensagens tal como estavam no controlador). */
final class ListaRequest extends PedidoPOS
{
    private const VER = ['pos_hotelaria_view', 'hotel_estadias', 'hotel_checkout', 'hotel_anular', 'hotel_quartos'];

    protected function permissoes(): array
    {
        return [...self::VER];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['estado' => ['nullable', Rule::in([EstadiaHotel::ABERTA, EstadiaHotel::FECHADA, EstadiaHotel::ANULADA])], 'terminal_pos_id' => ['nullable', 'integer'],
            'produto_quarto_id' => ['nullable', 'integer'], 'cliente_hospede_id' => ['nullable', 'integer'], 'de' => ['nullable', 'date'], 'ate' => ['nullable', 'date'],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:500'], 'pagina' => ['nullable', 'integer', 'min:1']];
    }
}
