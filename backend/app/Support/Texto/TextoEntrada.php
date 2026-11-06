<?php

namespace App\Support\Texto;

/** Limpeza comum dos textos de formulários de entrada (registo, utilizadores): sem lixo invisível, sem espaços a mais. */
final class TextoEntrada
{
    /**
     * NFC; sem caracteres de controlo nem invisíveis (zero-width, BOM, bidi); espaços das pontas retirados e espaços
     * internos repetidos reduzidos a um. Vazio devolve null.
     */
    public static function limpar(mixed $v): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}]/u', '', $v) ?? $v;
        if (class_exists(\Normalizer::class) && ($n = \Normalizer::normalize($v, \Normalizer::FORM_C)) !== false) {
            $v = $n;
        }
        $v = trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $v) ?? $v);

        return $v === '' ? null : $v;
    }
}
