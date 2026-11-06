<?php

namespace App\Http\Requests\POS\Armazem;

use App\Http\Requests\POS\PedidoPOS;

/** Validação de POSArmazemController::expedir (regras e mensagens tal como estavam no controlador). */
final class ExpedirRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['pos_armazem_expedir'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['armazem_id' => ['required', 'integer'], 'observacoes' => ['nullable', 'string', 'max:2000'],
            'recolhidos' => ['present', 'array', 'max:2000'], 'recolhidos.*' => ['integer', 'distinct']];
    }
}
