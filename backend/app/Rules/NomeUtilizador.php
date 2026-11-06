<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Nome de utilizador de contas NOVAS (registo e criação por um administrador): 3 a 50 caracteres, só letras sem acento
 * (a-z), algarismos e os separadores «.», «_» e «-» (não no início nem no fim, nunca dois seguidos), sem «@» (o e-mail
 * tem o seu próprio modo de entrada) e fora da lista de nomes reservados. Em maiúsculas o valor é aceite mas o servidor
 * grava-o em minúsculas (`normalizar`): «Joao.Silva» e «joao.silva» são a mesma conta. As contas antigas, com outro
 * formato, continuam a entrar como sempre. Espelho no frontend: `utilitarios/nomeUtilizador.ts`.
 */
final class NomeUtilizador implements ValidationRule
{
    public const MINIMO = 3;

    public const MAXIMO = 50;

    /** @var list<string> */
    public const RESERVADOS = [
        'admin', 'administrador', 'administrator', 'root', 'suporte', 'support', 'sistema', 'system', 'superadmin', 'superadministrador',
        'plataforma', 'consulvolt', 'api', 'www', 'null', 'undefined', 'anonymous', 'anonimo', 'nobody', 'guest', 'convidado', 'test', 'teste',
    ];

    public static function normalizar(?string $v): string
    {
        return mb_strtolower(trim((string) $v));
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $v = is_string($value) ? self::normalizar($value) : '';
        $n = mb_strlen($v);
        if ($n < self::MINIMO || $n > self::MAXIMO) {
            $fail('O nome de utilizador deve ter entre '.self::MINIMO.' e '.self::MAXIMO.' caracteres.');

            return;
        }
        if (preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/', $v) !== 1) {
            $fail('O nome de utilizador só pode ter letras sem acentos, algarismos e os sinais . _ - (sem espaços, sem @, e sem sinais no início, no fim ou repetidos).');

            return;
        }
        if (in_array($v, self::RESERVADOS, true)) {
            $fail('Este nome de utilizador está reservado. Escolha outro.');
        }
    }
}
