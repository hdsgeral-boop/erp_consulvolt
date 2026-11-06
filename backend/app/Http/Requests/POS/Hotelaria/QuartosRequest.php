<?php

namespace App\Http\Requests\POS\Hotelaria;

use App\Http\Requests\POS\PedidoPOS;

/** Validação de HotelariaController::quartos (regras e mensagens tal como estavam no controlador). */
final class QuartosRequest extends PedidoPOS
{
    private const VER = ['pos_hotelaria_view', 'hotel_estadias', 'hotel_checkout', 'hotel_anular', 'hotel_quartos'];

    protected function permissoes(): array
    {
        return [...self::VER];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['terminal_pos_id' => ['nullable', 'integer']];
    }
}
