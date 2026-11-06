<?php

namespace App\Http\Requests\Imobiliario;

use App\Http\Requests\POS\PedidoPOS;
use App\Services\Imobiliario\CatalogoImobiliario;
use Illuminate\Validation\Rule;

/** Mudar o estado de uma fracção (POST /fracoes/{id}/estado). Permissão imo_estado_mudar. As transições e o motivo são regras do serviço. */
final class MudarEstadoFracaoRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['imo_estado_mudar'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['estado' => ['required', Rule::in(array_keys(CatalogoImobiliario::ESTADOS_FRACAO))], 'motivo' => ['nullable', 'string', 'max:300']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['estado.required' => 'Escolha o novo estado.', 'estado.in' => 'Estado desconhecido.'];
    }
}
