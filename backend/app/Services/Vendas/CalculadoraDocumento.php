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

    /**
     * POS e lavandaria: o preço INCLUI IVA e o desconto global é uma percentagem sobre o total com IVA
     * (construir, js/facturacao_agt.js:240-266). Para cada linha, o valor cobrado é arred(qtd × preço × (1 − desc));
     * a base é a maior base cuja soma com o imposto (arredondado por excesso) não excede o valor cobrado.
     * O cabeçalho fica coerente (líquido + imposto = bruto; o legado gravava o líquido e o imposto antes do desconto).
     *
     * @param  list<array{quantidade: string|float|int, preco_unitario: string|float|int, taxa_imposto: string|float|int}>  $linhas  preço com IVA
     * @return array{linhas: list<array{valor: string, imposto: string, total: string, preco_base: string}>, total_liquido: string, total_imposto: string, total_bruto: string}
     */
    public static function calcularComIva(array $linhas, string|float|int $percentagemDesconto = 0): array
    {
        $fator = bcsub('1', bcdiv(self::n($percentagemDesconto), '100', 8), 8);
        $liquido = $imposto = '0.00';
        $saida = [];
        foreach ($linhas as $l) {
            $taxa = self::n($l['taxa_imposto']);
            $cobrado = self::arredondar(bcmul(bcmul(self::n($l['quantidade']), self::n($l['preco_unitario']), 8), $fator, 8), 2);
            $v = self::arredondar(bcdiv($cobrado, bcadd('1', bcdiv($taxa, '100', 8), 8), 8), 2);
            $bruto = fn (string $b) => bcadd($b, self::excessoCentimo(bcdiv(bcmul($b, $taxa, 8), '100', 8)), 2);
            for ($k = 0; $k < 4 && bccomp($bruto($v), $cobrado, 2) !== 0; $k++) {
                $v = bcadd($v, bccomp($bruto($v), $cobrado, 2) > 0 ? '-0.01' : '0.01', 2);
            }
            if (bccomp($bruto($v), $cobrado, 2) > 0) {
                $v = bcsub($v, '0.01', 2);
            }
            $v = bccomp($v, '0', 2) < 0 ? '0.00' : $v;
            $iva = self::excessoCentimo(bcdiv(bcmul($v, $taxa, 8), '100', 8));
            $qtd = self::n($l['quantidade']);
            $saida[] = ['valor' => $v, 'imposto' => $iva, 'total' => bcadd($v, $iva, 2),
                'preco_base' => bccomp($qtd, '0', 8) > 0 ? self::arredondar(bcdiv($v, $qtd, 10), 6) : '0'];
            $liquido = bcadd($liquido, $v, 2);
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
