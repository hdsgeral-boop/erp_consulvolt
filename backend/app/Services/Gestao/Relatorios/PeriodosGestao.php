<?php

namespace App\Services\Gestao\Relatorios;

use App\Exceptions\ErroNegocio;
use DateTimeImmutable;

/**
 * Períodos do relatório de gestão (presetPeriodo, periodoComparacao e mesesDoPeriodo,
 * modules/gestao/relatorios_gestao.js:36-75). Período A = em análise; período B = comparação (homólogo, período anterior
 * equivalente, datas livres ou nenhum). Regras iguais ao legado:
 *   - homólogo: as duas datas recuam um ano, com o dia limitado ao último dia do mês (29/02 → 28/02);
 *   - anterior: se A são meses inteiros, o mesmo n.º de meses imediatamente antes; senão, o mesmo n.º de dias;
 *   - os meses de um período são no máximo 61 (o legado parava aos 60 + 1).
 * Correcção: as datas são validadas (início ≤ fim, formato AAAA-MM-DD); o legado só avisava no ecrã.
 */
final class PeriodosGestao
{
    public const PRESETS_A = ['mes' => 'Mês actual', 'mes_anterior' => 'Mês anterior', 'trimestre' => 'Trimestre actual', 'trimestre_anterior' => 'Trimestre anterior',
        'semestre' => 'Semestre actual', 'ytd' => 'Acumulado do ano', 'ano' => 'Ano actual', 'ano_anterior' => 'Ano anterior', 'livre' => 'Datas à escolha'];

    public const COMPARACOES = ['homologo' => 'Homólogo (ano anterior)', 'anterior' => 'Período anterior', 'livre' => 'Datas à escolha', 'nenhum' => 'Sem comparação'];

    private const MESES = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];

    /**
     * Resolve A e B a partir do pedido (preset_a | a_inicio+a_fim, comparacao, b_inicio+b_fim, referencia).
     *
     * @param  array<string, mixed>  $f
     * @return array{a: array<string, mixed>, b: ?array<string, mixed>, duracao_diferente: bool}
     */
    public static function resolver(array $f): array
    {
        $ref = $f['referencia'] ?? date('Y-m-d');
        if (! empty($f['a_inicio']) || ! empty($f['a_fim'])) {
            $a = self::livre((string) ($f['a_inicio'] ?? ''), (string) ($f['a_fim'] ?? ''), 'A');
        } else {
            $a = self::preset((string) ($f['preset_a'] ?? 'mes'), $ref);
        }
        $tipoB = (string) ($f['comparacao'] ?? 'homologo');
        $b = match ($tipoB) {
            'nenhum' => null,
            'livre' => self::livre((string) ($f['b_inicio'] ?? ''), (string) ($f['b_fim'] ?? ''), 'B'),
            'homologo', 'anterior' => self::comparacao($tipoB, $a),
            default => throw new ErroNegocio('Comparação inválida.', 'COMPARACAO_INVALIDA', 422, ['comparacao' => ['Use homologo, anterior, livre ou nenhum.']]),
        };
        $a = self::descrever($a);
        $b = $b ? self::descrever($b) : null;

        return ['a' => $a, 'b' => $b, 'duracao_diferente' => $b !== null && abs($a['dias'] - $b['dias']) > 3];
    }

    /** @return array{inicio: string, fim: string, nome: string} */
    public static function preset(string $tipo, string $referencia): array
    {
        $d = self::data($referencia);
        $a = (int) $d->format('Y');
        $m = (int) $d->format('n') - 1;

        return match ($tipo) {
            'mes' => ['inicio' => self::iso($a, $m, 1), 'fim' => self::fimMes($a, $m), 'nome' => self::MESES[$m]." {$a}"],
            'mes_anterior' => (function () use ($a, $m) {
                [$y, $mm] = self::normalizar($a, $m - 1);

                return ['inicio' => self::iso($y, $mm, 1), 'fim' => self::fimMes($y, $mm), 'nome' => self::MESES[$mm]." {$y}"];
            })(),
            'trimestre' => (function () use ($a, $m) {
                $t = intdiv($m, 3);

                return ['inicio' => self::iso($a, $t * 3, 1), 'fim' => self::fimMes($a, $t * 3 + 2), 'nome' => ($t + 1).".º trim. {$a}"];
            })(),
            'trimestre_anterior' => (function () use ($a, $m) {
                [$y, $mm] = self::normalizar($a, intdiv($m, 3) * 3 - 3);
                $t = intdiv($mm, 3);

                return ['inicio' => self::iso($y, $mm, 1), 'fim' => self::fimMes($y, $mm + 2), 'nome' => ($t + 1).".º trim. {$y}"];
            })(),
            'semestre' => (function () use ($a, $m) {
                $s = $m < 6 ? 0 : 6;

                return ['inicio' => self::iso($a, $s, 1), 'fim' => self::fimMes($a, $s + 5), 'nome' => ($s ? 2 : 1).".º sem. {$a}"];
            })(),
            'ano' => ['inicio' => "{$a}-01-01", 'fim' => "{$a}-12-31", 'nome' => "Ano {$a}"],
            'ano_anterior' => ['inicio' => ($a - 1).'-01-01', 'fim' => ($a - 1).'-12-31', 'nome' => 'Ano '.($a - 1)],
            'ytd' => ['inicio' => "{$a}-01-01", 'fim' => $d->format('Y-m-d'), 'nome' => "Acumulado {$a} (até ".$d->format('d/m/Y').')'],
            default => throw new ErroNegocio('Período inválido.', 'PERIODO_INVALIDO', 422, ['preset_a' => ['Período desconhecido: '.$tipo.'.']]),
        };
    }

    /** @param  array{inicio: string, fim: string}  $a */
    public static function comparacao(string $tipo, array $a): array
    {
        if ($tipo === 'homologo') {
            $menos = function (string $s): string {
                [$y, $m, $d] = array_map('intval', explode('-', $s));
                $ult = (int) date('t', mktime(0, 0, 0, $m, 1, $y - 1));

                return sprintf('%04d-%02d-%02d', $y - 1, $m, min($d, $ult));
            };

            return ['inicio' => $menos($a['inicio']), 'fim' => $menos($a['fim']), 'nome' => 'Homólogo'];
        }
        $ini = self::data($a['inicio']);
        $fim = self::data($a['fim']);
        if ($ini->format('d') === '01' && $fim->format('Y-m-d') === $fim->format('Y-m-t')) {
            $n = count(self::meses($a));
            [$y, $m] = self::normalizar((int) $ini->format('Y'), (int) $ini->format('n') - 1 - $n);

            return ['inicio' => self::iso($y, $m, 1), 'fim' => $ini->modify('-1 day')->format('Y-m-d'), 'nome' => 'Período anterior'];
        }
        $dias = self::dias($a);
        $fimB = $ini->modify('-1 day');

        return ['inicio' => $fimB->modify('-'.($dias - 1).' days')->format('Y-m-d'), 'fim' => $fimB->format('Y-m-d'), 'nome' => 'Período anterior'];
    }

    /**
     * Meses do período (no máximo 61), com a chave AAAA-MM, o rótulo «Mmm AA» e as datas de início e fim do mês.
     *
     * @return list<array{chave: string, rotulo: string, inicio: string, fim: string}>
     */
    public static function meses(array $p): array
    {
        $a = (int) substr($p['inicio'], 0, 4);
        $m = (int) substr($p['inicio'], 5, 2) - 1;
        $af = (int) substr($p['fim'], 0, 4);
        $mf = (int) substr($p['fim'], 5, 2) - 1;
        $r = [];
        while ($a < $af || ($a === $af && $m <= $mf)) {
            $r[] = ['chave' => sprintf('%04d-%02d', $a, $m + 1), 'rotulo' => self::MESES[$m].' '.substr((string) $a, 2), 'inicio' => self::iso($a, $m, 1), 'fim' => self::fimMes($a, $m)];
            if (++$m > 11) {
                $m = 0;
                $a++;
            }
            if (count($r) > 60) {
                break;
            }
        }

        return $r;
    }

    public static function dias(array $p): int
    {
        return (int) self::data($p['inicio'])->diff(self::data($p['fim']))->days + 1;
    }

    public static function diaAnterior(string $data): string
    {
        return self::data($data)->modify('-1 day')->format('Y-m-d');
    }

    private static function descrever(array $p): array
    {
        return $p + ['dias' => self::dias($p), 'rotulo' => self::data($p['inicio'])->format('d/m/Y').' a '.self::data($p['fim'])->format('d/m/Y')];
    }

    private static function livre(string $ini, string $fim, string $qual): array
    {
        $ok = fn (string $s) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && DateTimeImmutable::createFromFormat('!Y-m-d', $s)?->format('Y-m-d') === $s;
        if (! $ok($ini) || ! $ok($fim) || $ini > $fim) {
            $campo = strtolower($qual);
            throw new ErroNegocio("Indique datas válidas para o período {$qual} (início ≤ fim).", 'PERIODO_INVALIDO', 422,
                ["{$campo}_inicio" => ['Data de início inválida ou posterior ao fim.']]);
        }

        return ['inicio' => $ini, 'fim' => $fim, 'nome' => 'Datas à escolha'];
    }

    private static function data(string $s): DateTimeImmutable
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', substr($s, 0, 10));
        if (! $d) {
            throw new ErroNegocio('Data inválida.', 'PERIODO_INVALIDO', 422, ['referencia' => ['Data inválida: '.$s.'.']]);
        }

        return $d;
    }

    /** @return array{0: int, 1: int} ano e mês (0-11) normalizados */
    private static function normalizar(int $a, int $m): array
    {
        $a += intdiv($m - ($m < 0 ? 11 : 0), 12);
        $m = (($m % 12) + 12) % 12;

        return [$a, $m];
    }

    private static function iso(int $a, int $m, int $d): string
    {
        [$a, $m] = self::normalizar($a, $m);

        return sprintf('%04d-%02d-%02d', $a, $m + 1, $d);
    }

    private static function fimMes(int $a, int $m): string
    {
        [$a, $m] = self::normalizar($a, $m);

        return date('Y-m-t', mktime(0, 0, 0, $m + 1, 1, $a));
    }
}
