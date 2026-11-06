<?php

namespace App\Http\Requests\POS\Lavandaria;

use App\Http\Requests\POS\PedidoPOS;

/** Validação de LavandariaController::servicos (regras e mensagens tal como estavam no controlador). */
final class ServicosRequest extends PedidoPOS
{
    private const VER = ['pos_lavandaria_view', 'lav_ordens', 'lav_receber', 'lav_anular', 'lav_tabelas', 'lav_dano_decidir', 'lav_dano_pagar'];

    protected function permissoes(): array
    {
        return [...self::VER];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['incluir_inativos' => ['nullable', 'boolean'], 'terminal_pos_id' => ['nullable', 'integer']];
    }
}
