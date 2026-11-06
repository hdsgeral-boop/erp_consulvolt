<?php

namespace App\Http\Requests\POS\Pos;

use App\Http\Requests\POS\PedidoPOS;

/** Sessões a integrar (pré-visualização e contabilização em lote): ids únicos, máximo 50. */
final class IdsSessoesRequest extends PedidoPOS
{
    public const MAXIMO = 50;

    protected function permissoes(): array
    {
        return ['pos_integrar'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['ids' => ['required', 'array', 'min:1', 'max:'.self::MAXIMO], 'ids.*' => ['required', 'integer', 'min:1', 'distinct']];
    }
}
