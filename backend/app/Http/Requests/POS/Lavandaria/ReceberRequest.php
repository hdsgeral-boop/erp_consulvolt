<?php

namespace App\Http\Requests\POS\Lavandaria;

use App\Http\Requests\POS\PedidoPOS;

/** Validação de LavandariaController::receber (regras e mensagens tal como estavam no controlador). */
final class ReceberRequest extends PedidoPOS
{
    private const PAGAMENTOS = ['pagamentos' => ['nullable', 'array'], 'pagamentos.*.meio_id' => ['required', 'string', 'max:40'],
        'pagamentos.*.valor' => ['required', 'numeric', 'gt:0'], 'pagamentos.*.referencia' => ['nullable', 'string', 'max:100']];

    protected function permissoes(): array
    {
        return ['lav_receber'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['valor' => ['required', 'numeric', 'gt:0'], 'pagamentos' => ['required', 'array', 'min:1']] + self::PAGAMENTOS;
    }
}
