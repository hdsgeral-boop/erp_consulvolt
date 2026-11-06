<?php

namespace App\Http\Requests\POS\Pos;

use App\Http\Requests\POS\PedidoPOS;

/** Suprimento ou sangria da caixa (F1b): permissão própria `pos_movimento_caixa`. */
final class MovimentoCaixaRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['pos_movimento_caixa'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'tipo' => ['required', 'in:SUPRIMENTO,SANGRIA'],
            'valor' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'motivo' => ['required', 'string', 'min:3', 'max:300'],
        ];
    }
}
