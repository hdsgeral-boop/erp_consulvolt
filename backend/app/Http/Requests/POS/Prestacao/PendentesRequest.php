<?php

namespace App\Http\Requests\POS\Prestacao;

use App\Http\Requests\POS\PedidoPOS;

/** Validação de PrestacaoContasController::pendentes (regras e mensagens tal como estavam no controlador). */
final class PendentesRequest extends PedidoPOS
{
    private const VER = ['pos_prestacao_view', 'pos_prestacao', 'pos_prestar', 'pos_prestacao_anular'];

    protected function permissoes(): array
    {
        return [...self::VER];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['terminal_pos_id' => ['nullable', 'integer']];
    }
}
