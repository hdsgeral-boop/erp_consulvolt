<?php

namespace App\Http\Requests\Estrategia;

use App\Http\Requests\POS\PedidoPOS;

/** Gravar as metas da empresa de um indicador, por período (valor vazio apaga a meta). Permissão est_metas_editar. */
final class GravarMetasRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['est_metas_editar'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'metas' => ['required', 'array', 'min:1', 'max:200'],
            'metas.*.periodo' => ['required', 'string', 'max:8'],
            'metas.*.valor' => ['nullable', 'numeric', 'between:-9999999999999999,9999999999999999'],
            'metas.*.valor_minimo' => ['nullable', 'numeric', 'between:-9999999999999999,9999999999999999'],
            'metas.*.nota' => ['nullable', 'string', 'max:300'],
            'motivo_excepcao' => ['nullable', 'string', 'max:300'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'metas.required' => 'Indique pelo menos uma meta.',
            'metas.*.periodo.required' => 'Cada meta tem de ter o seu período.',
            'metas.*.valor.numeric' => 'O valor da meta tem de ser um número.',
        ];
    }
}
