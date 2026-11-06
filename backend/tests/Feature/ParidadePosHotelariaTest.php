<?php

namespace Tests\Feature;

use App\Models\Armazem;
use App\Models\Empresa;
use App\Models\EstadiaHotel;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\Terceiro;
use App\Models\Utilizador;
use App\Services\Logistica\ServicoStock;
use App\Services\POS\Hotelaria\RegrasHotel;
use App\Services\POS\Hotelaria\ServicoCheckoutHotel;
use App\Services\Vendas\ServicoConfigVendas;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * F4 — verificação NUMÉRICA da hotelaria contra js/hotelaria.js (R-029…R-033, POS_LEGADO_INVENTARIO §2.C). Cada número foi
 * calculado à mão a partir do código do legado (os cálculos estão nos comentários) e não a partir da saída do sistema novo.
 * As diferenças deliberadas ficam assinaladas «DIFERENÇA DELIBERADA» e explicadas no relatório POS_F4_RELATORIO.md.
 * Horas locais (Africa/Luanda, sem horário de Verão). Regras por omissão: 14:00/12:00, tolerância 60 min, bloqueio 21:00–08:00.
 */
final class ParidadePosHotelariaTest extends TestCase
{
    private const RG = ['entrada' => '14:00', 'saida' => '12:00', 'tolerancia' => 60, 'bloqueio' => true, 'de' => '21:00', 'ate' => '08:00'];

    private Empresa $empresa;

    private array $ids = [];

    private array $s;

    private Utilizador $gestor;

    private int $ano;

    private function em(string $d): Carbon
    {
        return Carbon::parse($d, 'Africa/Luanda');
    }

    private function estadia(string $modo, string $qtd, string $entrada): EstadiaHotel
    {
        $rg = self::RG;

        return new EstadiaHotel(['modo' => $modo, 'quantidade' => $qtd, 'entrada_em' => $this->em($entrada),
            'saida_prevista_em' => RegrasHotel::saidaPrevista($this->em($entrada), $modo, $qtd, $rg)]);
    }

    // ───────────── R-029 / R-030: saída prevista e bloqueio horário (sem BD) ─────────────

    #[Test]
    public function r029_diaria_sai_ao_meio_dia_do_dia_seguinte_a_cada_diaria(): void
    {
        $f = fn (string $entrada, string $qtd) => RegrasHotel::saidaPrevista($this->em($entrada), 'DIA', $qtd, self::RG)->format('Y-m-d H:i');
        // legado: dia civil da entrada + max(1, round(n)) dias, às hotel_checkout_time (exemplo do inventário: 14:00 dia 5 → 12:00 dia 6)
        $this->assertSame('2026-03-06 12:00', $f('2026-03-05 14:00', '1'));
        $this->assertSame('2026-04-01 12:00', $f('2026-03-31 15:00', '1'));   // vira o mês
        $this->assertSame('2026-03-11 12:00', $f('2026-03-10 00:30', '1'));   // madrugada conta o dia civil da entrada
        $this->assertSame('2026-03-13 12:00', $f('2026-03-10 15:00', '3'));
        $this->assertSame('2026-03-13 12:00', $f('2026-03-10 15:00', '2.5'));  // Math.round(2.5) = 3
        $this->assertSame('2026-03-11 12:00', $f('2026-03-10 15:00', '0.4'));  // max(1, round(0.4)=0) = 1
        $rg = ['saida' => '11:30'] + self::RG;
        $this->assertSame('2026-03-11 11:30', RegrasHotel::saidaPrevista($this->em('2026-03-10 15:00'), 'DIA', '1', $rg)->format('Y-m-d H:i'));
    }

    #[Test]
    public function r030_hora_soma_as_horas_e_a_janela_de_bloqueio_atravessa_a_meia_noite(): void
    {
        $f = fn (string $entrada, string $qtd) => RegrasHotel::saidaPrevista($this->em($entrada), 'HORA', $qtd, self::RG)->format('Y-m-d H:i');
        $this->assertSame('2026-03-10 17:30', $f('2026-03-10 15:00', '2.5'));   // + 2,5 × 3 600 000 ms
        $this->assertSame('2026-03-11 02:00', $f('2026-03-10 23:30', '2.5'));

        // horaBloqueada: de 21:00 > ate 08:00 → bloqueia quando min ≥ 21*60 ou min < 8*60
        $b = fn (string $hora, array $rg = self::RG) => RegrasHotel::horaBloqueada($this->em("2026-03-10 {$hora}"), $rg);
        $this->assertSame([true, false, true, false, true, false], [$b('21:00'), $b('20:59'), $b('07:59'), $b('08:00'), $b('00:00'), $b('12:00')]);
        // janela no mesmo dia (09:00–17:00): ≥ 9*60 e < 17*60
        $dia = ['de' => '09:00', 'ate' => '17:00'] + self::RG;
        $this->assertSame([true, true, false, false], [$b('09:00', $dia), $b('16:59', $dia), $b('17:00', $dia), $b('08:59', $dia)]);
        $this->assertFalse($b('22:00', ['de' => '08:00', 'ate' => '08:00'] + self::RG));   // de = ate desliga
        $this->assertFalse($b('22:00', ['bloqueio' => false] + self::RG));
    }

    // ───────────── R-031: saída tardia (sem BD) ─────────────

    #[Test]
    public function r031_proposta_de_saida_tardia_a_diaria(): void
    {
        // entrada 10/03 15:00, 1 diária → prevista 11/03 12:00; tolerância 60 min → até às 13:00 inclusive não há proposta
        $e = $this->estadia('DIA', '1', '2026-03-10 15:00');
        $p = fn (EstadiaHotel $e1, string $agora) => RegrasHotel::propostaAtraso($e1, self::RG, $this->em($agora));
        $this->assertNull($p($e, '2026-03-11 12:59'));
        $this->assertNull($p($e, '2026-03-11 13:00'));   // agora ≤ prevista + tolerância
        // 13:01 → extra = ceil(61 min / 1440 min) = 1
        $this->assertSame(['quantidade' => '2.000', 'extra' => '1.000'], $p($e, '2026-03-11 13:01'));
        // exactamente 24 h depois da prevista: ceil(1,0) = 1; mais 1 min: ceil(1,0007) = 2 (a tolerância não desconta ao extra: BUG-15)
        $this->assertSame(['quantidade' => '2.000', 'extra' => '1.000'], $p($e, '2026-03-12 12:00'));
        $this->assertSame(['quantidade' => '3.000', 'extra' => '2.000'], $p($e, '2026-03-12 12:01'));
        // 2 diárias → prevista 12/03 12:00; 13/03 13:00 = 25 h depois → ceil(1,0417) = 2 → 4 diárias
        $this->assertSame(['quantidade' => '4.000', 'extra' => '2.000'], $p($this->estadia('DIA', '2', '2026-03-10 15:00'), '2026-03-13 13:00'));
    }

    #[Test]
    public function r031_proposta_de_saida_tardia_a_hora(): void
    {
        // entrada 15:00, 3 h → prevista 18:00; tolerância 60 → 19:00 sem proposta
        $e = $this->estadia('HORA', '3', '2026-03-10 15:00');
        $p = fn (EstadiaHotel $e1, string $agora) => RegrasHotel::propostaAtraso($e1, self::RG, $this->em($agora));
        $this->assertNull($p($e, '2026-03-10 19:00'));
        // 19:01: horas = ceil(4 h 01 / 1 h) = 5 → nova = max(3, 5) = 5, extra 2
        $this->assertSame(['quantidade' => '5.000', 'extra' => '2.000'], $p($e, '2026-03-10 19:01'));
        $this->assertSame(['quantidade' => '5.000', 'extra' => '2.000'], $p($e, '2026-03-10 19:30'));   // ceil(4,5) = 5
        $this->assertSame(['quantidade' => '5.000', 'extra' => '2.000'], $p($e, '2026-03-10 20:00'));   // ceil(5,0) = 5
        $this->assertSame(['quantidade' => '6.000', 'extra' => '3.000'], $p($e, '2026-03-10 20:30'));   // ceil(5,5) = 6
        // 2,5 h → prevista 17:30; 18:31: ceil(3,5167) = 4 → extra 1,5
        $this->assertSame(['quantidade' => '4.000', 'extra' => '1.500'], $p($this->estadia('HORA', '2.5', '2026-03-10 15:00'), '2026-03-10 18:31'));
    }

    // ───────────── R-033: repartição dos pagamentos (sem BD) ─────────────

    /** @param list<array<string, mixed>> $liquidos @param list<string> $totais */
    private function ratear(array $liquidos, string $troco, array $totais): array
    {
        $m = new ReflectionMethod(ServicoCheckoutHotel::class, 'ratear');

        return $m->invoke(app(ServicoCheckoutHotel::class), $liquidos, $troco, $totais);
    }

    private function pag(string $meio, string $valor, string $tipo = 'TPA'): array
    {
        return ['meio_id' => $meio, 'tipo' => $tipo, 'valor' => $valor, 'referencia' => null];
    }

    private function simples(array $rateio): array
    {
        return array_map(fn ($fa) => array_map(fn ($p) => [$p['meio_id'], $p['valor']], $fa), $rateio);
    }

    #[Test]
    public function r033_rateio_proporcional_com_a_ultima_a_receber_o_resto_e_o_troco(): void
    {
        // facturas 22 572,00 e 5 130,00 (geral 27 702,00); TPA 20 000 e numerário 7 702 (8 000 − troco 298)
        // F1: TPA r2(20000 × 22572 / 27702) = r2(16296,2999) = 16 296,30; num r2(7702 × 22572 / 27702) = r2(6275,7037) = 6 275,70
        // F2 (última): TPA 20000 − 16296,30 = 3 703,70; num 7702 − 6275,70 = 1 426,30, mais o troco 298 no numerário = 1 724,30
        $r = $this->ratear([$this->pag('tpa', '20000.00'), $this->pag('num', '7702.00', 'NUMERARIO')], '298.00', ['22572.00', '5130.00']);
        $this->assertSame([[['tpa', '16296.30'], ['num', '6275.70']], [['tpa', '3703.70'], ['num', '1724.30']]], $this->simples($r));
    }

    #[Test]
    public function r033_corrige_o_cêntimo_no_maior_pagamento_e_a_ultima_fecha_a_conta(): void
    {
        // 3 facturas de 10,00; 3 pagamentos de 10,00: cada fracção r2(10 × 10 / 30) = 3,33 → soma 9,99, falta 0,01 → vai ao maior
        // (o primeiro dos iguais: reduce com «>» do legado). F1 = [3,34 3,33 3,33]; F2 idem; acumulado [6,68 6,66 6,66]
        // F3 (última) = resto: [10 − 6,68 = 3,32; 3,34; 3,34] → soma 10,00
        $r = $this->ratear([$this->pag('a', '10.00'), $this->pag('b', '10.00'), $this->pag('c', '10.00')], '0.00', ['10.00', '10.00', '10.00']);
        $this->assertSame([
            [['a', '3.34'], ['b', '3.33'], ['c', '3.33']],
            [['a', '3.34'], ['b', '3.33'], ['c', '3.33']],
            [['a', '3.32'], ['b', '3.34'], ['c', '3.34']],
        ], $this->simples($r));
        // 100,00 / 100,00 / 100,01 com A 150,01 e B 150,00: F1 A r2(50,0017)=50,00 B r2(49,9983)=50,00; F2 igual; F3 A 50,01 B 50,00
        $r = $this->ratear([$this->pag('a', '150.01'), $this->pag('b', '150.00')], '0.00', ['100.00', '100.00', '100.01']);
        $this->assertSame([[['a', '50.00'], ['b', '50.00']], [['a', '50.00'], ['b', '50.00']], [['a', '50.01'], ['b', '50.00']]], $this->simples($r));
        // uma factura: recebe tudo; o troco soma-se ao numerário (60 = 50 + 10)
        $this->assertSame([[['num', '60.00']]], $this->simples($this->ratear([$this->pag('num', '50.00', 'NUMERARIO')], '10.00', ['50.00'])));
    }

    // ───────────── R-032 e saída tardia no check-out (com BD) ─────────────

    protected function setUp(): void
    {
        parent::setUp();
        $this->ano = (int) now()->year;
        $this->travelTo(Carbon::create($this->ano, 3, 10, 15, 0, 0, 'Africa/Luanda'));
        $this->empresa = $this->criarEmpresa(['nif' => '5417000051']);
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['311' => 'Clientes', '3452' => 'IVA liquidado', '611' => 'Vendas', '6221' => 'Alojamento', '2611' => 'Mercadorias', '7111' => 'CMV',
                '489' => 'Transitória numerário', '4511' => 'Caixa'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            app(ServicoConfigVendas::class)->definir(['clientes_default' => '311']);
            $quarto = ['taxa_imposto' => 14, 'codigo_conta' => '6221', 'conta_iva_liquidado' => '3452', 'e_quarto' => true, 'e_servico' => true, 'movimenta_stock' => false];
            $this->ids['q1'] = Produto::create($quarto + ['codigo' => 'Q1', 'nome' => 'Quarto 1', 'preco_unitario' => 11400, 'preco_por_dia' => 11400, 'preco_por_hora' => 1140, 'horas_minimas' => 2])->id;
            $this->ids['p'] = Produto::create(['codigo' => 'P1', 'nome' => 'Bebida', 'preco_unitario' => 1140, 'taxa_imposto' => 14, 'codigo_conta' => '611',
                'conta_iva_liquidado' => '3452', 'movimenta_stock' => true, 'conta_inventario' => '2611', 'conta_custo' => '7111'])->id;
            $this->ids['p0'] = Produto::create(['codigo' => 'P0', 'nome' => 'Sem stock', 'preco_unitario' => 100, 'taxa_imposto' => 14, 'codigo_conta' => '611',
                'conta_iva_liquidado' => '3452', 'movimenta_stock' => true, 'conta_inventario' => '2611', 'conta_custo' => '7111'])->id;
            $servico = ['codigo_conta' => '611', 'conta_iva_liquidado' => '3452', 'movimenta_stock' => false, 'e_servico' => true];
            $this->ids['iva7'] = Produto::create($servico + ['codigo' => 'S7', 'nome' => 'Lanche IVA 7', 'preco_unitario' => 500, 'taxa_imposto' => 7])->id;
            $this->ids['c1'] = Produto::create($servico + ['codigo' => 'C1', 'nome' => 'Moeda A', 'preco_unitario' => 1.10, 'taxa_imposto' => 0])->id;
            $this->ids['c2'] = Produto::create($servico + ['codigo' => 'C2', 'nome' => 'Moeda B', 'preco_unitario' => 1.10, 'taxa_imposto' => 0])->id;
            $this->ids['a'] = Armazem::create(['nome' => 'Bar', 'predefinido' => true])->id;
            app(ServicoStock::class)->entrada($this->ids['p'], $this->ids['a'], '10', '600', now()->toDateString(), 'Stock inicial');
            $this->ids['h1'] = Terceiro::create(['nome' => 'Hóspede A', 'nif' => '5000000011', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '311'])->id;
        });
        $this->gestor = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys(
            ['pos_hotelaria_view', 'pos_venda', 'pos_desconto', 'pos_terminais_gerir', 'hotel_estadias', 'hotel_checkout', 'hotel_anular'], true))->id]);
        $this->gestor->empresas()->attach($this->empresa->id);
        $this->s = $this->entrar($this->gestor) + ['X-Empresa-Id' => $this->empresa->id];
        $meios = [['id' => 'pm_num', 'tipo' => 'NUMERARIO', 'nome' => 'Numerário', 'conta_transitoria' => '489', 'conta_liquidacao' => '4511']];
        $this->ids['t'] = $this->postJson('/api/pos/terminais', ['codigo' => 'H01', 'nome' => 'Recepção', 'tipo' => 'HOTELARIA', 'armazem_id' => $this->ids['a'],
            'meios_pagamento' => $meios], $this->s)->assertCreated()->json('dados.id');
        $this->ids['sessao'] = $this->postJson("/api/pos/terminais/{$this->ids['t']}/sessoes", [], $this->s)->assertCreated()->json('dados.id');
    }

    private function irPara(int $dia, int $hora, int $minuto = 0): void
    {
        $this->travelTo(Carbon::create($this->ano, 3, $dia, $hora, $minuto, 0, 'Africa/Luanda'));
        $this->app['auth']->forgetGuards();
        $this->s = $this->entrar($this->gestor) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function checkin(array $d = []): int
    {
        return $this->postJson("/api/pos/hotelaria/sessoes/{$this->ids['sessao']}/checkin", $d + ['produto_quarto_id' => $this->ids['q1'],
            'cliente_hospede_id' => $this->ids['h1'], 'modo' => 'DIA', 'quantidade' => 1], $this->s)->assertCreated()->json('dados.id');
    }

    private function consumos(int $estadia, array $linhas)
    {
        return $this->putJson("/api/pos/hotelaria/estadias/{$estadia}/consumos", ['linhas' => $linhas], $this->s);
    }

    private function simular(array $estadias, array $extra = []): array
    {
        return $this->postJson("/api/pos/hotelaria/sessoes/{$this->ids['sessao']}/checkout/simular", ['estadias' => $estadias, 'modo_faturacao' => 'POR_QUARTO'] + $extra, $this->s)
            ->assertOk()->json('dados');
    }

    #[Test]
    public function r032_totais_com_iva_misto_diferem_um_centimo_na_base_mas_nao_no_total(): void
    {
        // 1 diária 11 400 (IVA 14) + 3 × 500 (IVA 7) = bruto 12 900,00
        // legado (totaisDe): líquido = 11400/1,14 + 1500/1,07 = 10 000,00 + 1 401,8692 = 11 401,8692 → net 11 401,87; tax = 12 900 − 11 401,87 = 1 498,13
        // novo (CalculadoraDocumento, imposto por excesso ao cêntimo por linha, R-025): linha 1 base 10 000,00 + IVA 1 400,00;
        //   linha 2 base 1 401,87 daria 1 401,87 + 98,14 = 1 500,01 > 1 500 → base 1 401,86 + IVA 98,14 = 1 500,00
        //   ⇒ base 11 401,86 e IVA 1 498,14. O total (que se cobra e se paga) é igual. DIFERENÇA DELIBERADA (o documento selado é o novo).
        $e = $this->checkin();
        $this->consumos($e, [['produto_id' => $this->ids['iva7'], 'quantidade' => 3]])->assertOk();
        $f = $this->simular([['id' => $e]])['facturas'][0];
        $this->assertSame(['12900.00', '11401.86', '1498.14', '12900.00'], [$f['bruto'], $f['total_liquido'], $f['total_imposto'], $f['total']]);
    }

    #[Test]
    public function r032_o_desconto_arredonda_por_linha_como_o_documento_e_pode_diferir_um_centimo_do_legado(): void
    {
        // 1 diária 11 400 + 2 consumos de 1,10 (IVA 0), desconto 15 %
        // legado: bruto 11 402,20; gross = r2(11 402,20 × 0,85) = r2(9 691,87) = 9 691,87; desconto = 1 710,33
        // novo: por linha r2(11 400 × 0,85) = 9 690,00; r2(1,10 × 0,85 = 0,935) = 0,94 (meio-cêntimo para cima) ×2 ⇒ 9 691,88
        //   DIFERENÇA DELIBERADA de 0,01: o total do check-out é a soma das linhas do FR emitido (facturacao_agt.js:240-266),
        //   como a venda POS e a lavandaria; o legado recalculava os totais à parte só para o rateio dos pagamentos.
        $e = $this->checkin();
        $this->consumos($e, [['produto_id' => $this->ids['c1'], 'quantidade' => 1], ['produto_id' => $this->ids['c2'], 'quantidade' => 1]])->assertOk();
        $f = $this->simular([['id' => $e]], ['percentagem_desconto' => 15])['facturas'][0];
        $this->assertSame(['11402.20', '1710.33', '9691.88'], [$f['bruto'], $f['desconto'], $f['total']]);
    }

    #[Test]
    public function r031_r032_recalculo_da_saida_tardia_a_hora_e_a_diaria_com_desconto(): void
    {
        // à hora: 3 h × 1 140 = 3 420 (mantido); 20:30 → ceil(5,5) = 6 h × 1 140 = 6 840,00 recalculado
        $h = $this->checkin(['modo' => 'HORA', 'quantidade' => 3]);
        $this->irPara(10, 20, 30);
        $f = $this->simular([['id' => $h, 'opcao_atraso' => 'RECALCULAR']])['facturas'][0];
        $this->assertSame(['6.000', '6840.00'], [$f['estadias'][0]['quantidade_final'], $f['total']]);
        $this->assertStringStartsWith('Alojamento Quarto 1 · 6 hora(s) · 10/03/2026 15:00 a 10/03/2026 20:30', $f['linhas'][0]['descricao']);
        $this->assertSame('3420.00', $this->simular([['id' => $h, 'opcao_atraso' => 'MANTER']])['facturas'][0]['total']);
        $this->postJson("/api/pos/hotelaria/estadias/{$h}/anular", ['motivo' => 'Teste'], $this->s)->assertOk();

        // à diária: 2 diárias entradas a 10/03 (prevista 12/03 12:00); a 13/03 13:00 há 25 h → +2 → 4 × 11 400 = 45 600,00
        // com 20 %: 36 480,00 (base 32 000,00 + IVA 4 480,00: 36 480 / 1,14 = 32 000)
        $this->irPara(10, 15);
        $d = $this->checkin(['quantidade' => 2]);
        $this->irPara(13, 13);
        $f = $this->simular([['id' => $d, 'opcao_atraso' => 'RECALCULAR']], ['percentagem_desconto' => 20])['facturas'][0];
        $this->assertSame(['4.000', '45600.00', '9120.00', '36480.00', '32000.00', '4480.00'],
            [$f['estadias'][0]['quantidade_final'], $f['bruto'], $f['desconto'], $f['total'], $f['total_liquido'], $f['total_imposto']]);
    }

    // ───────────── Stock dos consumos (BUG-20) ─────────────

    #[Test]
    public function consumos_recusam_o_que_nao_ha_em_stock_como_o_carrinho_do_legado(): void
    {
        $e = $this->checkin();
        // legado (addPOSProduct): sem stock «fora de stock»; acima do stock «não é possível facturar mais unidades…»; serviços sem limite
        $this->consumos($e, [['produto_id' => $this->ids['p0'], 'quantidade' => 1]])->assertStatus(422)->assertJsonPath('codigo', 'STOCK_INSUFICIENTE')
            ->assertJsonPath('mensagem', 'O artigo Sem stock encontra-se fora de stock.');
        $this->consumos($e, [['produto_id' => $this->ids['p'], 'quantidade' => 6], ['produto_id' => $this->ids['p'], 'quantidade' => 5]])
            ->assertStatus(422)->assertJsonPath('mensagem', 'Não é possível facturar mais unidades de Bebida do que o stock existente (10).');
        $this->consumos($e, [['produto_id' => $this->ids['p'], 'quantidade' => 10], ['produto_id' => $this->ids['iva7'], 'quantidade' => 500]])->assertOk();
    }

    #[Test]
    public function o_checkout_revalida_o_stock_e_diz_que_quarto_e_artigo_impedem_o_fecho(): void
    {
        $e = $this->checkin();
        $this->consumos($e, [['produto_id' => $this->ids['p'], 'quantidade' => 8]])->assertOk();
        // outro posto gasta stock entretanto (o legado só baixava o stock no check-out): sobram 5
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => app(ServicoStock::class)->saida($this->ids['p'], $this->ids['a'], '5', null, now()->toDateString(), 'Venda noutro posto'));
        $r = $this->postJson("/api/pos/hotelaria/sessoes/{$this->ids['sessao']}/checkout", ['estadias' => [['id' => $e]], 'modo_faturacao' => 'POR_QUARTO',
            'pagamentos' => [['meio_id' => 'pm_num', 'valor' => 20000]]], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'STOCK_INSUFICIENTE');
        $this->assertStringContainsString('Não é possível fechar o check-out: stock insuficiente de Bebida para os consumos de Quarto 1 (pedido 8.000, disponível 5', $r->json('mensagem'));
        $this->getJson("/api/pos/hotelaria/estadias/{$e}", $this->s)->assertJsonPath('dados.estado', 'ABERTA');   // nada ficou facturado
    }
}
