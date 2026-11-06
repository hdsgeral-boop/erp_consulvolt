<?php

namespace App\Http\Requests\POS\Lavandaria;

use App\Http\Requests\POS\PedidoPOS;

/** Validação de LavandariaController::atribuir (regras e mensagens tal como estavam no controlador). */
final class AtribuirRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['lav_ordens'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer'], 'colaborador_id' => ['nullable', 'integer'], 'retirar' => ['nullable', 'boolean'],
            'data' => ['nullable', 'date_format:Y-m-d'], 'aplicar' => ['nullable', 'in:PENDENTES,NENHUM'], 'nota' => ['nullable', 'string', 'max:500']];
    }
}
