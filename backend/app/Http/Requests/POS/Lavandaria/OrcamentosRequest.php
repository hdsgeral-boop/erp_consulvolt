<?php

namespace App\Http\Requests\POS\Lavandaria;

use App\Http\Requests\POS\PedidoPOS;

/** Validação de LavandariaController::orcamentos (regras e mensagens tal como estavam no controlador). */
final class OrcamentosRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['lav_ordens'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['decisao' => ['required', 'in:APROVAR,RECUSAR'], 'canal' => ['nullable', 'in:Presencial,Telefone,WhatsApp,E-mail'], 'nota' => ['nullable', 'string', 'max:500'],
            'linhas' => ['required', 'array', 'min:1'], 'linhas.*.pedido_id' => ['required', 'integer'], 'linhas.*.linha_id' => ['required', 'integer'],
            'linhas.*.valor' => ['nullable', 'numeric', 'min:0']];
    }
}
