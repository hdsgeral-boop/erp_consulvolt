<?php

namespace App\Http\Requests\POS\Lavandaria;

use App\Http\Requests\POS\PedidoPOS;

/** Validação de LavandariaController::alterarEstado (regras e mensagens tal como estavam no controlador). */
final class AlterarEstadoRequest extends PedidoPOS
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer'], 'estado' => ['required', 'in:EM_EXECUCAO,PRONTA,RECEBIDA,ANULADA'],
            'colaborador_id' => ['nullable', 'integer'], 'motivo' => ['nullable', 'string', 'max:500']];
    }
}
