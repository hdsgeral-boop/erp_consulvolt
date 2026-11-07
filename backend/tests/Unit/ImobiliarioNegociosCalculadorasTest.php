<?php

namespace Tests\Unit;

use App\Services\Imobiliario\CalculadoraNegocios;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Gestão Imobiliária, Onda 5 (F6): contas puras de compra e venda, com números calculados à mão (as fixtures do legado onde o legado está certo; as correcções
 * assinaladas): impostos da transmissão com a base no maior entre o preço e o VPT, isenções só no Imposto Predial, meio-cêntimo, sugestão de isenção,
 * custo de aquisição, quota da fracção e resultado da venda.
 */
final class ImobiliarioNegociosCalculadorasTest extends TestCase
{
    private const TAXAS = ['ip_transmissao' => 2, 'selo_transmissao' => 0.3, 'escritura_fixo' => 2000, 'isencao_ate' => 40000000, 'isencao_metade_ate' => 100000000];

    #[Test]
    public function impostos_da_transmissao_com_a_base_no_maior_entre_o_preco_e_o_vpt(): void
    {
        // preço 30 000 000, VPT 35 000 000 → base 35 000 000; IP 2 % = 700 000; selo 0,3 % = 105 000; escritura 2 000
        $i = CalculadoraNegocios::impostos(30000000, 35000000, self::TAXAS);
        $this->assertSame(['35000000.00', true, '700000.00', '105000.00', '2000.00', '807000.00'], [$i['base'], $i['base_e_vpt'], $i['ip_valor'], $i['selo_valor'], $i['escritura_valor'], $i['total_impostos']]);
        // o preço maior manda: 40 000 000 sem VPT → IP 800 000, selo 120 000
        $j = CalculadoraNegocios::impostos(40000000, 0, self::TAXAS);
        $this->assertSame(['40000000.00', false, '800000.00', '120000.00', '922000.00'], [$j['base'], $j['base_e_vpt'], $j['ip_valor'], $j['selo_valor'], $j['total_impostos']]);
    }

    #[Test]
    public function a_isencao_so_toca_no_imposto_predial(): void
    {
        $t = CalculadoraNegocios::impostos(30000000, 35000000, self::TAXAS, 'TOTAL');
        $this->assertSame(['0.00', '105000.00', '2000.00', '107000.00'], [$t['ip_valor'], $t['selo_valor'], $t['escritura_valor'], $t['total_impostos']]);
        $m = CalculadoraNegocios::impostos(30000000, 35000000, self::TAXAS, 'METADE');
        $this->assertSame(['350000.00', '105000.00', '457000.00'], [$m['ip_valor'], $m['selo_valor'], $m['total_impostos']]);
    }

    #[Test]
    public function arredondamento_meio_para_cima_ao_centimo(): void
    {
        $this->assertSame('0.01', CalculadoraNegocios::arredondar('0.005'));
        $this->assertSame('-0.01', CalculadoraNegocios::arredondar('-0.005'));
        $this->assertSame('2.68', CalculadoraNegocios::arredondar('2.675'));
        $this->assertSame('0.00', CalculadoraNegocios::arredondar('0.004'));
        // 12 345,67: IP = 246,9134 → 246,91; selo = 37,03701 → 37,04; total 246,91 + 37,04 + 2 000 = 2 283,95
        $i = CalculadoraNegocios::impostos('12345.67', 0, self::TAXAS);
        $this->assertSame(['246.91', '37.04', '2283.95'], [$i['ip_valor'], $i['selo_valor'], $i['total_impostos']]);
    }

    #[Test]
    public function sugestao_de_isencao_por_escaloes_e_so_para_habitacao(): void
    {
        $this->assertSame('TOTAL', CalculadoraNegocios::sugerirIsencao(40000000, 'HABITACAO', self::TAXAS)['isencao']);
        $this->assertSame('METADE', CalculadoraNegocios::sugerirIsencao('40000000.01', 'HABITACAO', self::TAXAS)['isencao']);
        $this->assertSame('METADE', CalculadoraNegocios::sugerirIsencao(100000000, 'HABITACAO', self::TAXAS)['isencao']);
        $this->assertSame('NAO', CalculadoraNegocios::sugerirIsencao('100000000.01', 'HABITACAO', self::TAXAS)['isencao']);
        $this->assertSame('As isenções do OGE são para habitação.', CalculadoraNegocios::sugerirIsencao(1, 'COMERCIO', self::TAXAS)['texto']);
        $this->assertSame('Não há escalões de isenção configurados para este ano.', CalculadoraNegocios::sugerirIsencao(1, 'HABITACAO', ['isencao_ate' => 0])['texto']);
    }

    #[Test]
    public function custo_de_aquisicao_capitaliza_os_encargos_marcados_e_o_resto_e_gasto_do_periodo(): void
    {
        $c = CalculadoraNegocios::custoAquisicao(30000000, 807000, [['valor' => 300000, 'capitaliza' => true], ['valor' => 50000, 'capitaliza' => false], ['valor' => '1000.50', 'capitaliza' => true]]);
        $this->assertSame(['31108000.50', '50000.00', '301000.50'], [$c['total'], $c['gasto_periodo'], $c['encargos']]);
    }

    #[Test]
    public function quota_da_fraccao_por_area_util_depois_bruta_depois_partes_iguais(): void
    {
        $f = [['id' => 1, 'area_util' => 60, 'area_bruta' => 70], ['id' => 2, 'area_util' => 40, 'area_bruta' => 50]];
        $this->assertSame(['0.600000', 'área útil'], array_values(CalculadoraNegocios::quota(1, $f)));
        $this->assertSame('0.400000', CalculadoraNegocios::quota(2, $f)['quota']);
        $bruta = [['id' => 1, 'area_util' => 0, 'area_bruta' => 75], ['id' => 2, 'area_util' => 0, 'area_bruta' => 25]];
        $this->assertSame(['0.750000', 'área bruta'], array_values(CalculadoraNegocios::quota(1, $bruta)));
        $tres = [['id' => 1, 'area_util' => 0, 'area_bruta' => 0], ['id' => 2, 'area_util' => 0, 'area_bruta' => 0], ['id' => 3, 'area_util' => 0, 'area_bruta' => 0]];
        $this->assertSame(['0.333333', 'partes iguais entre 3 fracções'], array_values(CalculadoraNegocios::quota(3, $tres)));
        $this->assertSame('1.000000', CalculadoraNegocios::quota(null, $f)['quota']);
        $this->assertSame(['1.000000', 'fracção única do imóvel'], array_values(CalculadoraNegocios::quota(9, [['id' => 9, 'area_util' => 1, 'area_bruta' => 1]])));
    }

    #[Test]
    public function resultado_da_venda_sobre_a_quota_vendida(): void
    {
        // preço 50 000 000; activo 100 000 000 com 40 000 000 amortizados; quota 0,5 → custo 50 000 000, amortizado 20 000 000, líquido 30 000 000, resultado 20 000 000
        $r = CalculadoraNegocios::resultadoVenda(50000000, 100000000, 40000000, '0.500000', 300000);
        $this->assertSame(['50000000.00', '20000000.00', '30000000.00', '20000000.00', '19700000.00', 'MAIS_VALIA'],
            [$r['custo'], $r['amortizacoes'], $r['valor_liquido'], $r['resultado_contabilistico'], $r['mais_valia'], $r['tipo']]);
        // menos-valia: preço 20 000 000 sobre um líquido de 60 000 000 → −40 000 000; com 100 000 de encargos o do legado dá −40 100 000
        $m = CalculadoraNegocios::resultadoVenda(20000000, 100000000, 40000000, '1.000000', 100000);
        $this->assertSame(['-40000000.00', '-40100000.00', 'MENOS_VALIA'], [$m['resultado_contabilistico'], $m['mais_valia'], $m['tipo']]);
    }
}
