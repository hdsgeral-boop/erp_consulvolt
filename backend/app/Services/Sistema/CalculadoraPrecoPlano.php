<?php

namespace App\Services\Sistema;

/**
 * Motor de cálculo de preços por ciclo de facturação (Mensal/Trimestral/Semestral/Anual), com desconto progressivo
 * — ver `erp.planos_catalogo.ciclos`. Único sítio onde estas fórmulas existem: ServicoLicenca e o frontend (via a
 * matriz pré-calculada devolvida por /api/licenca/planos) nunca as recalculam por conta própria (ADR-074, Módulo E
 * do motor de precificação: o backend é sempre autoritário sobre o valor final a cobrar).
 *
 * Fórmulas:
 *   V_bruto            = P_mês × meses
 *   V_desconto_ciclo    = V_bruto × (D_ciclo% / 100)
 *   V_apos_ciclo        = V_bruto − V_desconto_ciclo
 *   P_mensal_efetivo    = V_apos_ciclo / meses
 */
final class CalculadoraPrecoPlano
{
    public const CICLO_PERSONALIZADO = 'PERSONALIZADO';

    /** @return array<string, array{meses: int, desconto_percentagem: float, rotulo: string}> */
    public function ciclos(): array
    {
        return (array) config('erp.planos_catalogo.ciclos', []);
    }

    /** Código do ciclo correspondente a um nº de meses (3→TRIMESTRAL, …); fora dos 4 valores conhecidos → PERSONALIZADO (0%). */
    public function cicloPorMeses(int $meses): string
    {
        foreach ($this->ciclos() as $codigo => $def) {
            if ((int) $def['meses'] === $meses) {
                return $codigo;
            }
        }

        return self::CICLO_PERSONALIZADO;
    }

    /**
     * @return array{ciclo_codigo: string, meses: int, desconto_percentagem: float, valor_bruto: float,
     *     valor_desconto_ciclo: float, valor_apos_ciclo: float, preco_mensal_efetivo: float}
     */
    public function calcular(float $precoMensal, string $cicloCodigo, ?int $mesesPersonalizado = null): array
    {
        $def = $this->ciclos()[$cicloCodigo] ?? null;
        $meses = $def['meses'] ?? $mesesPersonalizado;
        if ($meses === null || $meses < 1) {
            $meses = 1;
        }
        $descontoPercentagem = (float) ($def['desconto_percentagem'] ?? 0);

        $precoMensalStr = number_format($precoMensal, 2, '.', '');
        $valorBruto = bcmul($precoMensalStr, (string) $meses, 2);
        $valorDescontoCiclo = bcdiv(bcmul($valorBruto, number_format($descontoPercentagem, 2, '.', ''), 4), '100', 2);
        $valorAposCiclo = bcsub($valorBruto, $valorDescontoCiclo, 2);
        $precoMensalEfetivo = bcdiv($valorAposCiclo, (string) $meses, 2);

        return [
            'ciclo_codigo' => $cicloCodigo,
            'meses' => $meses,
            'desconto_percentagem' => $descontoPercentagem,
            'valor_bruto' => (float) $valorBruto,
            'valor_desconto_ciclo' => (float) $valorDescontoCiclo,
            'valor_apos_ciclo' => (float) $valorAposCiclo,
            'preco_mensal_efetivo' => (float) $precoMensalEfetivo,
        ];
    }

    /** Atalho: resolve o ciclo a partir do nº de meses e calcula. */
    public function calcularPorMeses(float $precoMensal, int $meses): array
    {
        return $this->calcular($precoMensal, $this->cicloPorMeses($meses), $meses);
    }

    /** Matriz pré-calculada dos ciclos conhecidos (Mensal/Trimestral/Semestral/Anual), para /api/licenca/planos. */
    public function matriz(float $precoMensal): array
    {
        $matriz = [];
        foreach ($this->ciclos() as $codigo => $def) {
            $matriz[$codigo] = $this->calcular($precoMensal, $codigo) + ['rotulo' => $def['rotulo']];
        }

        return $matriz;
    }
}
