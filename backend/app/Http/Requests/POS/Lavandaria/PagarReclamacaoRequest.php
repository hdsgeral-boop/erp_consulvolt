<?php

namespace App\Http\Requests\POS\Lavandaria;

use App\Http\Requests\POS\PedidoPOS;

/** Validação de LavandariaController::pagarReclamacao (regras e mensagens tal como estavam no controlador). */
final class PagarReclamacaoRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['lav_dano_pagar'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['data' => ['required', 'date_format:Y-m-d'], 'conta_financeira' => ['required', 'string', 'max:20']];
    }
}
