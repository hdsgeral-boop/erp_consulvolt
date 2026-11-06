<?php

namespace App\Http\Requests\POS\Pos;

use App\Http\Requests\POS\PedidoPOS;

/** Validação de POSController::guardarDefinicoes (regras e mensagens tal como estavam no controlador). */
final class GuardarDefinicoesRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['pos_terminais_gerir'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['conta_sobra' => ['nullable', 'string', 'max:20'], 'conta_quebra' => ['nullable', 'string', 'max:20'], 'conta_operador' => ['nullable', 'string', 'max:20'],
            'tolerancia_desvio' => ['nullable', 'numeric', 'min:0'], 'codigo_diario' => ['nullable', 'string', 'max:10']];
    }
}
