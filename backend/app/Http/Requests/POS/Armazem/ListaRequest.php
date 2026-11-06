<?php

namespace App\Http\Requests\POS\Armazem;

use App\Http\Requests\POS\PedidoPOS;

/** Validação de POSArmazemController::lista (regras e mensagens tal como estavam no controlador). */
final class ListaRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['pos_armazem_picking', 'pos_armazem_expedir'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['armazem_id' => ['required', 'integer']];
    }
}
