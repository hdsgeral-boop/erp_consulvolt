<?php

namespace Tests\Unit;

use App\Services\Imobiliario\CalculadoraActualizacao;
use App\Services\Imobiliario\CalculadoraMargem;
use App\Services\Imobiliario\CalculadoraMora;
use App\Services\Imobiliario\CalculadoraPeriodos;
use App\Services\Imobiliario\CalculadoraRenda;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Gestão Imobiliária, Onda 2: fórmulas puras de contratos e rendas, com números calculados à mão (fixtures do legado onde o legado está
 * certo e a correcção explícita onde foi corrigido): renda, Imposto Predial, Selo, IVA, retenção, pro-rata, actualização composta e linear,
 * períodos e vencimentos, mora e margem do subarrendamento (mensalizada e à moda do legado).
 */
final class ImobiliarioCalculadorasTest extends TestCase
{
    private const TAXAS = ['ip_taxa' => 25, 'ip_base' => 60, 'selo_habitacao' => 0.1, 'selo_outro' => 0.4, 'iva_arrendamento' => 0, 'iva_alojamento' => 7];

    /** @param  array<string, mixed>  $c */
    private function contrato(array $c = []): array
    {
        return $c + ['retencao_ip' => true, 'isencao_ip' => 'NAO', 'selo_encargo' => 'SENHORIO', 'finalidade_fiscal' => 'HABITACAO', 'renda' => 100000, 'encargos' => []];
    }

    // ---------------------------------------------------------------- renda

    #[Test]
    public function renda_de_100000_em_habitacao_com_retencao_bate_com_o_legado(): void
    {
        $r = CalculadoraRenda::calcular($this->contrato(), self::TAXAS);
        $this->assertSame('100000.00', $r['base']);
        $this->assertSame('60000.00', $r['ip_base']);      // 100 000 × 60 %
        $this->assertSame('15000.00', $r['ip_valor']);     // 60 000 × 25 %  (15 % efectivo)
        $this->assertSame('100.00', $r['selo_valor']);     // 0,1 % da base; não entra na factura
        $this->assertSame('0.00', $r['iva_valor']);
        $this->assertSame('100000.00', $r['total_facturar']);
        $this->assertSame('85000.00', $r['total_receber']);   // 100 000 − 15 000 retidos
        $this->assertSame('100.00', $r['custo_senhorio']);    // só o selo, que é encargo do senhorio
    }

    #[Test]
    public function sem_retencao_o_imposto_predial_e_custo_do_senhorio(): void
    {
        $r = CalculadoraRenda::calcular($this->contrato(['retencao_ip' => false]), self::TAXAS);
        $this->assertSame('100000.00', $r['total_receber']);
        $this->assertSame('15100.00', $r['custo_senhorio']);   // selo 100 + IP 15 000
    }

    #[Test]
    public function isencoes_do_imposto_predial_e_do_selo(): void
    {
        $this->assertSame('7500.00', CalculadoraRenda::calcular($this->contrato(['isencao_ip' => 'METADE']), self::TAXAS)['ip_valor']);
        $this->assertSame('92500.00', CalculadoraRenda::calcular($this->contrato(['isencao_ip' => 'METADE']), self::TAXAS)['total_receber']);
        $total = CalculadoraRenda::calcular($this->contrato(['isencao_ip' => 'TOTAL']), self::TAXAS);
        $this->assertSame('0.00', $total['ip_valor']);
        $this->assertSame('100000.00', $total['total_receber']);
        $isento = CalculadoraRenda::calcular($this->contrato(['selo_encargo' => 'ISENTO']), self::TAXAS);
        $this->assertSame('0.00', $isento['selo_valor']);
        $this->assertSame('0.00', $isento['custo_senhorio']);
        $inq = CalculadoraRenda::calcular($this->contrato(['selo_encargo' => 'INQUILINO']), self::TAXAS);
        $this->assertSame('100.00', $inq['selo_valor']);
        $this->assertSame('0.00', $inq['custo_senhorio']);   // o selo é do inquilino
    }

    #[Test]
    public function comercio_paga_selo_de_0_4_e_alojamento_tem_iva_de_7(): void
    {
        $this->assertSame('400.00', CalculadoraRenda::calcular($this->contrato(['finalidade_fiscal' => 'COMERCIO']), self::TAXAS)['selo_valor']);
        $a = CalculadoraRenda::calcular($this->contrato(['finalidade_fiscal' => 'ALOJAMENTO']), self::TAXAS);
        $this->assertSame('7000.00', $a['iva_valor']);
        $this->assertSame('107000.00', $a['total_facturar']);
        $this->assertSame('92000.00', $a['total_receber']);   // 107 000 − IP 15 000
    }

    #[Test]
    public function encargos_a_acrescer_e_desconto_entram_na_base_e_os_incluidos_nao(): void
    {
        $r = CalculadoraRenda::calcular($this->contrato(['encargos' => [['valor' => 20000, 'incluido_na_renda' => false], ['valor' => 5000, 'incluido_na_renda' => true]]]),
            self::TAXAS, ['desconto' => 10000]);
        $this->assertSame('20000.00', $r['encargos']);
        $this->assertSame('110000.00', $r['base']);        // 100 000 + 20 000 − 10 000
        $this->assertSame('66000.00', $r['ip_base']);
        $this->assertSame('16500.00', $r['ip_valor']);
    }

    #[Test]
    public function extras_entram_no_total_e_no_iva_mas_nao_no_imposto_predial_nem_no_selo(): void
    {
        $r = CalculadoraRenda::calcular($this->contrato(['finalidade_fiscal' => 'ALOJAMENTO']), self::TAXAS, ['extras_total' => 30000]);
        $this->assertSame('15000.00', $r['ip_valor']);
        $this->assertSame('100000.00', $r['base']);
        $this->assertSame('9100.00', $r['iva_valor']);       // (100 000 + 30 000) × 7 %
        $this->assertSame('139100.00', $r['total_facturar']);
        $this->assertSame('124100.00', $r['total_receber']);
        $semExtras = CalculadoraRenda::calcular($this->contrato(), self::TAXAS, ['extras_total' => 30000]);
        $this->assertSame('130000.00', $semExtras['total_facturar']);
        $this->assertSame('100.00', $semExtras['selo_valor']);
    }

    #[Test]
    public function pro_rata_do_primeiro_periodo_16_de_31_dias(): void
    {
        $pr = CalculadoraPeriodos::factorProRata('2026-01-01', '2026-01-31', '2026-01-16', null);
        $this->assertSame(['factor' => '0.5161290322', 'cobertos' => 16, 'total' => 31], $pr);
        $r = CalculadoraRenda::calcular($this->contrato(), self::TAXAS, ['factor' => $pr['factor']]);
        $this->assertSame('51612.90', $r['renda']);          // 100 000 × 16 / 31
        $this->assertSame('30967.74', $r['ip_base']);
        $this->assertSame('7741.94', $r['ip_valor']);        // 7 741,935 arredondado ao cêntimo, meio para cima
        $this->assertSame('51.61', $r['selo_valor']);
        $this->assertSame('43870.96', $r['total_receber']);
    }

    #[Test]
    public function pro_rata_do_ultimo_periodo_e_o_legado_cobra_sempre_inteiro(): void
    {
        $this->assertSame('0.4666666666', CalculadoraPeriodos::factorProRata('2026-06-01', '2026-06-30', '2026-01-16', '2026-06-14')['factor']);   // 14 / 30
        $this->assertSame('1.0000000000', CalculadoraPeriodos::factorProRata('2026-02-01', '2026-02-28', '2026-01-16', null)['factor']);
        // contrato com pro_rata = false (o legado): o primeiro e o último período são inteiros
        $this->assertSame('1.0000000000', CalculadoraPeriodos::factorProRata('2026-01-01', '2026-01-31', '2026-01-16', null, false)['factor']);
        $this->assertSame('1.0000000000', CalculadoraPeriodos::factorProRata('2026-06-01', '2026-06-30', '2026-01-16', '2026-06-14', false)['factor']);
        $renda = CalculadoraRenda::calcular($this->contrato(), self::TAXAS, ['factor' => '0.4666666666']);
        $this->assertSame('46666.67', $renda['renda']);
    }

    // ---------------------------------------------------------------- actualização

    #[Test]
    public function actualizacao_percentual_e_composta(): void
    {
        $c = ['renda' => 100000, 'actualizacao' => 'PERC', 'actualizacao_valor' => 5, 'actualizacao_meses' => 12, 'inicio' => '2024-01-10'];
        $this->assertSame('100000.00', CalculadoraActualizacao::rendaNoPeriodo($c, '2024-06'));
        $this->assertSame('100000.00', CalculadoraActualizacao::rendaNoPeriodo($c, '2024-12'));
        $this->assertSame('105000.00', CalculadoraActualizacao::rendaNoPeriodo($c, '2025-01'));
        $this->assertSame('105000.00', CalculadoraActualizacao::rendaNoPeriodo($c, '2025-12'));
        $this->assertSame('110250.00', CalculadoraActualizacao::rendaNoPeriodo($c, '2026-01'));   // 100 000 × 1,05²
    }

    #[Test]
    public function actualizacao_por_valor_fixo_e_linear_e_a_base_manual_reinicia_a_contagem(): void
    {
        $c = ['renda' => 100000, 'actualizacao' => 'VALOR', 'actualizacao_valor' => 10000, 'actualizacao_meses' => 12, 'inicio' => '2024-01-10'];
        $this->assertSame('120000.00', CalculadoraActualizacao::rendaNoPeriodo($c, '2026-01'));
        $base = ['actualizacao_base' => '2026-01-01'] + $c + ['actualizacao' => 'PERC'];
        $base['actualizacao'] = 'PERC';
        $base['actualizacao_valor'] = 5;
        $this->assertSame('100000.00', CalculadoraActualizacao::rendaNoPeriodo($base, '2026-12'));
        $this->assertSame('105000.00', CalculadoraActualizacao::rendaNoPeriodo($base, '2027-01'));
        $this->assertSame('100000.00', CalculadoraActualizacao::rendaNoPeriodo(['actualizacao' => 'NAO'] + $c, '2030-01'));
    }

    // ---------------------------------------------------------------- períodos

    #[Test]
    public function limites_vencimentos_e_periodos(): void
    {
        $this->assertSame(['2026-11-01', '2027-01-31'], CalculadoraPeriodos::limites('2026-11', 'TRIMESTRAL'));
        $this->assertSame(['2026-02-01', '2026-02-28'], CalculadoraPeriodos::limites('2026-02', 'MENSAL'));
        $this->assertSame('2026-11-28', CalculadoraPeriodos::vencimento('2026-11-01', '2027-01-31', true, 28));
        $this->assertSame('2027-01-28', CalculadoraPeriodos::vencimento('2026-11-01', '2027-01-31', false, 28));
        $this->assertSame('2026-02-28', CalculadoraPeriodos::vencimento('2026-02-01', '2026-02-28', true, 31));   // limitado ao fim do mês
        $this->assertSame('2027-02', CalculadoraPeriodos::somarMeses('2026-11', 3));
        $this->assertSame('2025-04', CalculadoraPeriodos::somarMeses('2026-03', -11));
        $this->assertSame('2026-02-28', CalculadoraPeriodos::somarMesesData('2026-01-31', 1));
        $this->assertSame(['2026-01', '2026-02', '2026-03'], CalculadoraPeriodos::periodosDoContrato('2026-01-16', null, 'MENSAL', '2026-01-01', '2026-03-31'));
        $this->assertSame(['2026-02', '2026-05', '2026-08', '2026-11'], CalculadoraPeriodos::periodosDoContrato('2026-02-10', null, 'TRIMESTRAL', '2026-01-01', '2026-12-31'));
        $this->assertSame(['2026-02', '2026-05'], CalculadoraPeriodos::periodosDoContrato('2026-02-10', '2026-07-31', 'TRIMESTRAL', '2026-01-01', '2026-12-31'));
        $this->assertSame(['2026-05', '2026-08', '2026-11'], CalculadoraPeriodos::periodosDoContrato('2026-02-10', null, 'TRIMESTRAL', '2026-04-01', '2026-12-31'));
    }

    // ---------------------------------------------------------------- mora

    #[Test]
    public function mora_de_85000_com_45_dias_a_1_por_cento_ao_mes_sao_1275(): void
    {
        $this->assertSame(45, CalculadoraMora::diasDeAtraso('2026-02-05', '2026-03-22', '85000.00'));
        $this->assertSame('1275.00', CalculadoraMora::juros('85000.00', '1', 45));
        $this->assertSame('666.67', CalculadoraMora::juros('100000.00', '2', 10));   // 100 000 × 2 % × 10/30
        $this->assertSame('0.00', CalculadoraMora::juros('85000.00', '0', 45));      // sem taxa
        $this->assertSame('0.00', CalculadoraMora::juros('85000.00', '1', 0));       // sem atraso
        $this->assertSame(0, CalculadoraMora::diasDeAtraso('2026-03-22', '2026-03-22', '85000.00'));   // vence hoje
        $this->assertSame(0, CalculadoraMora::diasDeAtraso('2026-02-05', '2026-03-22', '0.00'));       // sem dívida
    }

    // ---------------------------------------------------------------- margem

    /** @return list<array<string, mixed>> */
    private function rendasMargem(): array
    {
        $r = [['fracao_id' => 1, 'posicao' => 'INQUILINO', 'periodo' => '2026-01', 'meses' => 12, 'total' => '120000.00', 'contrato' => 'AT 2026/0001']];
        for ($m = 1; $m <= 12; $m++) {
            $r[] = ['fracao_id' => 1, 'posicao' => 'SENHORIO', 'periodo' => sprintf('2026-%02d', $m), 'meses' => 1, 'total' => '15000.00', 'contrato' => 'SB 2026/0001'];
        }

        return $r;
    }

    #[Test]
    public function margem_mensaliza_a_renda_tomada_anual(): void
    {
        $m = CalculadoraMargem::calcular($this->rendasMargem(), '2026-03', '2026-06', true);
        $l = $m['linhas'][0];
        $this->assertSame('40000.00', $l['paga']);        // 120 000 / 12 × 4 meses
        $this->assertSame('60000.00', $l['recebe']);
        $this->assertSame('20000.00', $l['margem']);
        $this->assertSame('50.0', $l['taxa']);
        $this->assertCount(4, $l['periodos']);
        $this->assertSame(['periodo' => '2026-03', 'paga' => '10000.00', 'recebe' => '15000.00', 'margem' => '5000.00'], $l['periodos'][0]);
        $anual = CalculadoraMargem::calcular($this->rendasMargem(), '2026-01', '2026-12', true);
        $this->assertSame(['paga' => '120000.00', 'recebe' => '180000.00', 'margem' => '60000.00'], $anual['totais']);
    }

    #[Test]
    public function margem_do_legado_nao_mensaliza_e_distorce_a_comparacao(): void
    {
        $m = CalculadoraMargem::calcular($this->rendasMargem(), '2026-03', '2026-06', false);
        $l = $m['linhas'][0];
        $this->assertSame('0.00', $l['paga']);            // a renda anual começa em 2026-01, fora do intervalo
        $this->assertSame('60000.00', $l['recebe']);
        $this->assertSame('60000.00', $l['margem']);
        $this->assertNull($l['taxa']);
    }

    #[Test]
    public function margem_negativa_vazia_e_ordenacao_pelas_piores(): void
    {
        $r = $this->rendasMargem();
        foreach (['2026-01', '2026-02', '2026-03'] as $p) {
            $r[] = ['fracao_id' => 2, 'posicao' => 'INQUILINO', 'periodo' => $p, 'meses' => 1, 'total' => '20000.00', 'contrato' => 'AT 2026/0002'];
            $r[] = ['fracao_id' => 2, 'posicao' => 'SENHORIO', 'periodo' => $p, 'meses' => 1, 'total' => '15000.00', 'contrato' => 'SB 2026/0002'];
        }
        foreach (['2026-01', '2026-02'] as $p) {
            $r[] = ['fracao_id' => 3, 'posicao' => 'INQUILINO', 'periodo' => $p, 'meses' => 1, 'total' => '10000.00', 'contrato' => 'AT 2026/0003'];
        }
        $m = CalculadoraMargem::calcular($r, '2026-01', '2026-03', true);
        $this->assertSame([3, 2, 1], array_column($m['linhas'], 'fracao_id'));   // −20 000, −15 000, +… : piores primeiro
        $f2 = $m['linhas'][1];
        $this->assertSame('-15000.00', $f2['margem']);
        $this->assertSame('-25.0', $f2['taxa']);
        $this->assertTrue($f2['negativa']);
        $this->assertTrue($m['linhas'][0]['vazia']);
        $this->assertSame(2, $m['negativas']);
        $this->assertSame(1, $m['vazias']);
    }
}
