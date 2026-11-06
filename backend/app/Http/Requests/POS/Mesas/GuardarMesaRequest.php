<?php

namespace App\Http\Requests\POS\Mesas;

use App\Http\Requests\POS\PedidoPOS;

/** POST /terminais/{t}/mesas (criar: pos_terminais_gerir ou pos_venda — no legado o operador criava mesas no POS) e PUT …/{mesa} (alterar: pos_terminais_gerir). */
final class GuardarMesaRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return $this->route('mesa') ? ['pos_terminais_gerir'] : ['pos_terminais_gerir', 'pos_venda'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['nome' => ['required', 'string', 'max:60'], 'ordem' => ['nullable', 'integer', 'min:0', 'max:100000'], 'ativo' => ['nullable', 'boolean']];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['nome' => 'nome da mesa'];
    }
}
