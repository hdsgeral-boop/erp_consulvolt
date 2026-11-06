<?php

namespace App\Services\Estrategia;

/**
 * Cálculo do desempenho dos indicadores em aritmética DECIMAL (bcmath; valores como texto), nunca em vírgula flutuante:
 * o semáforo do legado marcava VERMELHO uma meta atingida exactamente a 90 % (8,1 / 9 × 100 = 89,99999999999999 em JS).
 * EST-C-01…05 (cumprimento, semáforo, cor do objectivo), EST-C-14 (agregação), EST-C-22 (escada).
 *
 * O semáforo compara por multiplicação cruzada (sem divisões): é exacto. O cumprimento devolvido (percentagem, 4 casas)
 * serve para mostrar, nunca para decidir a cor. O legado não tem pesos de objectivos nem de indicadores: não se inventam.
 */
final class CalculadoraDesempenho
{
    private const E = 8;

    /** Ordem de gravidade para a cor de um objectivo (EST-C-05). */
    private const ORDEM_COR = ['VERMELHO' => 0, 'AMARELO' => 1, 'SEM_MEDIDA' => 2, 'SEM_META' => 3, 'VERDE' => 4];

    /** Normaliza um número (aceita vírgula decimal); null se vazio ou inválido. */
    public static function numero(mixed $v): ?string
    {
        if ($v === null || $v === '' || is_bool($v)) {
            return null;
        }
        $s = str_replace([' ', ','], ['', '.'], trim((string) $v));
        if (preg_match('/^[+-]?(\d+(\.\d+)?|\.\d+)$/', $s) !== 1) {
            return null;
        }
        $s = ltrim($s, '+');
        if (str_starts_with($s, '.')) {
            $s = '0'.$s;
        } elseif (str_starts_with($s, '-.')) {
            $s = '-0'.substr($s, 1);
        }

        return $s;
    }

    /** EST-C-01…03: cumprimento em percentagem (4 casas), para mostrar. */
    public static function cumprimento(string $polaridade, mixed $valor, mixed $meta): string
    {
        $v = self::numero($valor) ?? '0';
        $m = self::numero($meta) ?? '0';
        $mZero = bccomp($m, '0', self::E) === 0;
        $vNaoPos = bccomp($v, '0', self::E) <= 0;
        if ($polaridade === 'DESCE') {
            $r = $vNaoPos ? '1' : ($mZero ? '0' : bcdiv($m, $v, self::E));
        } elseif ($polaridade === 'ALVO') {
            $r = $mZero
                ? (bccomp($v, '0', self::E) === 0 ? '1' : '0')
                : bcsub('1', bcdiv(self::abs(bcsub($v, $m, self::E)), self::abs($m), self::E), self::E);
            if (bccomp($r, '0', self::E) < 0) {
                $r = '0';
            }
        } else {
            $r = $mZero ? (bccomp($v, '0', self::E) >= 0 ? '1' : '0') : bcdiv($v, $m, self::E);
        }

        return self::arredondar(bcmul($r, '100', self::E), 4);
    }

    /** EST-C-04: o semáforo. `$verde`/`$amarelo` são as tolerâncias em % (omissão 100 e 90). */
    public static function semaforo(string $polaridade, mixed $valor, mixed $meta, mixed $verde = null, mixed $amarelo = null): string
    {
        $m = self::numero($meta);
        if ($m === null) {
            return 'SEM_META';
        }
        $v = self::numero($valor);
        if ($v === null) {
            return 'SEM_MEDIDA';
        }
        if (self::atinge($polaridade, $v, $m, self::numero($verde) ?? '100')) {
            return 'VERDE';
        }

        return self::atinge($polaridade, $v, $m, self::numero($amarelo) ?? '90') ? 'AMARELO' : 'VERMELHO';
    }

    /** O cumprimento é ≥ T % (T > 0)? Comparação exacta por multiplicação cruzada. */
    private static function atinge(string $pol, string $v, string $m, string $t): bool
    {
        $e = self::E;
        if ($pol === 'DESCE') {
            if (bccomp($v, '0', $e) <= 0) {
                return bccomp('100', $t, $e) >= 0;
            }
            if (bccomp($m, '0', $e) === 0) {
                return bccomp('0', $t, $e) >= 0;
            }

            return bccomp(bcmul($m, '100', $e), bcmul($t, $v, $e), $e) >= 0;   // (m / v) × 100 ≥ t, com v > 0
        }
        if ($pol === 'ALVO') {
            if (bccomp($m, '0', $e) === 0) {
                return bccomp(bccomp($v, '0', $e) === 0 ? '100' : '0', $t, $e) >= 0;
            }
            if (bccomp($t, '0', $e) <= 0) {
                return true;
            }

            // (1 − |v − m| / |m|) × 100 ≥ t  ⇔  |v − m| × 100 ≤ |m| × (100 − t)
            return bccomp(bcmul(self::abs(bcsub($v, $m, $e)), '100', $e), bcmul(self::abs($m), bcsub('100', $t, $e), $e), $e) <= 0;
        }
        if (bccomp($m, '0', $e) === 0) {
            return bccomp(bccomp($v, '0', $e) >= 0 ? '100' : '0', $t, $e) >= 0;
        }
        $esq = bcmul($v, '100', $e);
        $dir = bcmul($t, $m, $e);

        return bccomp($m, '0', $e) > 0 ? bccomp($esq, $dir, $e) >= 0 : bccomp($esq, $dir, $e) <= 0;
    }

    /**
     * EST-C-05: a cor do objectivo é a do pior indicador; sem indicadores, SEM_META.
     *
     * @param  list<string>  $cores
     */
    public static function piorCor(array $cores): string
    {
        if ($cores === []) {
            return 'SEM_META';
        }
        usort($cores, fn ($a, $b) => (self::ORDEM_COR[$a] ?? 9) <=> (self::ORDEM_COR[$b] ?? 9));

        return $cores[0];
    }

    /**
     * Contagem por cor e percentagem de cumpridos (VERDE) entre os que têm meta e medida (1 casa; null se nenhum).
     *
     * @param  list<string>  $cores
     * @return array<string, mixed>
     */
    public static function resumo(array $cores): array
    {
        $r = ['total' => count($cores), 'VERDE' => 0, 'AMARELO' => 0, 'VERMELHO' => 0, 'SEM_META' => 0, 'SEM_MEDIDA' => 0, 'cumpridos_pct' => null];
        foreach ($cores as $c) {
            if (isset($r[$c])) {
                $r[$c]++;
            }
        }
        $medidos = $r['VERDE'] + $r['AMARELO'] + $r['VERMELHO'];
        if ($medidos > 0) {
            $r['cumpridos_pct'] = self::arredondar(bcdiv(bcmul((string) $r['VERDE'], '100', 6), (string) $medidos, 6), 1);
        }

        return $r;
    }

    /**
     * EST-C-22: metas «em escada»: v0; passo absoluto (v += p) ou percentual (v ×= 1 + p/100); acumula sem arredondar, mostra a 2 casas.
     *
     * @return list<string>
     */
    public static function escada(mixed $inicial, mixed $passo, int $n, bool $percentual): array
    {
        $v = self::numero($inicial) ?? '0';
        $p = self::numero($passo) ?? '0';
        $saida = [];
        for ($k = 0; $k < $n; $k++) {
            if ($k > 0) {
                $v = $percentual ? bcmul($v, bcadd('1', bcdiv($p, '100', 12), 12), 12) : bcadd($v, $p, 12);
            }
            $saida[] = self::arredondar($v, 2);
        }

        return $saida;
    }

    /** Arredonda (meio para longe do zero, como o PHP) e devolve com `$casas` casas fixas. */
    public static function arredondar(string $v, int $casas): string
    {
        $neg = str_starts_with($v, '-');
        $r = bcadd(ltrim($v, '-'), '0.'.str_repeat('0', $casas).'5', $casas);

        return $neg && bccomp($r, '0', $casas) !== 0 ? '-'.$r : $r;
    }

    private static function abs(string $v): string
    {
        return ltrim($v, '-');
    }
}
