<?php

namespace App\Http\Requests\POS\Armazem;

use App\Http\Requests\POS\PedidoPOS;

/** Validação de POSArmazemController::vender (regras e mensagens tal como estavam no controlador). */
final class VenderRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['pos_armazem_vender'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['armazem_id' => ['required', 'integer'], 'terceiro_id' => ['nullable', 'integer'], 'observacoes' => ['nullable', 'string', 'max:2000'],
            'linhas' => ['required', 'array', 'min:1'], 'linhas.*.produto_id' => ['required', 'integer'], 'linhas.*.quantidade' => ['required', 'numeric', 'gt:0']];
    }
}
