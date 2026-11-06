<?php

namespace App\Http\Requests\Estrategia;

use App\Http\Requests\POS\PedidoPOS;

/** Entregar o valor de um indicador de recolha manual num período. Permissão est_entregar. */
final class GravarMedicaoRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['est_entregar'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'periodo' => ['required', 'string', 'max:8'],
            'valor' => ['required', 'numeric', 'between:-9999999999999999,9999999999999999'],
            'nota' => ['nullable', 'string', 'max:300'],
            'unidade_organica_id' => ['nullable', 'integer'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'periodo.required' => 'Indique o período a que o valor se refere.',
            'valor.required' => 'Indique o valor medido.',
            'valor.numeric' => 'O valor tem de ser um número.',
        ];
    }
}
