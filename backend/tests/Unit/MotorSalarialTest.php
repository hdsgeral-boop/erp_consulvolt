<?php

namespace Tests\Unit;

use App\Services\RH\MotorSalarial;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Motor salarial: tabela de IRT do engine_v2.js, INSS, isenções, faltas, horas extra e avençados (modos ATUAL e LEGADO). */
final class MotorSalarialTest extends TestCase
{
    private const INFOTIPOS = [
        1 => ['id' => 1, 'tipo' => 'VENCIMENTO', 'nome' => 'Salário Base', 'inss' => true, 'irt' => 'true', 'base_horaria' => true],
        2 => ['id' => 2, 'tipo' => 'VENCIMENTO', 'nome' => 'Subsídio de alimentação', 'inss' => false, 'irt' => 'conditional_30k'],
        3 => ['id' => 3, 'tipo' => 'VENCIMENTO', 'nome' => 'Subsídio de transporte', 'inss' => false, 'irt' => 'conditional_30k'],
        4 => ['id' => 4, 'tipo' => 'VENCIMENTO', 'nome' => 'Horas Extras', 'inss' => true, 'irt' => 'true'],
        5 => ['id' => 5, 'tipo' => 'DESCONTO', 'nome' => 'Adiantamento', 'inss' => false, 'irt' => 'false'],
        6 => ['id' => 6, 'tipo' => 'OUTROS', 'nome' => 'Dias de Trabalho', 'inss' => false, 'irt' => 'false'],
        7 => ['id' => 7, 'tipo' => 'VENCIMENTO', 'nome' => 'Ajudas de custo', 'inss' => false, 'irt' => 'false'],
    ];

    private function calc(array $lancamentos, array $extra = [], string $modo = 'ATUAL'): array
    {
        return MotorSalarial::calcular($extra + ['id' => 1, 'dias_contrato' => 22, 'lancamentos' => $lancamentos], self::INFOTIPOS, ['modo' => $modo]);
    }

    public static function pontosIrt(): array
    {
        return [
            'isento' => ['150000', '0.00'], 'salto do 2.º escalão' => ['150000.01', '12500.00'], 'limite 2.º' => ['200000', '20500.00'],
            'limite 3.º' => ['300000', '49250.00'], 'limite 5.º' => ['1000000', '187250.00'], 'último escalão' => ['12000000', '2842250.00'],
        ];
    }

    #[Test]
    #[DataProvider('pontosIrt')]
    public function tabela_de_irt_do_engine_v2(string $base, string $esperado): void
    {
        $this->assertSame($esperado, MotorSalarial::arred(MotorSalarial::irt($base)));
    }

    #[Test]
    public function salario_normal_com_isencoes_de_30000_pela_flag(): void
    {
        $r = $this->calc([['infotipo_id' => 1, 'valor' => 300000], ['infotipo_id' => 2, 'valor' => 40000], ['infotipo_id' => 3, 'valor' => 20000]]);
        $this->assertSame(['360000.00', '300000.00', '9000.00', '24000.00', '50000.00', '301000.00', '49440.00', '301560.00'],
            [$r['bruto'], $r['base_inss'], $r['inss_trabalhador'], $r['inss_patronal'], $r['isencoes'], $r['base_irt'], $r['irt'], $r['liquido']]);
    }

    #[Test]
    public function faltas_pro_rata_e_isencao_sobre_o_valor_pago(): void
    {
        // 20 de 22 dias: falta = valor/22 × 2
        $lanc = [['infotipo_id' => 1, 'valor' => 220000, 'dias_trabalhados' => 20], ['infotipo_id' => 2, 'valor' => 22000, 'dias_trabalhados' => 20]];
        $atual = $this->calc($lanc);
        $this->assertSame('242000.00', $atual['bruto']);
        $this->assertSame('22000.00', $atual['descontos']);           // 20 000 + 2 000
        $this->assertSame('200000.00', $atual['base_inss']);
        // base IRT = 242 000 − 6 000 (INSS) − 20 000 (isenção sobre o pago) − 22 000 (faltas) = 194 000
        $this->assertSame('194000.00', $atual['base_irt']);
        $legado = $this->calc($lanc, [], 'LEGADO');
        // legado: isenção sobre o valor CHEIO (22 000) → base IRT 192 000
        $this->assertSame('192000.00', $legado['base_irt']);
        $this->assertSame(bcsub(bcsub(bcsub($atual['bruto'], $atual['inss_trabalhador'], 2), $atual['irt'], 2), $atual['descontos'], 2), $atual['liquido']);
    }

    #[Test]
    public function avencado_sobre_o_valor_pago_no_modo_atual(): void
    {
        $lanc = [['infotipo_id' => 1, 'valor' => 100000, 'dias_trabalhados' => 20]];   // falta 9 090,91
        $atual = $this->calc($lanc, ['avencado' => true]);
        $this->assertSame(['0.00', '0.00'], [$atual['inss_trabalhador'], $atual['inss_patronal']]);
        $this->assertSame('5909.09', $atual['irt']);                    // 6,5 % × 90 909,09
        $this->assertSame('6500.00', $this->calc($lanc, ['avencado' => true], 'LEGADO')['irt']);   // legado: sobre o bruto
    }

    #[Test]
    public function horas_extra_por_escaloes_e_faltas_por_hora(): void
    {
        $contrato = ['dias' => 22, 'horas' => 8, 'remuneracoes' => [['infotipo_id' => 1, 'valor_mes' => 176000]]];   // valor hora = 1 000
        $r = $this->calc([['infotipo_id' => 1, 'valor' => 176000], ['infotipo_id' => 4, 'valor' => 0, 'horas' => 35]], ['contrato' => $contrato]);
        $he = collect($r['rubricas'])->firstWhere('nome', 'H. Extras');
        $this->assertSame('53750.00', $he['valor']);                    // 30 h × 1 500 + 5 h × 1 750
        $this->assertSame('229750.00', $r['bruto']);
        $this->assertSame('229750.00', $r['base_inss']);
    }

    #[Test]
    public function aposentado_sem_inss_e_rubricas_informativas_e_nao_tributaveis(): void
    {
        $r = $this->calc([['infotipo_id' => 1, 'valor' => 200000], ['infotipo_id' => 6, 'valor' => 22], ['infotipo_id' => 7, 'valor' => 50000],
            ['infotipo_id' => 5, 'valor' => 10000]], ['reformado' => true]);
        $this->assertSame(['0.00', '0.00'], [$r['inss_trabalhador'], $r['inss_patronal']]);
        $this->assertSame('250000.00', $r['bruto']);                    // "Dias de Trabalho" (OUTROS) não entra
        $this->assertSame('200000.00', $r['base_irt']);                 // ajudas de custo com irt = false ficam fora
        $this->assertSame('20500.00', $r['irt']);
        $this->assertSame('219500.00', $r['liquido']);                  // 250 000 − 20 500 − 10 000
        $this->assertTrue(collect($r['rubricas'])->firstWhere('infotipo_id', 6)['informativa']);
        // legado ignorava a flag irt = false
        $this->assertSame('250000.00', $this->calc([['infotipo_id' => 1, 'valor' => 200000], ['infotipo_id' => 7, 'valor' => 50000]], ['reformado' => true], 'LEGADO')['base_irt']);
    }
}
