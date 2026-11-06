<?php

namespace App\Services\Estrategia;

use DateTimeInterface;

/**
 * Períodos do plano estratégico (puro, sem BD). Chave ordenável como no legado: `2027-03` (mês), `2027-T1`, `2027-S1`, `2027`.
 * EST-C-06 período equivalente, EST-C-07 período anterior, EST-C-08 intervalo (último dia do mês real).
 */
final class Periodos
{
    public const REGEX = '/^([0-9]{4})(?:-(0[1-9]|1[0-2]|T[1-4]|S[12]))?$/';

    private const MESES = ['', 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

    public static function valido(string $chave): bool
    {
        return preg_match(self::REGEX, $chave) === 1;
    }

    /** Frequência a que uma chave pertence (MENSAL, TRIMESTRAL, SEMESTRAL, ANUAL) ou null se for inválida. */
    public static function frequenciaDe(string $chave): ?string
    {
        if (preg_match(self::REGEX, $chave, $m) !== 1) {
            return null;
        }
        $sufixo = $m[2] ?? '';

        return match (true) {
            $sufixo === '' => 'ANUAL',
            $sufixo[0] === 'T' => 'TRIMESTRAL',
            $sufixo[0] === 'S' => 'SEMESTRAL',
            default => 'MENSAL',
        };
    }

    /**
     * Os períodos de um ano para uma frequência, por ordem (12, 4, 2 ou 1).
     *
     * @return list<string>
     */
    public static function periodosDe(string $frequencia, int $ano): array
    {
        return match ($frequencia) {
            'MENSAL' => array_map(fn (int $m) => sprintf('%04d-%02d', $ano, $m), range(1, 12)),
            'TRIMESTRAL' => array_map(fn (int $t) => "{$ano}-T{$t}", range(1, 4)),
            'SEMESTRAL' => ["{$ano}-S1", "{$ano}-S2"],
            'ANUAL' => [(string) $ano],
            default => [],
        };
    }

    /** O mês (1-12) em que o período começa. */
    public static function mesInicial(string $chave): int
    {
        preg_match(self::REGEX, $chave, $m);
        $s = $m[2] ?? '';

        return match (true) {
            $s === '' => 1,
            $s[0] === 'T' => ((int) $s[1] - 1) * 3 + 1,
            $s[0] === 'S' => ((int) $s[1] - 1) * 6 + 1,
            default => (int) $s,
        };
    }

    /** EST-C-06: o período da frequência indicada que contém o primeiro mês do período dado. */
    public static function equivalente(string $frequencia, string $chave): ?string
    {
        if (! self::valido($chave)) {
            return null;
        }
        $ano = (int) substr($chave, 0, 4);
        $mes = self::mesInicial($chave);

        return match ($frequencia) {
            'ANUAL' => (string) $ano,
            'SEMESTRAL' => $ano.'-S'.($mes <= 6 ? 1 : 2),
            'TRIMESTRAL' => $ano.'-T'.(int) ceil($mes / 3),
            'MENSAL' => sprintf('%04d-%02d', $ano, $mes),
            default => null,
        };
    }

    /** O período em curso numa data, para a frequência. */
    public static function actual(string $frequencia, DateTimeInterface $data): string
    {
        return (string) self::equivalente($frequencia, $data->format('Y-m'));
    }

    /** EST-C-07: o período anterior (para o primeiro do ano, o último do ano anterior). */
    public static function anterior(string $chave): ?string
    {
        $f = self::frequenciaDe($chave);
        if ($f === null) {
            return null;
        }
        $ano = (int) substr($chave, 0, 4);
        $i = array_search($chave, self::periodosDe($f, $ano), true);
        if ($i > 0) {
            return self::periodosDe($f, $ano)[$i - 1];
        }
        $ant = self::periodosDe($f, $ano - 1);

        return $ant[count($ant) - 1];
    }

    /**
     * EST-C-08: [início, fim] como `AAAA-MM-DD` (o último dia é o do mês real, bissextos incluídos).
     *
     * @return array{0: string, 1: string}
     */
    public static function intervalo(string $chave): array
    {
        $ano = (int) substr($chave, 0, 4);
        $ini = self::mesInicial($chave);
        $n = match (self::frequenciaDe($chave)) {
            'ANUAL' => 12,
            'SEMESTRAL' => 6,
            'TRIMESTRAL' => 3,
            default => 1,
        };
        $fim = $ini + $n - 1;
        $ultimo = (int) date('t', mktime(0, 0, 0, $fim, 1, $ano));

        return [sprintf('%04d-%02d-01', $ano, $ini), sprintf('%04d-%02d-%02d', $ano, $fim, $ultimo)];
    }

    public static function rotulo(string $chave): string
    {
        $ano = substr($chave, 0, 4);

        return match (self::frequenciaDe($chave)) {
            'ANUAL' => $ano,
            'SEMESTRAL' => substr($chave, 6, 1).'.º semestre de '.$ano,
            'TRIMESTRAL' => substr($chave, 6, 1).'.º trimestre de '.$ano,
            'MENSAL' => self::MESES[(int) substr($chave, 5, 2)].' de '.$ano,
            default => $chave,
        };
    }

    /** O período pertence ao ciclo do plano? */
    public static function noCiclo(string $chave, int $inicio, int $fim): bool
    {
        if (! self::valido($chave)) {
            return false;
        }
        $ano = (int) substr($chave, 0, 4);

        return $ano >= $inicio && $ano <= $fim;
    }
}
