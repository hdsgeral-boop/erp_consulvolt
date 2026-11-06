<?php

namespace App\Http\Requests\Imobiliario;

use App\Http\Requests\POS\PedidoPOS;

/** Criar (POST) e editar (PUT) um modelo de documento. Permissão imo_modelo_editar. O tipo, as variáveis e a unicidade são regras do serviço. */
final class GravarModeloRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['imo_modelo_editar'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['tipo' => ['nullable', 'string', 'max:14'], 'nome' => ['nullable', 'string', 'max:200'], 'assunto' => ['nullable', 'string', 'max:300'],
            'corpo' => ['nullable', 'string', 'max:100000'], 'activo' => ['nullable', 'boolean']];
    }
}
