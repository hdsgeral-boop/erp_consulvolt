<?php

namespace App\Http\Requests\POS\Pos;

use App\Http\Requests\POS\PedidoPOS;

/** Validação de POSController::anularDeliberacao (regras e mensagens tal como estavam no controlador). */
final class AnularDeliberacaoRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['pos_desvio_deliberar'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['motivo' => ['required', 'string', 'max:500']];
    }
}
