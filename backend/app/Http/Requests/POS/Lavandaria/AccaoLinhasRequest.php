<?php

namespace App\Http\Requests\POS\Lavandaria;

use App\Http\Requests\POS\PedidoPOS;

/** Validação de LavandariaController::accaoLinhas (regras e mensagens tal como estavam no controlador). */
final class AccaoLinhasRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['lav_ordens'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['accao' => ['required', 'in:INICIAR,PRONTA'], 'linha_id' => ['nullable', 'integer'], 'executado_por' => ['nullable', 'string', 'max:255']];
    }
}
