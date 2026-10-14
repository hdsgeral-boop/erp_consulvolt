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
            // dados bancários (só contas 42/43 de movimento; normalizados e validados em ServicoContasBancarias)
            'banco_nome' => ['nullable', 'string', 'max:100'],
            'banco_balcao' => ['nullable', 'string', 'max:100'],
            'banco_numero_conta' => ['nullable', 'string', 'max:40'],
            'banco_iban' => ['nullable', 'string', 'max:50'],   // aceita espaços/pontos; normalizado fica com ≤ 34
            'banco_swift' => ['nullable', 'string', 'max:15'],
            'banco_titular' => ['nullable', 'string', 'max:150'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['codigo' => 'código', 'descricao' => 'descrição', 'tipo' => 'tipo (M = movimento, T = totalizadora)', 'banco_nome' => 'banco',
            'banco_balcao' => 'balcão / agência', 'banco_numero_conta' => 'n.º de conta', 'banco_iban' => 'IBAN', 'banco_swift' => 'SWIFT / BIC', 'banco_titular' => 'titular da conta'];
    }
}
