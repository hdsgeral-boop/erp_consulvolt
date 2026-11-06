<?php

namespace App\Http\Requests\POS\Lavandaria;

use App\Http\Requests\POS\PedidoPOS;

/** Validação de LavandariaController::entregar (regras e mensagens tal como estavam no controlador). */
final class EntregarRequest extends PedidoPOS
{
    private const PAGAMENTOS = ['pagamentos' => ['nullable', 'array'], 'pagamentos.*.meio_id' => ['required', 'string', 'max:40'],
        'pagamentos.*.valor' => ['required', 'numeric', 'gt:0'], 'pagamentos.*.referencia' => ['nullable', 'string', 'max:100']];

    protected function permissoes(): array
    {
        return ['lav_ordens'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['linhas' => ['required', 'array', 'min:1'], 'linhas.*' => ['integer'], 'valor' => ['nullable', 'numeric', 'min:0']] + self::PAGAMENTOS;
    }
}
