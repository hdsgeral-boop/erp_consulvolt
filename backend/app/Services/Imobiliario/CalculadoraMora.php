<?php

namespace App\Services\Imobiliario;

use App\Services\Vendas\CalculadoraDocumento;

/**
 * Mora de uma renda em atraso: juros simples sobre a dívida, ao mês comercial de 30 dias (`dívida × taxa/100 × dias/30`). A taxa é a do
 * contrato (`mora_mensal`, que o legado lia mas nunca gravava) ou, sem ela, a do ano do vencimento da renda (o legado usava a do ano
 * corrente). Só há mora se a dívida passar de 0,009 e o vencimento já tiver passado. Função pura, sem base de dados.
 */
final class CalculadoraMora
{
    /** Dívida mínima (em Kz) abaixo da qual se ignora (arredondamentos). */
    public const LIMIAR = '0.009';

    public static function diasDeAtraso(string $vencimento, string $hoje, string $divida): int
    {
        if (bccomp($divida, self::LIMIAR, 3) <= 0 || $vencimento >= $hoje) {
            return 0;
        }

        return CalculadoraPeriodos::diasEntre($vencimento, $hoje);
    }

    /** Juros de mora ao cêntimo (meio para cima); 0 sem atraso ou sem taxa. */
    public static function juros(string $divida, string $taxaMensal, int $diasAtraso): string
    {
        if ($diasAtraso <= 0 || bccomp($taxaMensal, '0', 6) <= 0 || bccomp($divida, self::LIMIAR, 3) <= 0) {
            return '0.00';
        }
        $v = bcdiv(bcmul(bcmul($divida, $taxaMensal, 10), (string) $diasAtraso, 10), '3000', 10);   // ÷100 (%) e ÷30 (dias)

        return CalculadoraDocumento::arredondar($v);
    }
}
