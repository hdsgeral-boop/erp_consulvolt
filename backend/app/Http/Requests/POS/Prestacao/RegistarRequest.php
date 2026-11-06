<?php

namespace App\Http\Requests\POS\Prestacao;

use App\Http\Requests\POS\PedidoPOS;

/** Validação de PrestacaoContasController::registar (regras e mensagens tal como estavam no controlador). */
final class RegistarRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['pos_prestar'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'chave_item' => ['required', 'string', 'max:60'], 'data' => ['nullable', 'date_format:Y-m-d'],
            'conta_financeira' => ['nullable', 'string', 'max:20'], 'sessao_caixa_id' => ['nullable', 'integer'],
            'comissao' => ['nullable', 'numeric', 'min:0'], 'comissao_deduzida' => ['nullable', 'boolean'],
        ];
    }
}
