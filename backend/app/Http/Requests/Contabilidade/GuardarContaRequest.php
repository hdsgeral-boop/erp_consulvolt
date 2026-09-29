<?php

namespace App\Http\Requests\Contabilidade;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST/PUT /api/contabilidade/plano-contas */
final class GuardarContaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $criar = $this->isMethod('post');

        return [
            'codigo' => [$criar ? 'required' : 'sometimes', 'string', 'max:20', 'regex:/^[0-9A-Za-z.]+$/'],
            'descricao' => [$criar ? 'required' : 'sometimes', 'string', 'max:255'],
            'tipo' => [$criar ? 'required' : 'sometimes', Rule::in(['M', 'T'])],
            'codigo_moeda' => ['nullable', 'string', 'max:10'],
            'natureza_conta' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['codigo' => 'código', 'descricao' => 'descrição', 'tipo' => 'tipo (M = movimento, T = totalizadora)'];
    }
}
