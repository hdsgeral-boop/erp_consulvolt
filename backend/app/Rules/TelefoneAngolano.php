<?php

namespace App\Rules;

use App\Support\Angola\TelefoneAngolano as ValidadorTelefone;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Telefone angolano (9 dígitos, +244 opcional) ou internacional (+indicativo). Ver App\Support\Angola\TelefoneAngolano. */
final class TelefoneAngolano implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if (! is_string($value) || ! ValidadorTelefone::valido($value)) {
            $fail(ValidadorTelefone::MENSAGEM);
        }
    }
}
