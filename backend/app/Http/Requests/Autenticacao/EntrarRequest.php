<?php

namespace App\Http\Requests\Autenticacao;

use Illuminate\Foundation\Http\FormRequest;

final class EntrarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nome_utilizador' => ['required', 'string', 'max:100'],
            'palavra_passe' => ['required', 'string', 'max:255'],
            'dispositivo' => ['nullable', 'string', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'nome_utilizador' => 'nome de utilizador',
            'palavra_passe' => 'palavra-passe',
            'dispositivo' => 'dispositivo',
        ];
    }
}
