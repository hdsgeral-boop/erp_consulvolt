<?php

namespace App\Services\Compras;

use App\Exceptions\ErroNegocio;
use App\Services\Sistema\ServicoCambios;
use App\Services\Vendas\CalculadoraDocumento;

/**
 * Valores das linhas de compra em decimal exacto (o legado somava floats sem arredondar, UC:1670).
 *   líquido = arred(qtd × preço, 2) · IVA = arred(líquido × taxa / 100, 2) (valores do documento do fornecedor)
 * Moeda estrangeira: valores calculados na moeda e convertidos para Kz linha a linha (arred(valor × câmbio, 2)).
 */
final class CalculadoraCompra
{
    /**
     * @param  list<array{quantidade: string|float, preco_unitario: string|float, taxa_imposto: string|float}>  $linhas
     * @return array{linhas: list<array{liquido: string, imposto: string, liquido_kz: string, imposto_kz: string, preco_kz: string}>,
     *               liquido: string, imposto: string, bruto: string, liquido_kz: string, imposto_kz: string, bruto_kz: string}
     */
    public static function calcular(array $linhas, string $taxaCambio = '1'): array
    {
        $t = ['liquido' => '0.00', 'imposto' => '0.00', 'liquido_kz' => '0.00', 'imposto_kz' => '0.00'];
        $saida = [];
        foreach ($linhas as $l) {
            $taxa = (float) $l['taxa_imposto'];
            if ($taxa < 0 || $taxa > 100) {
                throw new ErroNegocio('A taxa de IVA tem de estar entre 0 e 100.', 'TAXA_INVALIDA', 422);
            }
            $liq = CalculadoraDocumento::arredondar(bcmul(self::n($l['quantidade']), self::n($l['preco_unitario']), 8));
            $iva = CalculadoraDocumento::arredondar(bcdiv(bcmul($liq, self::n($l['taxa_imposto']), 8), '100', 8));
            $liqKz = CalculadoraDocumento::arredondar(bcmul($liq, $taxaCambio, 8));
            $ivaKz = CalculadoraDocumento::arredondar(bcmul($iva, $taxaCambio, 8));
            $saida[] = ['liquido' => $liq, 'imposto' => $iva, 'liquido_kz' => $liqKz, 'imposto_kz' => $ivaKz,
                'preco_kz' => CalculadoraDocumento::arredondar(bcmul(self::n($l['preco_unitario']), $taxaCambio, 8))];
            foreach (['liquido' => $liq, 'imposto' => $iva, 'liquido_kz' => $liqKz, 'imposto_kz' => $ivaKz] as $k => $v) {
                $t[$k] = bcadd($t[$k], $v, 2);
            }
        }

        return ['linhas' => $saida] + $t + ['bruto' => bcadd($t['liquido'], $t['imposto'], 2), 'bruto_kz' => bcadd($t['liquido_kz'], $t['imposto_kz'], 2)];
    }

    /**
     * Moeda e câmbio de um documento de compra: AOA; câmbio manual; ou o da tabela até à data.
     *
     * @return array{codigo: string, taxa: string, taxa_id: ?int, manual: bool, estrangeira: bool}
     */
    public static function moeda(ServicoCambios $cambios, int $empresa, ?string $codigo, ?string $manual, string $data): array
    {
        $codigo = strtoupper($codigo ?: ServicoCambios::BASE);
        if ($codigo === ServicoCambios::BASE) {
            return ['codigo' => $codigo, 'taxa' => '1', 'taxa_id' => null, 'manual' => false, 'estrangeira' => false];
        }
        if ($manual !== null && $manual !== '') {
            if ((float) $manual <= 0) {
                throw new ErroNegocio('O câmbio tem de ser positivo.', 'CAMBIO_INVALIDO', 422);
            }

            return ['codigo' => $codigo, 'taxa' => number_format((float) $manual, 6, '.', ''), 'taxa_id' => null, 'manual' => true, 'estrangeira' => true];
        }
        $c = $cambios->obter($empresa, $codigo, $data)
            ?? throw new ErroNegocio("Não há câmbio de {$codigo} registado até {$data}. Registe o câmbio ou indique-o manualmente.", 'CAMBIO_EM_FALTA', 422);

        return ['codigo' => $codigo, 'taxa' => $c['taxa'], 'taxa_id' => $c['id'], 'manual' => false, 'estrangeira' => true];
    }

    private static function n(string|float|int $v): string
    {
        return is_string($v) ? $v : number_format((float) $v, 8, '.', '');
    }
}
