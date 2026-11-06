<?php

namespace App\Http\Requests\POS\Armazem;

use App\Http\Requests\POS\PedidoPOS;

/** Validação de ParidadePOSArmazemController::gerarSenha (regras e mensagens tal como estavam no controlador). */
final class GerarSenhaRequest extends PedidoPOS
{
    private const OPERAR_SENHAS = ['pos_armazem_vender', 'pos_armazem_picking', 'pos_armazem_expedir'];

    protected function permissoes(): array
    {
        return [...self::OPERAR_SENHAS];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['armazem_id' => ['required', 'integer']];
    }
}
