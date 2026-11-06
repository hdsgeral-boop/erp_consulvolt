<?php

namespace App\Http\Requests\POS\Pos;

use App\Http\Requests\POS\PedidoPOS;

/** Validação de POSController::copiarMeios (regras e mensagens tal como estavam no controlador). */
final class CopiarMeiosRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['pos_terminais_gerir'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['terminal_origem_id' => ['required', 'integer'], 'modo' => ['required', 'in:SUBSTITUIR,ACRESCENTAR'], 'empresa_origem_id' => ['nullable', 'integer']];
    }
}
