<?php

namespace App\Http\Requests\POS\Pos;

use App\Http\Requests\POS\PedidoPOS;

/** Validação de POSController::guardarServicosLavTerminal (regras e mensagens tal como estavam no controlador). */
final class GuardarServicosLavTerminalRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['pos_terminais_gerir'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['servico_ids' => ['required', 'array'], 'servico_ids.*' => ['integer']];
    }
}
