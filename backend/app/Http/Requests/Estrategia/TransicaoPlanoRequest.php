<?php

namespace App\Http\Requests\Estrategia;

use App\Http\Requests\POS\PedidoPOS;

/** Aprovar, iniciar a execução, reabrir ou encerrar um plano. Permissão est_plano_aprovar (tarefa sensível). */
final class TransicaoPlanoRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['est_plano_aprovar'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'motivo' => ['nullable', 'string', 'max:300'],
            'excepcao' => ['nullable', 'string', 'max:300'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [

        ];
    }
}
