<?php

namespace App\Services\Imobiliario;

use App\Services\Vendas\CalculadoraDocumento;

/**
 * Cálculo da renda de um período (`calcular` do legado, CT:655-685), em decimal exacto e sem base de dados:
 *   base = renda + encargos a acrescer − desconto            (proporcionais aos dias do período, se for o caso)
 *   IP   = base × «incide sobre» % × taxa %                  (25 % × 60 % = 15 % efectivo; isenção TOTAL 0, METADE ÷ 2)
 *   selo = base × (habitação ? 0,1 % : 0,4 %)                (0 se isento; nunca entra na factura)
 *   IVA  = (base + extras) × (alojamento ? 7 % : 0 %)
 *   total a facturar = base + extras + IVA;  total a receber = total a facturar − IP retido
 *   custo do senhorio = selo (se for seu encargo) + IP (se não for retido)
 * Correcções (P3): os extras (obras debitadas ao inquilino) entram no total e no IVA mas não no Imposto Predial nem no Selo — o legado
 * esquecia-os ao recalcular; o desconto passa a poder ser definido. As taxas vêm sempre de `taxas_imobiliario` (por validar).
 */
final class CalculadoraRenda
{
    /**
     * @param  array<string, mixed>  $c  retencao_ip, isencao_ip, selo_encargo, finalidade_fiscal, renda, encargos[{valor, incluido_na_renda}]
     * @param  array<string, float|int|string>  $t  taxas do ano (ip_taxa, ip_base, selo_habitacao, selo_outro, iva_arrendamento, iva_alojamento)
     * @param  array<string, mixed>  $o  renda, desconto, extras_total, factor (dados do período, sobre os do contrato)
     * @return array<string, mixed>
     */
    public static function calcular(array $c, array $t, array $o = []): array
    {
        $factor = (string) ($o['factor'] ?? '1');
        $renda = self::proporcional((string) ($o['renda'] ?? $c['renda']), $factor);
        $encargos = '0.00';
        foreach ($c['encargos'] ?? [] as $e) {
            if (empty($e['incluido_na_renda'])) {
                $encargos = bcadd($encargos, self::proporcional((string) $e['valor'], $factor), 2);
            }
        }
        $desconto = self::dinheiro((string) ($o['desconto'] ?? 0));
        $extras = self::dinheiro((string) ($o['extras_total'] ?? 0));
        $base = bcsub(bcadd($renda, $encargos, 2), $desconto, 2);
        if (bccomp($base, '0', 2) < 0) {
            $base = '0.00';
        }
        $ipBase = self::pct($base, (string) $t['ip_base']);
        $ip = self::pct($ipBase, (string) $t['ip_taxa']);
        $ip = match ($c['isencao_ip']) {
            'TOTAL' => '0.00',
            'METADE' => CalculadoraDocumento::arredondar(bcdiv($ip, '2', 8)),
            default => $ip,
        };
        $seloPct = (string) ($c['finalidade_fiscal'] === 'HABITACAO' ? $t['selo_habitacao'] : $t['selo_outro']);
        $selo = $c['selo_encargo'] === 'ISENTO' ? '0.00' : self::pct($base, $seloPct);
        $ivaTaxa = (string) ($c['finalidade_fiscal'] === 'ALOJAMENTO' ? $t['iva_alojamento'] : $t['iva_arrendamento']);
        $iva = self::pct(bcadd($base, $extras, 2), $ivaTaxa);
        $totalFacturar = bcadd(bcadd($base, $extras, 2), $iva, 2);
        $retido = ! empty($c['retencao_ip']) ? $ip : '0.00';
        $totalReceber = bcsub($totalFacturar, $retido, 2);
        $custo = bcadd($c['selo_encargo'] === 'SENHORIO' ? $selo : '0.00', ! empty($c['retencao_ip']) ? '0.00' : $ip, 2);

        return ['renda' => $renda, 'encargos' => $encargos, 'desconto' => $desconto, 'extras_total' => $extras, 'base' => $base, 'ip_base' => $ipBase, 'ip_valor' => $ip,
            'ip_retido' => ! empty($c['retencao_ip']), 'selo_pct' => self::taxa($seloPct), 'selo_valor' => $selo, 'selo_encargo' => $c['selo_encargo'],
            'iva_taxa' => self::taxa($ivaTaxa), 'iva_valor' => $iva, 'total_facturar' => $totalFacturar, 'total_receber' => $totalReceber, 'custo_senhorio' => $custo,
            'factor' => bcadd(bcadd($factor, '0', 6), '0', 6)];
    }

    /** Valor monetário com 2 casas, meio para cima. */
    public static function dinheiro(string $v): string
    {
        return CalculadoraDocumento::arredondar(number_format((float) $v, 8, '.', ''));
    }

    private static function proporcional(string $valor, string $factor): string
    {
        $v = number_format((float) $valor, 2, '.', '');

        return bccomp($factor, '1', 10) === 0 ? $v : CalculadoraDocumento::arredondar(bcmul($v, $factor, 10));
    }

    private static function pct(string $valor, string $taxa): string
    {
        return CalculadoraDocumento::arredondar(bcdiv(bcmul($valor, number_format((float) $taxa, 6, '.', ''), 10), '100', 10));
    }

    private static function taxa(string $t): string
    {
        return number_format((float) $t, 4, '.', '');
    }
}
