<?php

namespace App\Http\Requests\POS\Lavandaria;

use App\Http\Requests\POS\PedidoPOS;

/** Validação de LavandariaController::colaboradores (regras e mensagens tal como estavam no controlador). */
final class ColaboradoresRequest extends PedidoPOS
{
    private const VER = ['pos_lavandaria_view', 'lav_ordens', 'lav_receber', 'lav_anular', 'lav_tabelas', 'lav_dano_decidir', 'lav_dano_pagar'];

    protected function permissoes(): array
    {
        return [...self::VER];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['todos' => ['nullable', 'boolean']];
    }
}
