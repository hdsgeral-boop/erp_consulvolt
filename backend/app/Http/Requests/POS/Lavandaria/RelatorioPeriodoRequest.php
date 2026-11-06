<?php

namespace App\Http\Requests\POS\Lavandaria;

use App\Http\Requests\POS\PedidoPOS;

/** Validação de LavandariaController::relatorioPeriodo (regras e mensagens tal como estavam no controlador). */
final class RelatorioPeriodoRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['pos_lavandaria_view', 'pos_relatorios_view', 'lav_ordens'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['de' => ['required', 'date_format:Y-m-d'], 'ate' => ['required', 'date_format:Y-m-d'], 'terminal_pos_id' => ['nullable', 'integer']];
    }
}
