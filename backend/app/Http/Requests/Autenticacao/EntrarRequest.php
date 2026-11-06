<?php

namespace App\Http\Requests\Autenticacao;

use App\Support\Texto\TextoEntrada;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Entrada (login). O identificador pode ser o nome de utilizador ou o e-mail (até 150 caracteres, o tamanho da coluna
 * do e-mail). Os espaços das pontas e os caracteres invisíveis são retirados (teclados de telemóvel acrescentam
 * espaços ao completar); a palavra-passe NUNCA se altera. As mensagens não dizem se a conta existe.
 */
final class EntrarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('nome_utilizador'))) {
            $this->merge(['nome_utilizador' => TextoEntrada::limpar($this->input('nome_utilizador'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nome_utilizador' => ['required', 'string', 'max:150'],
            'palavra_passe' => ['required', 'string', 'max:255'],
            'dispositivo' => ['nullable', 'string', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'nome_utilizador.required' => 'Indique o nome de utilizador ou o e-mail.',
            'nome_utilizador.max' => 'O nome de utilizador ou e-mail não pode ter mais de 150 caracteres.',
            'palavra_passe.required' => 'Indique a palavra-passe.',
            'palavra_passe.max' => 'A palavra-passe não pode ter mais de 255 caracteres.',
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
