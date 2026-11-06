<?php

namespace App\Http\Requests\POS\Armazem;

use App\Http\Requests\POS\PedidoPOS;

/** Validação de ParidadePOSArmazemController::acertar (regras e mensagens tal como estavam no controlador). */
final class AcertarRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['pos_armazem_acerto'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['armazem_id' => ['required', 'integer'], 'produtos' => ['nullable', 'array', 'min:1', 'max:1000'], 'produtos.*' => ['integer']];
    }
}
