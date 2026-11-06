<?php

namespace App\Http\Requests\POS\Pos;

use App\Http\Requests\POS\PedidoPOS;
use App\Services\POS\ServicoContabilizacaoPOS;
use Illuminate\Validation\Rule;

/** Validação de POSController::deliberar (regras e mensagens tal como estavam no controlador). */
final class DeliberarRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['pos_desvio_deliberar'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['decisao' => ['required', Rule::in(ServicoContabilizacaoPOS::DECISOES)], 'nota' => ['nullable', 'string', 'max:1000']];
    }
}
