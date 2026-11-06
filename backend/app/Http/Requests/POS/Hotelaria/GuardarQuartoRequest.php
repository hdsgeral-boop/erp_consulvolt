<?php

namespace App\Http\Requests\POS\Hotelaria;

use App\Http\Requests\POS\PedidoPOS;

/** Validação de HotelariaController::guardarQuarto (regras e mensagens tal como estavam no controlador). */
final class GuardarQuartoRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['hotel_quartos'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['e_quarto' => ['sometimes', 'boolean'], 'preco_por_hora' => ['nullable', 'numeric', 'min:0'], 'preco_por_dia' => ['nullable', 'numeric', 'min:0'],
            'horas_minimas' => ['nullable', 'numeric', 'min:0']];
    }
}
