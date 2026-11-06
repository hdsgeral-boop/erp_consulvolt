<?php

namespace App\Http\Requests\POS\Pos;

use App\Http\Requests\POS\PedidoPOS;

/** Validação de POSController::abrirSessao (regras e mensagens tal como estavam no controlador). */
final class AbrirSessaoRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['pos_venda'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['fundo_maneio' => ['nullable', 'numeric', 'min:0']];
    }
}
