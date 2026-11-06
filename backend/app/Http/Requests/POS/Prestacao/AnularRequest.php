<?php

namespace App\Http\Requests\POS\Prestacao;

use App\Http\Requests\POS\PedidoPOS;

/** Validação de PrestacaoContasController::anular (regras e mensagens tal como estavam no controlador). */
final class AnularRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['pos_prestacao_anular'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['motivo' => ['required', 'string', 'min:3', 'max:500']];
    }
}
