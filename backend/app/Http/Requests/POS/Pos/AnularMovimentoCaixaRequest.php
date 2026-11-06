<?php

namespace App\Http\Requests\POS\Pos;

use App\Http\Requests\POS\PedidoPOS;

/** Anulação de um suprimento/sangria com a sessão aberta (F1b). */
final class AnularMovimentoCaixaRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['pos_movimento_caixa'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['motivo' => ['required', 'string', 'min:3', 'max:300']];
    }
}
