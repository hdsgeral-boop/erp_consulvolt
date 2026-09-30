<?php

namespace App\Services\Ativos;

use App\Models\AtivoImobilizado;
use App\Models\CategoriaAtivo;

/**
 * Regra de cálculo da quota mensal de amortização (sem acesso à base de dados), igual à do legado
 * (processAmortizationCalculations / executeBulkAmortizationProcessing / executeSingleAmortizationPosting,
 * js/ui_assets.js:1269-1389, 1975-2083, 1588-1742):
 *   - método das quotas constantes, mensal, sem pro rata: o mês de aquisição conta como mês inteiro (mês 1);
 *   - base = valor de aquisição − valor residual;
 *   - quota = quota fixa (se > 0), senão base ÷ vida útil (meses), arredondada ao cêntimo (half-up, ADR-022);
 *     sem vida útil, base × taxa anual da categoria ÷ 12 (25 % se o activo não tiver categoria, como o legado);
 *   - não é devida: antes do mês de aquisição, nos anos ≤ «ano da amortização acumulada inicial», depois de esgotada a
 *     vida útil, ou com o valor por amortizar a zero; a última quota é limitada ao valor por amortizar.
 * Correcções:
 *   - no último mês da vida útil a quota é o valor por amortizar (absorve os arredondamentos das quotas ao cêntimo e a
 *     diferença de uma amortização inicial migrada); o legado parava no fim da vida e deixava esse resto por amortizar
 *     para sempre (ex.: 1 000,00 em 3 meses → 999,99);
 *   - sem vida útil o legado nunca amortizava (a condição «meses decorridos > vida útil» é sempre verdadeira com
 * vida 0 — ui_assets.js:1356, 2031), embora a taxa da categoria estivesse prevista para esse caso (ui_assets.js:1336) e o
 * fluxo do imobilizado a aceite (fluxo_imobilizado.js:163). Aqui amortiza pela taxa até esgotar a base.
 */
final class CalculadoraAmortizacoes
{
    public const TAXA_SEM_CATEGORIA = '25';

    /** Base amortizável (aquisição − residual). */
    public static function base(AtivoImobilizado $a): string
    {
        return bcsub(self::d($a->valor_aquisicao), self::d($a->valor_residual), 2);
    }

    /** Quota mensal antes do limite do valor por amortizar. */
    public static function quota(AtivoImobilizado $a, ?CategoriaAtivo $c): string
    {
        if (bccomp(self::d($a->quota_fixa), '0', 2) > 0) {
            return self::d($a->quota_fixa);
        }
        $base = self::base($a);
        $vida = (int) $a->vida_util;
        if ($vida > 0) {
            return self::arredondar(bcdiv($base, (string) $vida, 10));
        }
        $taxa = $c ? (string) ($c->taxa_anual ?? '0') : self::TAXA_SEM_CATEGORIA;

        return self::arredondar(bcdiv(bcmul($base, $taxa, 10), '1200', 10));
    }

    /**
     * Quota devida no mês (ano, mês 1-12), dado o acumulado já amortizado (inicial + outras quotas), ou null se não houver.
     * Com $proposta (quota manual ou rascunho existente) usa-a em vez da calculada, sempre limitada ao valor por amortizar.
     */
    public static function devida(AtivoImobilizado $a, ?CategoriaAtivo $c, int $ano, int $mes, string $acumulado, ?string $proposta = null): ?string
    {
        if (! self::noPeriodoDeVida($a, $ano, $mes)) {
            return null;
        }
        $restante = bcsub(self::base($a), $acumulado, 2);
        if (bccomp($restante, '0', 2) <= 0) {
            return null;
        }
        $quota = $proposta ?? (self::ultimoMesDeVida($a, $ano, $mes) ? $restante : self::quota($a, $c));
        if (bccomp($quota, $restante, 2) > 0) {
            $quota = $restante;
        }

        return bccomp($quota, '0', 2) > 0 ? $quota : null;
    }

    /** O mês está dentro do período de amortização (aquisição, ano da amortização inicial e vida útil)? */
    public static function noPeriodoDeVida(AtivoImobilizado $a, int $ano, int $mes): bool
    {
        if (! $a->data_aquisicao) {
            return false;
        }
        $inicio = (int) $a->data_aquisicao->format('Y') * 12 + (int) $a->data_aquisicao->format('n') - 1;
        $n = $ano * 12 + $mes - 1;
        if ($n < $inicio) {
            return false;
        }
        if ($a->acumulado_fim_ano !== null && $ano <= (int) $a->acumulado_fim_ano) {
            return false;
        }
        $vida = (int) $a->vida_util;

        return $vida <= 0 || $n - $inicio + 1 <= $vida;
    }

    /** Último mês da vida útil (a quota desse mês absorve o valor por amortizar). */
    public static function ultimoMesDeVida(AtivoImobilizado $a, int $ano, int $mes): bool
    {
        $vida = (int) $a->vida_util;
        if ($vida <= 0 || ! $a->data_aquisicao) {
            return false;
        }

        return $ano * 12 + $mes - 1 - ((int) $a->data_aquisicao->format('Y') * 12 + (int) $a->data_aquisicao->format('n') - 1) + 1 === $vida;
    }

    /** "MM-AAAA" → [ano, mês]; recusa outros formatos. */
    public static function periodo(string $codigo): ?array
    {
        if (! preg_match('/^(\d{2})-(\d{4})$/', $codigo, $m) || (int) $m[1] < 1 || (int) $m[1] > 12) {
            return null;
        }

        return [(int) $m[2], (int) $m[1]];
    }

    public static function codigo(int $ano, int $mes): string
    {
        return str_pad((string) $mes, 2, '0', STR_PAD_LEFT).'-'.$ano;
    }

    /** Ordem cronológica de um período "MM-AAAA" (ano × 12 + mês − 1). */
    public static function ordem(string $codigo): int
    {
        [$ano, $mes] = self::periodo($codigo) ?? [0, 1];

        return $ano * 12 + $mes - 1;
    }

    public static function arredondar(string $v): string
    {
        $neg = str_starts_with($v, '-');

        return bcadd($v, $neg ? '-0.005' : '0.005', 2);
    }

    public static function d(mixed $v): string
    {
        return $v === null || $v === '' ? '0.00' : bcadd((string) $v, '0', 2);
    }
}
