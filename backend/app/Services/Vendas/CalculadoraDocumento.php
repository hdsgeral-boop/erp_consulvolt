<?php

namespace App\Services\Vendas;

/**
 * Cálculo dos valores de um documento comercial com as regras da facturação electrónica AGT
 * (js/facturacao_agt.js:245-294), em decimal exacto (bcmath):
 *   valor da linha = arred(qtd × preço, 2)
 *   imposto        = arredondado ao cêntimo POR EXCESSO (valor × taxa / 100)   — excessoCentimo do legado
 *   totais         = somas das linhas; bruto = líquido + imposto
 * O mesmo cálculo serve os documentos fora do regime: acabam as divergências de cêntimos entre o
 * total do documento e a contabilização (o legado usava floats e recalculava o IVA por outra fórmula).
 */
final class CalculadoraDocumento
{
    /**
     * @param  list<array{quantidade: string|float|int, preco_unitario: string|float|int, taxa_imposto: string|float|int}>  $linhas
     * @return array{linhas: list<array{valor: string, imposto: string, total: string}>, total_liquido: string, total_imposto: string, total_bruto: string}
     */
    public static function calcular(array $linhas): array
    {
        $liquido = $imposto = '0.00';
        $saida = [];
        foreach ($linhas as $l) {
            $valor = self::arredondar(bcmul(self::n($l['quantidade']), self::n($l['preco_unitario']), 8), 2);
            $iva = self::excessoCentimo(bcdiv(bcmul($valor, self::n($l['taxa_imposto']), 8), '100', 8));
            $saida[] = ['valor' => $valor, 'imposto' => $iva, 'total' => bcadd($valor, $iva, 2)];
            $liquido = bcadd($liquido, $valor, 2);
            $imposto = bcadd($imposto, $iva, 2);
        }

        return ['linhas' => $saida, 'total_liquido' => $liquido, 'total_imposto' => $imposto, 'total_bruto' => bcadd($liquido, $imposto, 2)];
    }

    /** Arredondamento "half away from zero" a $casas decimais. */
    public static function arredondar(string $v, int $casas = 2): string
    {
        $ajuste = '0.'.str_repeat('0', $casas).'5';

        return bccomp($v, '0', 10) >= 0 ? bcadd($v, $ajuste, $casas) : bcsub($v, $ajuste, $casas);
    }

    /** Arredondamento ao cêntimo por excesso (valores ≥ 0). */
    public static function excessoCentimo(string $v): string
    {
        $truncado = bcadd($v, '0', 2);

        return bccomp($v, $truncado, 10) > 0 ? bcadd($truncado, '0.01', 2) : $truncado;
    }

    private static function n(string|float|int $v): string
    {
        return is_string($v) ? $v : number_format((float) $v, 8, '.', '');
    }
}
