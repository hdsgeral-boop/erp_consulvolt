<?php

namespace App\Services\Imobiliario;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Períodos, vencimentos e pro-rata das rendas (limitesPeriodo, somarMeses, periodosDoContrato e vencimento do legado). Funções
 * puras sobre datas ISO (AAAA-MM-DD) e períodos AAAA-MM, sem base de dados. Correcção do legado (P3): o primeiro e o último período
 * são proporcionais aos dias em que o contrato vigora (`factorProRata`); o legado cobrava sempre o período inteiro e continua a
 * poder obter-se com o contrato `pro_rata = false` (fixture dos testes).
 */
final class CalculadoraPeriodos
{
    public const MESES = ['MENSAL' => 1, 'BIMESTRAL' => 2, 'TRIMESTRAL' => 3, 'SEMESTRAL' => 6, 'ANUAL' => 12];

    public static function meses(string $periodicidade): int
    {
        return self::MESES[$periodicidade] ?? 1;
    }

    public static function periodoDe(string $iso): string
    {
        return substr($iso, 0, 7);
    }

    /** Soma (ou subtrai) meses a um período AAAA-MM. */
    public static function somarMeses(string $periodo, int $n): string
    {
        [$a, $m] = array_map('intval', explode('-', $periodo));
        $indice = $a * 12 + ($m - 1) + $n;

        return sprintf('%04d-%02d', intdiv($indice, 12), $indice % 12 + 1);
    }

    /** Meses inteiros entre dois períodos (b − a). */
    public static function mesesEntre(string $a, string $b): int
    {
        [$a1, $m1] = array_map('intval', explode('-', $a));
        [$a2, $m2] = array_map('intval', explode('-', $b));

        return ($a2 - $a1) * 12 + ($m2 - $m1);
    }

    /** @return array{0: string, 1: string} primeiro e último dia do período (de n meses) que começa em `$periodo` */
    public static function limites(string $periodo, string $periodicidade): array
    {
        $n = self::meses($periodicidade);

        return [$periodo.'-01', self::ultimoDia(self::somarMeses($periodo, $n - 1))];
    }

    public static function ultimoDia(string $periodo): string
    {
        $d = new DateTimeImmutable($periodo.'-01', new DateTimeZone('UTC'));

        return $d->modify('last day of this month')->format('Y-m-d');
    }

    /** Dias entre duas datas ISO (b − a), em UTC. */
    public static function diasEntre(string $a, string $b): int
    {
        $x = new DateTimeImmutable(substr($a, 0, 10), new DateTimeZone('UTC'));
        $y = new DateTimeImmutable(substr($b, 0, 10), new DateTimeZone('UTC'));

        return (int) $x->diff($y)->format('%r%a');
    }

    /** Adiciona meses a uma data ISO sem saltar de mês (31 Jan + 1 mês = 28/29 Fev). */
    public static function somarMesesData(string $iso, int $n): string
    {
        $dia = (int) substr($iso, 8, 2);
        $alvo = self::somarMeses(substr($iso, 0, 7), $n);
        $ultimo = (int) substr(self::ultimoDia($alvo), 8, 2);

        return sprintf('%s-%02d', $alvo, min($dia, $ultimo));
    }

    /**
     * Períodos (início de cada um) que o contrato cobre entre `$de` e `$ate` (inclusive). Parte do mês do início e avança de n em n
     * meses; o limite é o fim efectivo (`fim_real` ou `fim`) ou `$ate`. Só emite períodos que começam em `$de` ou depois.
     *
     * @return list<string>
     */
    public static function periodosDoContrato(string $inicio, ?string $fim, string $periodicidade, string $de, string $ate): array
    {
        $n = self::meses($periodicidade);
        $limite = $fim !== null && $fim !== '' && $fim < $ate ? $fim : $ate;
        $ultimo = self::periodoDe($limite);
        $minimo = self::periodoDe($de);
        $saida = [];
        $p = self::periodoDe($inicio);
        for ($i = 0; $i < 600 && $p <= $ultimo; $i++) {
            if ($p >= $minimo) {
                $saida[] = $p;
            }
            $p = self::somarMeses($p, $n);
        }

        return $saida;
    }

    /** Data de vencimento: o dia escolhido (limitado ao fim do mês) do mês de início (renda adiantada) ou do mês final do período. */
    public static function vencimento(string $de, string $ate, bool $antecipada, int $dia): string
    {
        $periodo = self::periodoDe($antecipada ? $de : $ate);
        $ultimo = (int) substr(self::ultimoDia($periodo), 8, 2);

        return sprintf('%s-%02d', $periodo, min(max(1, $dia), $ultimo));
    }

    /**
     * Fracção do período em que o contrato vigora, como «dias cobertos / dias do período» com 10 casas (1 = período inteiro).
     * `$fimEfectivo` é o último dia do contrato (null = sem termo). Sem pro-rata devolve sempre 1 (comportamento do legado).
     *
     * @return array{factor: string, cobertos: int, total: int}
     */
    public static function factorProRata(string $de, string $ate, string $inicio, ?string $fimEfectivo, bool $proRata = true): array
    {
        $total = self::diasEntre($de, $ate) + 1;
        if (! $proRata) {
            return ['factor' => '1.0000000000', 'cobertos' => $total, 'total' => $total];
        }
        $ini = max($de, $inicio);
        $fim = $fimEfectivo !== null && $fimEfectivo !== '' && $fimEfectivo < $ate ? $fimEfectivo : $ate;
        $cobertos = max(0, min($total, self::diasEntre($ini, $fim) + 1));

        return ['factor' => $cobertos >= $total ? '1.0000000000' : bcdiv((string) $cobertos, (string) $total, 10), 'cobertos' => $cobertos, 'total' => $total];
    }
}
