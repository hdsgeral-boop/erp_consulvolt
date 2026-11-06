<?php

namespace App\Http\Requests\Imobiliario;

use App\Http\Requests\POS\PedidoPOS;

/** Pré-visualizar um texto de modelo com valores de exemplo (POST /modelos/pre-visualizar). Permissão imo_modelos_view. Não grava nada. */
final class PreVisualizarModeloRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['imo_modelos_view'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['assunto' => ['nullable', 'string', 'max:300'], 'corpo' => ['required', 'string', 'max:100000'], 'valores' => ['nullable', 'array', 'max:100'],
            'valores.*' => ['nullable', 'string', 'max:500']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['corpo.required' => 'O modelo não pode ficar sem texto.'];
    }
}
