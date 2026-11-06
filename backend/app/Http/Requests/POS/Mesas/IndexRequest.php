<?php

namespace App\Http\Requests\POS\Mesas;

use App\Http\Requests\POS\PedidoPOS;

/** Validação de MesasPOSController::index (regras e mensagens tal como estavam no controlador). */
final class IndexRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['pos_venda', 'pos_terminais_gerir', 'pos_terminais_view', 'pos_view'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['incluir_inactivas' => ['nullable', 'boolean']];
    }
}
