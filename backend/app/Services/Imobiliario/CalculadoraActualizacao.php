<?php

namespace App\Services\Imobiliario;

use App\Services\Vendas\CalculadoraDocumento;

/**
 * Actualização da renda por indexação (rendaNoPeriodo do legado): `n = floor(meses desde a base / actualizar a cada N meses)`;
 * percentagem composta `renda × (1 + v/100)^n` ou valor fixo linear `renda + v × n`; «não actualiza» devolve a renda base.
 * Função pura, em decimal exacto (bcmath), sem base de dados.
 */
final class CalculadoraActualizacao
{
    /**
     * @param  array{renda: float|int|string, actualizacao?: string, actualizacao_valor?: float|int|string, actualizacao_meses?: int|string|null, actualizacao_base?: ?string, inicio: string}  $c
     */
    public static function rendaNoPeriodo(array $c, string $periodo): string
    {
        $renda = number_format((float) $c['renda'], 2, '.', '');
        $tipo = (string) ($c['actualizacao'] ?? 'NAO');
        $valor = number_format((float) ($c['actualizacao_valor'] ?? 0), 6, '.', '');
        if ($tipo === 'NAO' || bccomp($valor, '0', 6) <= 0) {
            return $renda;
        }
        $n = self::actualizacoesDevidas($c, $periodo);
        if ($n <= 0) {
            return $renda;
        }
        if ($tipo === 'VALOR') {
            return CalculadoraDocumento::arredondar(bcadd($renda, bcmul($valor, (string) $n, 8), 8));
        }
        $factor = bcadd('1', bcdiv($valor, '100', 10), 10);
        $r = $renda;
        for ($i = 0; $i < min($n, 600); $i++) {
            $r = bcmul($r, $factor, 10);
        }

        return CalculadoraDocumento::arredondar($r);
    }

    /** Número de actualizações já devidas no período. */
    public static function actualizacoesDevidas(array $c, string $periodo): int
    {
        $base = CalculadoraPeriodos::periodoDe((string) (($c['actualizacao_base'] ?? null) ?: $c['inicio']));

        return max(0, intdiv(CalculadoraPeriodos::mesesEntre($base, $periodo), max(1, (int) ($c['actualizacao_meses'] ?? 12))));
    }
}
