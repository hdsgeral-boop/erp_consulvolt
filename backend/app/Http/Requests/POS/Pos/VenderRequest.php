<?php

namespace App\Http\Requests\POS\Pos;

use App\Http\Requests\POS\PedidoPOS;

/** Validação de POSController::vender (regras e mensagens tal como estavam no controlador). */
final class VenderRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['pos_venda'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cliente_id' => ['nullable', 'integer'], 'observacoes' => ['nullable', 'string', 'max:2000'], 'percentagem_desconto' => ['nullable', 'numeric', 'between:0,100'],
            'linhas' => ['required', 'array', 'min:1', 'max:1000'], 'linhas.*.produto_id' => ['required', 'integer'], 'linhas.*.quantidade' => ['required', 'numeric', 'gt:0'],
            'linhas.*.preco_unitario' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'], 'linhas.*.descricao' => ['nullable', 'string', 'max:500'],
            'pagamentos' => ['required', 'array', 'min:1', 'max:50'], 'pagamentos.*.meio_id' => ['required', 'string', 'max:40'], 'pagamentos.*.valor' => ['required', 'numeric', 'gt:0'],
            'pagamentos.*.referencia' => ['nullable', 'string', 'max:100'],
        ];
    }
}
