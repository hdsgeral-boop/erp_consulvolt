<?php

namespace App\Http\Requests\Terceiros;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST/PUT /api/terceiros — `papel` indica a ficha (cliente ou fornecedor) que está a ser gravada. */
final class GuardarTerceiroRequest extends FormRequest
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
            'papel' => ['required', Rule::in(['CLIENTE', 'FORNECEDOR'])],
            'nome' => [$criar ? 'required' : 'sometimes', 'string', 'max:255'],
            'nif' => ['nullable', 'string', 'max:30'],
            'endereco' => ['nullable', 'string', 'max:1000'],
            'email' => ['nullable', 'email:rfc', 'max:150'],
            'telefone' => ['nullable', 'string', 'max:50'],
            'codigo_conta' => [$criar ? 'required' : 'sometimes', 'string', 'max:20'],
            'conta_compra_transitoria' => ['nullable', 'string', 'max:20'],
            'codigo_moeda' => ['nullable', 'string', Rule::exists('moedas', 'codigo')->whereNull('eliminado_em')],
            'fe_pais' => ['nullable', 'string', 'regex:/^[A-Za-z]{2}$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'codigo_conta.required' => 'Associe a conta contabilística da entidade antes de gravar.',
            'fe_pais.regex' => 'O país deve ter o código ISO de 2 letras (ex.: AO, PT).',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['nome' => 'nome', 'codigo_conta' => 'conta contabilística', 'codigo_moeda' => 'moeda', 'fe_pais' => 'país'];
    }

    /** @return array<string, mixed> */
    public function dados(): array
    {
        $d = collect($this->validated())->except('papel')->all();
        if (isset($d['fe_pais'])) {
            $d['fe_pais'] = mb_strtoupper($d['fe_pais']);
        }

        return $d;
    }
}
