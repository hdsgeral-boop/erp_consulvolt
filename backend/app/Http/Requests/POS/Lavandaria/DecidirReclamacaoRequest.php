<?php

namespace App\Http\Requests\POS\Lavandaria;

use App\Http\Requests\POS\PedidoPOS;

/** Validação de LavandariaController::decidirReclamacao (regras e mensagens tal como estavam no controlador). */
final class DecidirReclamacaoRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['lav_dano_decidir'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['decisao' => ['required', 'in:COMPROVADO,APROVADA,RECUSADA'], 'referencia_comprovativo' => ['nullable', 'string', 'max:255'],
            'data_comprovativo' => ['nullable', 'date_format:Y-m-d'], 'valor_comprovativo' => ['nullable', 'numeric', 'min:0'], 'nota_decisao' => ['nullable', 'string', 'max:255']];
    }
}
