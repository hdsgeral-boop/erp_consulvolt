<?php

namespace App\Http\Requests\POS\Prestacao;

use App\Http\Requests\POS\PedidoPOS;
use App\Services\POS\ServicoPrestacaoContasPOS;
use Illuminate\Validation\Rule;

/** Validação de PrestacaoContasController::liquidacoes (regras e mensagens tal como estavam no controlador). */
final class LiquidacoesRequest extends PedidoPOS
{
    private const VER = ['pos_prestacao_view', 'pos_prestacao', 'pos_prestar', 'pos_prestacao_anular'];

    protected function permissoes(): array
    {
        return [...self::VER];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['sessao_pos_id' => ['nullable', 'integer'], 'estado' => ['nullable', 'in:REGISTADO,ANULADO'],
            'natureza_registo' => ['nullable', Rule::in(ServicoPrestacaoContasPOS::NATUREZAS)], 'alvo' => ['nullable', 'in:FOLHA_CAIXA,TESOURARIA'],
            'data_inicio' => ['nullable', 'date_format:Y-m-d'], 'data_fim' => ['nullable', 'date_format:Y-m-d'],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:500'], 'pagina' => ['nullable', 'integer', 'min:1']];
    }
}
