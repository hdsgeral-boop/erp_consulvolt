<?php

namespace App\Services\Imobiliario;

/**
 * Contas puras do negócio de compra e venda (sem base de dados; tudo em bcmath, nunca float). Reproduzem as fórmulas do legado (TR:88-115,
 * 262-354) com «meio para cima» ao cêntimo, que é o arredondamento do legado nos casos testáveis; as correcções estão documentadas no relatório da F6.
 *  - Impostos da transmissão: base = o MAIOR entre o preço e o valor patrimonial; Imposto Predial 2 % (TOTAL → 0; METADE → metade), Selo 0,3 % e
 *    escritura fixa. A isenção só toca no Imposto Predial. As taxas vêm de `taxas_imobiliario` («por validar»).
 *  - Custo de aquisição = preço + impostos + encargos capitalizáveis; os outros encargos são gasto do período.
 *  - Quota da fracção sobre o activo do imóvel: por área útil e depois bruta (sobre o total das fracções), senão partes iguais.
 *  - Resultado da venda: contabilístico = preço − valor líquido; do legado (`mais_valia`) = contabilístico − todos os encargos da venda.
 */
final class CalculadoraNegocios
{
    public const TIPOS = ['COMPRA' => ['rot' => 'Compra (a empresa adquire)', 'prefixo' => 'CP'], 'VENDA' => ['rot' => 'Venda (a empresa aliena)', 'prefixo' => 'VD']];

    public const FASES = [
        'NEGOCIACAO' => ['rot' => 'Em negociação', 'ordem' => 1, 'campo' => null], 'PROMESSA' => ['rot' => 'Promessa assinada', 'ordem' => 2, 'campo' => 'data_promessa'],
        'ESCRITURA' => ['rot' => 'Escritura feita', 'ordem' => 3, 'campo' => 'data_escritura'], 'REGISTADO' => ['rot' => 'Registado na Conservatória', 'ordem' => 4, 'campo' => 'data_registo'],
        'CONCLUIDO' => ['rot' => 'Concluído', 'ordem' => 5, 'campo' => null], 'CANCELADO' => ['rot' => 'Cancelado', 'ordem' => 0, 'campo' => null],
    ];

    /** `capitaliza` por omissão de cada tipo de encargo. */
    public const TIPOS_CUSTO = [
        'IMPOSTO' => ['rot' => 'Imposto Predial da transmissão', 'capitaliza' => true], 'SELO' => ['rot' => 'Imposto de Selo', 'capitaliza' => true],
        'ESCRITURA' => ['rot' => 'Escritura e notariado', 'capitaliza' => true], 'REGISTO' => ['rot' => 'Registo predial', 'capitaliza' => true],
        'MEDIACAO' => ['rot' => 'Comissão de mediação', 'capitaliza' => true], 'AVALIACAO' => ['rot' => 'Avaliação e vistoria', 'capitaliza' => true],
        'ADVOGADO' => ['rot' => 'Honorários jurídicos', 'capitaliza' => true], 'OUTRO' => ['rot' => 'Outro encargo', 'capitaliza' => false],
    ];

    public const ISENCOES = ['NAO' => 'Sem isenção', 'METADE' => 'Redução de 50% (OGE)', 'TOTAL' => 'Isento (OGE)'];

    public const FINALIDADES = ['HABITACAO' => 'Habitação', 'COMERCIO' => 'Comércio', 'ESCRITORIO' => 'Escritório', 'ARMAZEM' => 'Armazém', 'TERRENO' => 'Terreno', 'OUTRO' => 'Outro'];

    public const DESTINOS_CONTRATOS = ['TRANSMITE' => 'Transmitem-se ao comprador (a empresa deixa de os gerir)', 'MANTEM' => 'Mantêm-se nesta empresa (a venda não abrange o arrendamento)'];

    public const TIPOS_PAGAMENTO = ['SINAL' => 'Sinal', 'PRESTACAO' => 'Prestação', 'SALDO' => 'Saldo'];

    /** Taxas de arranque da transmissão (as de `taxas_imobiliario` sobrepõem-se). */
    public const TAXAS_TRANSMISSAO = ['ip_transmissao' => 2.0, 'selo_transmissao' => 0.3, 'escritura_fixo' => 2000.0, 'isencao_ate' => 0.0, 'isencao_metade_ate' => 0.0];

    public static function dinheiro(mixed $v): string
    {
        $s = is_numeric($v) ? number_format((float) $v, 8, '.', '') : '0.00000000';

        return self::arredondar($s);
    }

    /** «Meio para cima» ao cêntimo, sobre uma string decimal (bcmath), também para negativos. */
    public static function arredondar(string $v): string
    {
        $neg = str_starts_with($v, '-');
        $abs = ltrim($v, '-');
        $r = bcadd($abs, '0.005', 8);
        $r = bcadd(bcdiv(bcmul($r, '100', 8), '1', 0), '0', 0);
        $r = bcdiv($r, '100', 2);

        return $neg && bccomp($r, '0', 2) !== 0 ? '-'.$r : $r;
    }

    /**
     * Impostos da transmissão.
     *
     * @param  array<string, mixed>  $t  taxas (ip_transmissao, selo_transmissao, escritura_fixo)
     * @return array{base: string, base_e_vpt: bool, ip_taxa: string, ip_valor: string, selo_pct: string, selo_valor: string, escritura_valor: string, total_impostos: string, isencao: string}
     */
    public static function impostos(mixed $preco, mixed $vpt, array $t, string $isencao = 'NAO'): array
    {
        $p = self::dinheiro($preco);
        $v = self::dinheiro($vpt);
        $base = bccomp($v, $p, 2) > 0 ? $v : $p;
        $ipTaxa = number_format((float) ($t['ip_transmissao'] ?? self::TAXAS_TRANSMISSAO['ip_transmissao']), 4, '.', '');
        $seloTaxa = number_format((float) ($t['selo_transmissao'] ?? self::TAXAS_TRANSMISSAO['selo_transmissao']), 4, '.', '');
        $ip = self::arredondar(bcdiv(bcmul($base, $ipTaxa, 8), '100', 8));
        if ($isencao === 'TOTAL') {
            $ip = '0.00';
        } elseif ($isencao === 'METADE') {
            $ip = self::arredondar(bcdiv($ip, '2', 8));
        }
        $selo = self::arredondar(bcdiv(bcmul($base, $seloTaxa, 8), '100', 8));
        $escritura = self::dinheiro($t['escritura_fixo'] ?? self::TAXAS_TRANSMISSAO['escritura_fixo']);

        return ['base' => $base, 'base_e_vpt' => bccomp($v, $p, 2) > 0, 'ip_taxa' => $ipTaxa, 'ip_valor' => $ip, 'selo_pct' => $seloTaxa, 'selo_valor' => $selo, 'escritura_valor' => $escritura,
            'total_impostos' => bcadd(bcadd($ip, $selo, 2), $escritura, 2), 'isencao' => $isencao];
    }

    /**
     * Sugestão (só sugestão) de isenção do OGE para habitação, pelos escalões do ano.
     *
     * @param  array<string, mixed>  $t
     * @return array{isencao: string, texto: string}
     */
    public static function sugerirIsencao(mixed $valor, string $finalidade, array $t): array
    {
        if ($finalidade !== 'HABITACAO') {
            return ['isencao' => 'NAO', 'texto' => 'As isenções do OGE são para habitação.'];
        }
        $v = self::dinheiro($valor);
        $ate = self::dinheiro($t['isencao_ate'] ?? 0);
        $metade = self::dinheiro($t['isencao_metade_ate'] ?? 0);
        $fmt = fn (string $x) => number_format((float) $x, 0, ',', ' ');
        if (bccomp($ate, '0', 2) > 0 && bccomp($v, $ate, 2) <= 0) {
            return ['isencao' => 'TOTAL', 'texto' => 'Habitação até '.$fmt($ate).' Kz está isenta no OGE do ano.'];
        }
        if (bccomp($metade, '0', 2) > 0 && bccomp($v, $metade, 2) <= 0) {
            return ['isencao' => 'METADE', 'texto' => 'Habitação entre '.$fmt($ate).' e '.$fmt($metade).' Kz tem redução de 50%.'];
        }

        return ['isencao' => 'NAO', 'texto' => bccomp($ate, '0', 2) > 0 ? 'Acima dos escalões de isenção do ano.' : 'Não há escalões de isenção configurados para este ano.'];
    }

    /**
     * Custo de aquisição de uma compra: preço + impostos + encargos capitalizáveis; o resto é gasto do período.
     *
     * @param  list<array{valor: mixed, capitaliza: bool}>  $custos
     * @return array{preco: string, impostos: string, encargos: string, total: string, gasto_periodo: string}
     */
    public static function custoAquisicao(mixed $preco, mixed $impostos, array $custos): array
    {
        $cap = '0.00';
        $gasto = '0.00';
        foreach ($custos as $c) {
            if (! empty($c['capitaliza'])) {
                $cap = bcadd($cap, self::dinheiro($c['valor']), 2);
            } else {
                $gasto = bcadd($gasto, self::dinheiro($c['valor']), 2);
            }
        }
        $p = self::dinheiro($preco);
        $i = self::dinheiro($impostos);

        return ['preco' => $p, 'impostos' => $i, 'encargos' => $cap, 'total' => bcadd(bcadd($p, $i, 2), $cap, 2), 'gasto_periodo' => $gasto];
    }

    /**
     * Quota-parte da fracção no activo do imóvel: sem fracção ou fracção única → 1; por área útil e depois bruta (sobre o total das fracções);
     * senão partes iguais.
     *
     * @param  list<array{id: int, area_util: mixed, area_bruta: mixed}>  $fracoes  todas as fracções do imóvel
     * @return array{quota: string, criterio: string}
     */
    public static function quota(?int $fracaoId, array $fracoes): array
    {
        if ($fracaoId === null) {
            return ['quota' => '1.000000', 'criterio' => 'imóvel inteiro'];
        }
        $alvo = null;
        foreach ($fracoes as $f) {
            if ((int) $f['id'] === $fracaoId) {
                $alvo = $f;
            }
        }
        if ($alvo === null) {
            return ['quota' => '1.000000', 'criterio' => 'fracção não encontrada no imóvel'];
        }
        if (count($fracoes) === 1) {
            return ['quota' => '1.000000', 'criterio' => 'fracção única do imóvel'];
        }
        foreach (['area_util' => 'área útil', 'area_bruta' => 'área bruta'] as $campo => $rot) {
            $total = '0';
            foreach ($fracoes as $f) {
                $total = bcadd($total, number_format((float) ($f[$campo] ?? 0), 2, '.', ''), 2);
            }
            $dela = number_format((float) ($alvo[$campo] ?? 0), 2, '.', '');
            if (bccomp($total, '0', 2) > 0 && bccomp($dela, '0', 2) > 0) {
                return ['quota' => bcdiv($dela, $total, 6), 'criterio' => $rot];
            }
        }

        return ['quota' => bcdiv('1', (string) count($fracoes), 6), 'criterio' => 'partes iguais entre '.count($fracoes).' fracções'];
    }

    /**
     * Resultado de uma venda sobre a quota do activo vendida.
     *
     * @return array{custo: string, amortizacoes: string, valor_liquido: string, resultado_contabilistico: string, mais_valia: string, tipo: string}
     */
    public static function resultadoVenda(mixed $preco, mixed $custoTotal, mixed $amortizacoesTotal, string $quota, mixed $encargosVenda): array
    {
        $custo = self::arredondar(bcmul(self::dinheiro($custoTotal), $quota, 8));
        $amort = self::arredondar(bcmul(self::dinheiro($amortizacoesTotal), $quota, 8));
        $liquido = bcsub($custo, $amort, 2);
        $resultado = bcsub(self::dinheiro($preco), $liquido, 2);
        $maisValia = bcsub($resultado, self::dinheiro($encargosVenda), 2);

        return ['custo' => $custo, 'amortizacoes' => $amort, 'valor_liquido' => $liquido, 'resultado_contabilistico' => $resultado, 'mais_valia' => $maisValia,
            'tipo' => bccomp($maisValia, '0', 2) >= 0 ? 'MAIS_VALIA' : 'MENOS_VALIA'];
    }
}
