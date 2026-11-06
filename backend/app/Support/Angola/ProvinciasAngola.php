<?php

namespace App\Support\Angola;

/** As 21 províncias de Angola (divisão de 2024) e a forma de reconhecer a grafia do utilizador (sem acentos nem maiúsculas). */
final class ProvinciasAngola
{
    /** @var list<string> */
    public const LISTA = [
        'Bengo', 'Benguela', 'Bié', 'Cabinda', 'Cuando', 'Cubango', 'Cuanza Norte', 'Cuanza Sul', 'Cunene', 'Huambo', 'Huíla',
        'Icolo e Bengo', 'Luanda', 'Lunda Norte', 'Lunda Sul', 'Malanje', 'Moxico', 'Moxico Leste', 'Namibe', 'Uíge', 'Zaire',
    ];

    /** Grafias antigas ou alternativas aceites (chave já simplificada). */
    private const ALIAS = ['cuandocubango' => 'Cubango', 'kwanzanorte' => 'Cuanza Norte', 'kwanzasul' => 'Cuanza Sul', 'kuanzanorte' => 'Cuanza Norte', 'kuanzasul' => 'Cuanza Sul', 'uije' => 'Uíge', 'bie' => 'Bié', 'huila' => 'Huíla'];

    private static function chave(string $v): string
    {
        $v = mb_strtolower(trim($v));
        $sem = class_exists(\Normalizer::class) ? (\Normalizer::normalize($v, \Normalizer::FORM_D) ?: $v) : $v;
        $sem = preg_replace('/\p{Mn}+/u', '', $sem) ?? $sem;

        $k = preg_replace('/[\s\-]+/u', '', $sem) ?? '';

        return preg_match('/^[a-z]+$/', $k) === 1 ? $k : '';
    }

    /** Nome canónico da província, ou null se não for uma província de Angola. */
    public static function canonica(?string $texto): ?string
    {
        $k = self::chave((string) $texto);
        if ($k === '') {
            return null;
        }
        foreach (self::LISTA as $p) {
            if (self::chave($p) === $k) {
                return $p;
            }
        }

        return self::ALIAS[$k] ?? null;
    }
}
