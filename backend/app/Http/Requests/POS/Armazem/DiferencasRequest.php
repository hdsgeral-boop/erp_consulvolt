<?php

namespace App\Http\Requests\POS\Armazem;

use App\Http\Requests\POS\PedidoPOS;

/** Validação de ParidadePOSArmazemController::diferencas (regras e mensagens tal como estavam no controlador). */
final class DiferencasRequest extends PedidoPOS
{
    private const VER = ['pos_armazem_view', 'pos_armazem_vender', 'pos_armazem_picking', 'pos_armazem_expedir', 'pos_armazem_acerto'];

    protected function permissoes(): array
    {
        return [...self::VER];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['armazem_id' => ['required', 'integer']];
    }
}
