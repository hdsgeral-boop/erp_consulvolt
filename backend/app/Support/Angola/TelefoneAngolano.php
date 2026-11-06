<?php

namespace App\Support\Angola;

/**
 * Telefone: angolano (+244; 9 dígitos que começam por 9 — móvel — ou por 2 — fixo) ou internacional (+código, 7 a 15
 * dígitos). Aceita espaços, pontos, hífenes e parênteses, e o prefixo «+244», «00244» ou «244». Espelho no frontend:
 * `frontend/src/utilitarios/telefoneAngolano.ts`.
 */
final class TelefoneAngolano
{
    public const MENSAGEM = 'O telefone não é válido: use 9 dígitos começados por 9 (ex.: 923 000 111), com ou sem +244, ou um número internacional com «+» e o indicativo do país.';

    /**
     * Forma compacta: número angolano só com os 9 dígitos; internacional como «+<dígitos>». Texto que não se
     * reconhece fica como está (sem separadores), para a validação o recusar.
     */
    public static function normalizar(?string $telefone): string
    {
        $v = preg_replace('/[\s\x{00A0}.\-()]+/u', '', trim((string) $telefone)) ?? '';
        if (preg_match('/^(?:\+|00)?244([29]\d{8})$/', $v, $m) === 1) {
            return $m[1];
        }
        if (str_starts_with($v, '00') && preg_match('/^00[1-9]\d{6,14}$/', $v) === 1) {
            return '+'.substr($v, 2);
        }

        return $v;
    }

    public static function valido(?string $telefone): bool
    {
        $v = self::normalizar($telefone);

        return preg_match('/^[29]\d{8}$/', $v) === 1 || (preg_match('/^\+[1-9]\d{6,14}$/', $v) === 1 && ! str_starts_with($v, '+244'));
    }
}
