<?php

namespace App\Http\Requests\POS\Lavandaria;

use App\Http\Requests\POS\PedidoPOS;

/** Validação de LavandariaController::registarReclamacao (regras e mensagens tal como estavam no controlador). */
final class RegistarReclamacaoRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['lav_ordens'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['descricao' => ['required', 'string', 'max:2000'], 'valor_declarado' => ['nullable', 'numeric', 'min:0']];
    }
}
