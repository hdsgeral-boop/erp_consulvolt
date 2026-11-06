<?php

namespace App\Http\Requests\POS\Pos;

use App\Http\Requests\POS\PedidoPOS;

/** «IVA em falta» da integração (F-112): conta de IVA liquidado a gravar nos artigos sem conta e/ou como conta por omissão. */
final class IvaEmFaltaRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['pos_integrar'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['conta_iva' => ['required', 'string', 'max:20'], 'produto_ids' => ['nullable', 'array', 'max:200'], 'produto_ids.*' => ['integer', 'min:1', 'distinct'],
            'definir_omissao' => ['nullable', 'boolean']];
    }
}
