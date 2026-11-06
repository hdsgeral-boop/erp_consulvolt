<?php

namespace App\Http\Requests\POS\Pos;

use App\Http\Requests\POS\PedidoPOS;

/** Validação de POSController::descontabilizar (regras e mensagens tal como estavam no controlador). */
final class DescontabilizarRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['pos_descontabilizar'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['motivo' => ['required', 'string', 'max:500']];
    }
}
