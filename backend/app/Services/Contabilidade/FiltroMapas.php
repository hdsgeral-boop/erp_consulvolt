<?php

namespace App\Services\Contabilidade;

use App\Exceptions\ErroNegocio;

/**
 * Filtros comuns a todos os mapas contabilísticos (js/ui_reports.js:283-311), traduzidos para SQL:
 *   - exclusão da classe 9 (contabilidade analítica / "contas de migração"), salvo pedido expresso;
 *   - exclusão dos lançamentos de apuramento (períodos 13 e 14) do exercício em análise, salvo pedido expresso
 *     (o legado só os mostrava quando se escolhia o "período 13/14");
 *   - filtro de contas "21, 24-26, *x, x*" (filterLinesByAccounts, js/ui_reports.js:27-60);
 *   - unidade de negócio, centro de custo e diário.
 * As comparações de códigos usam a colação "C" (byte a byte), como a comparação de strings do JavaScript.
 */
final class FiltroMapas
{
    /**
     * @param  array<string, mixed>  $f  filtro_contas, unidade_negocio_id, centro_custo_id, diario_id, terceiro_id, incluir_classe_9, incluir_apuramento
     * @param  int|null  $anoApuramento  exercício cujos lançamentos dos períodos 13/14 se excluem (null = nenhum)
     * @return array{0: string, 1: list<mixed>} fragmento " AND ..." e parâmetros
     */
    public static function sql(string $alias, array $f, ?int $anoApuramento): array
    {
        $a = $alias !== '' ? "{$alias}." : '';
        $sql = '';
        $p = [];
        if (empty($f['incluir_classe_9'])) {
            $sql .= " AND {$a}codigo_conta NOT LIKE '9%'";
        }
        if ($anoApuramento !== null && empty($f['incluir_apuramento'])) {
            $sql .= " AND NOT (COALESCE({$a}periodo_id, 0) IN (13, 14) AND EXTRACT(YEAR FROM {$a}data_documento) = ?)";
            $p[] = $anoApuramento;
        }
        foreach (['unidade_negocio_id', 'centro_custo_id', 'diario_id', 'terceiro_id'] as $campo) {
            if (! empty($f[$campo])) {
                $sql .= " AND {$a}{$campo} = ?";
                $p[] = (int) $f[$campo];
            }
        }
        if (! empty($f['filtro_contas'])) {
            [$s, $pp] = self::contas("{$a}codigo_conta", (string) $f['filtro_contas']);
            $sql .= " AND {$s}";
            $p = array_merge($p, $pp);
        }

        return [$sql, $p];
    }

    /**
     * Expressão de contas do legado: lista separada por vírgulas de prefixos ("21"), intervalos ("24-26": compara o
     * prefixo do comprimento do maior limite), "*x" (até x, inclusive as subcontas de x) e "x*" (de x em diante).
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function contas(string $coluna, string $expressao): array
    {
        $partes = array_values(array_filter(array_map('trim', explode(',', $expressao)), fn ($s) => $s !== ''));
        if (! $partes) {
            return ['TRUE', []];
        }
        $ou = [];
        $p = [];
        $c = "{$coluna} COLLATE \"C\"";
        foreach ($partes as $parte) {
            if (! preg_match('/^[0-9A-Za-z.*\- ]+$/', $parte)) {
                throw new ErroNegocio("Filtro de contas inválido: \"{$parte}\".", 'FILTRO_CONTAS_INVALIDO', 422);
            }
            if (str_contains($parte, '-')) {
                [$ini, $fim] = array_map('trim', explode('-', $parte, 2));
                $n = max(strlen($ini), strlen($fim));
                $ou[] = "(left({$coluna}, {$n}) COLLATE \"C\" BETWEEN ? AND ?)";
                array_push($p, $ini, $fim);
            } elseif (str_starts_with($parte, '*')) {
                $v = substr($parte, 1);
                $ou[] = "({$c} <= ? OR {$coluna} LIKE ?)";
                array_push($p, $v, self::like($v));
            } elseif (str_ends_with($parte, '*')) {
                $ou[] = "({$c} >= ?)";
                $p[] = substr($parte, 0, -1);
            } else {
                $ou[] = "({$coluna} LIKE ?)";
                $p[] = self::like($parte);
            }
        }

        return ['('.implode(' OR ', $ou).')', $p];
    }

    public static function like(string $prefixo): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $prefixo).'%';
    }

    /** Valor monetário de um agregado SQL (numeric exacto, 2 casas — ADR-022). */
    public static function dinheiro(mixed $v): string
    {
        if ($v === null || $v === '') {
            return '0.00';
        }
        $s = is_string($v) ? $v : number_format(round((float) $v, 2), 2, '.', '');

        return bcadd($s, '0', 2);
    }
}
