<?php

namespace App\Support\Angola;

/**
 * Validador ÚNICO do NIF angolano (AGT). Espelho no frontend: `frontend/src/utilitarios/nifAngolano.ts`.
 *
 * Formatos aceites (os mesmos que o SAF-T/AGT e a facturação electrónica já tratam noutros módulos):
 *  - pessoa colectiva / empresário: 10 dígitos (ex.: 5417012345);
 *  - 9 dígitos (contribuintes antigos e consumidor final);
 *  - pessoa singular: o número do BI, 9 dígitos + 2 letras + 3 dígitos (ex.: 000755848LA037).
 * Nunca se rejeita um NIF só por ser de pessoa singular. Recusam-se só os impossíveis: todos os dígitos iguais
 * (0000000000, 9999999999) e, salvo pedido em contrário, o 999999999 do «consumidor final».
 */
final class NifAngolano
{
    public const COLECTIVA = 'colectiva';

    public const SINGULAR = 'singular';

    public const DIGITOS_9 = 'nove_digitos';

    public const MENSAGEM = 'O NIF não é válido: use 10 dígitos (empresa) ou, para pessoa singular, o número do BI (9 dígitos, 2 letras e 3 dígitos, ex.: 000755848LA037).';

    /** Tira espaços (incluindo NBSP e invisíveis), pontos, hífenes e barras e põe as letras em maiúsculas. */
    public static function normalizar(?string $nif): string
    {
        $v = preg_replace('/[\s\x{00A0}\x{200B}-\x{200F}\x{2060}\x{FEFF}.\-\/]+/u', '', (string) $nif) ?? '';

        return mb_strtoupper($v);
    }

    /** `colectiva` | `singular` | `nove_digitos`, ou null se o formato não é de um NIF angolano. */
    public static function tipo(?string $nif): ?string
    {
        $v = self::normalizar($nif);

        return match (true) {
            preg_match('/^\d{10}$/', $v) === 1 => self::COLECTIVA,
            preg_match('/^\d{9}[A-Z]{2}\d{3}$/', $v) === 1 => self::SINGULAR,
            preg_match('/^\d{9}$/', $v) === 1 => self::DIGITOS_9,
            default => null,
        };
    }

    public static function valido(?string $nif, bool $permitirConsumidorFinal = false): bool
    {
        $v = self::normalizar($nif);
        if (self::tipo($v) === null) {
            return false;
        }
        if ($v === '999999999') {
            return $permitirConsumidorFinal;
        }

        // só dígitos todos iguais (placeholder): 0000000000, 1111111111, 9999999999…
        return preg_match('/^(\d)\1+$/', $v) !== 1;
    }
}
