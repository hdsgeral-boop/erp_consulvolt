<?php

namespace App\Http\Requests\POS\Lavandaria;

use App\Http\Requests\POS\PedidoPOS;

/** Validação de LavandariaController::anular (regras e mensagens tal como estavam no controlador). */
final class AnularRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['lav_anular'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['motivo' => ['required', 'string', 'min:3', 'max:500']];
    }
}
