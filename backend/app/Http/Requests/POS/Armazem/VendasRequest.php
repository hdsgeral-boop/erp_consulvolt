<?php

namespace App\Http\Requests\POS\Armazem;

use App\Http\Requests\POS\PedidoPOS;

/** Validação de POSArmazemController::vendas (regras e mensagens tal como estavam no controlador). */
final class VendasRequest extends PedidoPOS
{
    private const VER = ['pos_armazem_view', 'pos_armazem_vender', 'pos_armazem_picking', 'pos_armazem_expedir'];

    protected function permissoes(): array
    {
        return [...self::VER];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['armazem_id' => ['nullable', 'integer'], 'de' => ['nullable', 'date'], 'ate' => ['nullable', 'date']];
    }
}
