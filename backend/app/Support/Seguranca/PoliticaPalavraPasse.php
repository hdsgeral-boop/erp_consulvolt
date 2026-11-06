<?php

namespace App\Support\Seguranca;

/**
 * Política ÚNICA das palavras-passe escolhidas por pessoas (ADR-082/083; Argon2id guarda-as). Espelho no frontend:
 * `frontend/src/utilitarios/palavraPasse.ts` (`problemaPalavraPasse`, `forcaPalavraPasse`).
 *
 * - mínimo de 8 caracteres (o registo de uma empresa exige 10, pois cria a conta com acesso total) e máximo de 128;
 * - maiúscula, minúscula e algarismo;
 * - nunca uma palavra trivial (password, qwerty, 12345678, «consulvolt»…), só uma sequência (abcdefgh, 12345678) ou um
 *   só carácter repetido, nem conter o nome de utilizador ou a parte local do e-mail da própria conta.
 * As palavras-passe geradas pelo servidor (14 caracteres aleatórios) cumprem-na sempre.
 */
final class PoliticaPalavraPasse
{
    public const MINIMO = 8;

    public const MINIMO_REGISTO = 10;

    public const MAXIMO = 128;

    /** Palavras triviais: comparam-se sem maiúsculas e só com letras e algarismos, também depois de tirar algarismos finais. */
    private const TRIVIAIS = [
        'password', 'passw0rd', 'palavrapasse', 'palavrapass', 'senha', 'senha123', 'qwerty', 'qwertyuiop', 'azerty', 'abc123',
        'admin', 'admin123', 'administrador', 'administrator', 'root', 'letmein', 'welcome', 'bemvindo', 'iloveyou', 'teste', 'test',
        'consulvolt', 'consulvoltrp', 'erp', 'erpconsulvolt', 'mudar123', 'mudaragora', 'trocar', 'login',
    ];

    public static function minimoDoRegisto(): int
    {
        return self::MINIMO_REGISTO;
    }

    /**
     * Mensagem do primeiro problema (já em português, pronta a mostrar), ou null se a palavra-passe é aceite.
     *
     * @param  list<string|null>  $evitar  textos da própria conta que a palavra-passe não pode conter (utilizador, e-mail)
     */
    public static function problema(string $p, array $evitar = [], int $minimo = self::MINIMO): ?string
    {
        $n = mb_strlen($p);
        if ($n < $minimo) {
            return "A palavra-passe tem de ter pelo menos {$minimo} caracteres.";
        }
        if ($n > self::MAXIMO) {
            return 'A palavra-passe não pode ter mais de '.self::MAXIMO.' caracteres.';
        }
        if (! preg_match('/\p{Lu}/u', $p) || ! preg_match('/\p{Ll}/u', $p) || ! preg_match('/\d/', $p)) {
            return 'A palavra-passe é fraca: use maiúsculas, minúsculas e algarismos (por exemplo, «Barcaça#2026»).';
        }
        if (self::trivial($p)) {
            return 'Esta palavra-passe é demasiado comum ou previsível. Escolha outra.';
        }
        $chave = self::chave($p);
        foreach ($evitar as $texto) {
            $t = self::chave((string) $texto);
            if (mb_strlen($t) >= 4 && str_contains($chave, $t)) {
                return 'A palavra-passe não pode conter o seu nome de utilizador nem o seu e-mail.';
            }
        }

        return null;
    }

    public static function trivial(string $p): bool
    {
        $c = self::chave($p);
        $sem = preg_replace('/\d+$/', '', $c) ?? $c;
        if (in_array($c, self::TRIVIAIS, true) || in_array($sem, self::TRIVIAIS, true)) {
            return true;
        }
        // um só carácter repetido, ou uma sequência pura (12345678, abcdefgh, 87654321)
        if ($c !== '' && preg_match('/^(.)\1+$/u', $c) === 1) {
            return true;
        }

        return mb_strlen($c) >= 6 && (str_contains('abcdefghijklmnopqrstuvwxyz', $c) || str_contains('zyxwvutsrqponmlkjihgfedcba', $c)
            || str_contains('01234567890', $c) || str_contains('09876543210', $c) || str_contains('qwertyuiopasdfghjklzxcvbnm', $c));
    }

    private static function chave(string $v): string
    {
        $sem = class_exists(\Normalizer::class) ? (\Normalizer::normalize(mb_strtolower($v), \Normalizer::FORM_D) ?: mb_strtolower($v)) : mb_strtolower($v);

        return preg_replace('/[^a-z0-9]/', '', preg_replace('/\p{Mn}+/u', '', $sem) ?? $sem) ?? '';
    }
}
