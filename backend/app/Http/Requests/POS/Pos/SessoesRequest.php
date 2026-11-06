<?php

namespace App\Http\Requests\POS\Pos;

use App\Http\Requests\POS\PedidoPOS;

/** Validação de POSController::sessoes (regras e mensagens tal como estavam no controlador). */
final class SessoesRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['pos_view', 'pos_relatorios_view', 'pos_integracao_view', 'pos_desvios_view', 'pos_prestacao_view', 'pos_venda'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['terminal_pos_id' => ['nullable', 'integer'], 'estado' => ['nullable', 'in:ABERTA,FECHADA'],
            'estado_contabilizacao' => ['nullable', 'string', 'max:20'], 'estado_desvio' => ['nullable', 'string', 'max:20'], 'estado_liquidacao' => ['nullable', 'string', 'max:20'],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:500'], 'pagina' => ['nullable', 'integer', 'min:1']];
    }
}
