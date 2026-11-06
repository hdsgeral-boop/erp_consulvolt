<?php

namespace App\Http\Requests\POS\Lavandaria;

use App\Http\Requests\POS\PedidoPOS;

/** Validação de LavandariaController::material (regras e mensagens tal como estavam no controlador). */
final class MaterialRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['lav_ordens'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['produto_id' => ['required', 'integer'], 'quantidade' => ['required', 'numeric', 'gt:0']];
    }
}
