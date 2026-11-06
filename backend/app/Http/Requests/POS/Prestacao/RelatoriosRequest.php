<?php

namespace App\Http\Requests\POS\Prestacao;

use App\Http\Requests\POS\PedidoPOS;

/** Validação de PrestacaoContasController::relatorios (regras e mensagens tal como estavam no controlador). */
final class RelatoriosRequest extends PedidoPOS
{
    private const RELATORIOS = ['pos_relatorios_view', 'pos_relatorios'];

    protected function permissoes(): array
    {
        return [...self::RELATORIOS];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['data_inicio' => ['nullable', 'date_format:Y-m-d'], 'data_fim' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:data_inicio'],
            'terminal_pos_id' => ['nullable', 'integer'], 'operador_id' => ['nullable', 'integer']];
    }
}
