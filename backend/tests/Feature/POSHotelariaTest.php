<?php

namespace Tests\Feature;

use App\Models\Armazem;
use App\Models\Empresa;
use App\Models\EstadiaHotel;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\StockArmazem;
use App\Models\Terceiro;
use App\Models\Utilizador;
use App\Services\Logistica\ServicoStock;
use App\Services\POS\ServicoMigracaoHotelaria;
use App\Services\Vendas\ServicoConfigVendas;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * POS parte 3b — hotelaria (ADR-050): check-in com regras do terminal (diária 14:00/12:00, venda à hora bloqueada
 * 21:00–08:00, mínimo de horas), quarto ocupado, consumos, anulação, check-out com saída tardia (RECALCULAR/MANTER),
 * desconto, uma factura por quarto ou única, rateio dos pagamentos e troco na última factura.
 * Quarto Q1: 11 400 Kz/diária e 1 140 Kz/hora com IVA 14 % (mín. 2 h); Q2: 5 700 Kz/diária, sem preço à hora.
 * Consumo P1: 1 140 Kz com IVA (custo 600), 10 em stock no armazém do terminal.
 */
final class POSHotelariaTest extends TestCase
{
    private Empresa $empresa;

    private array $ids = [];

    private array $s;

    /** @var array<string, Utilizador> */
    private array $utilizadores = [];

    private int $ano;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ano = (int) now()->year;
        $this->travelTo(Carbon::create($this->ano, 3, 10, 15, 0, 0, 'Africa/Luanda'));
        $this->empresa = $this->criarEmpresa(['nif' => '5417000050']);
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['311' => 'Clientes', '3452' => 'IVA liquidado', '611' => 'Vendas', '6221' => 'Alojamento', '2611' => 'Mercadorias', '7111' => 'CMV',
                '488' => 'Transitória TPA', '489' => 'Transitória numerário', '4511' => 'Caixa', '43101' => 'Banco'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            app(ServicoConfigVendas::class)->definir(['clientes_default' => '311']);
            $quarto = ['taxa_imposto' => 14, 'codigo_conta' => '6221', 'conta_iva_liquidado' => '3452', 'e_quarto' => true, 'e_servico' => true, 'movimenta_stock' => false];
            $this->ids['q1'] = Produto::create($quarto + ['codigo' => 'Q1', 'nome' => 'Quarto 1', 'preco_unitario' => 11400, 'preco_por_dia' => 11400, 'preco_por_hora' => 1140, 'horas_minimas' => 2])->id;
            $this->ids['q2'] = Produto::create($quarto + ['codigo' => 'Q2', 'nome' => 'Quarto 2', 'preco_unitario' => 5700, 'preco_por_dia' => null, 'preco_por_hora' => 0])->id;
            $this->ids['p'] = Produto::create(['codigo' => 'P1', 'nome' => 'Bebida', 'preco_unitario' => 1140, 'taxa_imposto' => 14, 'codigo_conta' => '611',
                'conta_iva_liquidado' => '3452', 'movimenta_stock' => true, 'conta_inventario' => '2611', 'conta_custo' => '7111'])->id;
            $this->ids['a'] = Armazem::create(['nome' => 'Bar', 'predefinido' => true])->id;
            app(ServicoStock::class)->entrada($this->ids['p'], $this->ids['a'], '10', '600', now()->toDateString(), 'Stock inicial');
            $this->ids['h1'] = Terceiro::create(['nome' => 'Hóspede A', 'nif' => '5000000011', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '311'])->id;
            $this->ids['h2'] = Terceiro::create(['nome' => 'Hóspede B', 'nif' => '5000000012', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '311'])->id;
            $this->ids['emp'] = Terceiro::create(['nome' => 'Empresa C', 'nif' => '5000000013', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '311'])->id;
        });
        $this->s = $this->sessao('gestor', ['pos_hotelaria_view', 'pos_venda', 'pos_desconto', 'pos_terminais_gerir', 'hotel_estadias', 'hotel_checkout', 'hotel_anular', 'hotel_quartos']);
        $meios = [['id' => 'pm_num', 'tipo' => 'NUMERARIO', 'nome' => 'Numerário', 'conta_transitoria' => '489', 'conta_liquidacao' => '4511'],
            ['id' => 'pm_tpa', 'tipo' => 'TPA', 'nome' => 'Multicaixa', 'conta_transitoria' => '488', 'conta_liquidacao' => '43101']];
        $this->ids['t'] = $this->postJson('/api/pos/terminais', ['codigo' => 'H01', 'nome' => 'Recepção', 'tipo' => 'HOTELARIA', 'armazem_id' => $this->ids['a'],
            'meios_pagamento' => $meios], $this->s)->assertCreated()->json('dados.id');
        $this->ids['loja'] = $this->postJson('/api/pos/terminais', ['codigo' => 'L01', 'nome' => 'Loja', 'tipo' => 'LOJA', 'meios_pagamento' => $meios], $this->s)->assertCreated()->json('dados.id');
        $this->ids['sessao'] = $this->postJson("/api/pos/terminais/{$this->ids['t']}/sessoes", [], $this->s)->assertCreated()->json('dados.id');
    }

    private function sessao(string $nome, array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);
        $this->utilizadores[$nome] = $u;

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    /** Avança o relógio e volta a entrar (a sessão da API expira com 15 min de inactividade). */
    private function irPara(int $dia, int $hora, int $minuto = 0): void
    {
        $this->travelTo(Carbon::create($this->ano, 3, $dia, $hora, $minuto, 0, 'Africa/Luanda'));
        $this->app['auth']->forgetGuards();
        $this->s = $this->entrar($this->utilizadores['gestor']) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function checkin(array $d, ?array $s = null, ?int $sessao = null)
    {
        return $this->postJson('/api/pos/hotelaria/sessoes/'.($sessao ?? $this->ids['sessao']).'/checkin', $d + ['cliente_hospede_id' => $this->ids['h1'], 'modo' => 'DIA', 'quantidade' => 1], $s ?? $this->s);
    }

    private function stock(): string
    {
        return (string) app(ContextoEmpresa::class)->executarComo($this->empresa->id,
            fn () => StockArmazem::query()->where('armazem_id', $this->ids['a'])->where('produto_id', $this->ids['p'])->value('quantidade_stock'));
    }

    #[Test]
    public function checkin_aplica_as_regras_do_terminal_e_bloqueia_o_quarto_ocupado(): void
    {
        $lojaSessao = $this->postJson("/api/pos/terminais/{$this->ids['loja']}/sessoes", [], $this->s)->assertCreated()->json('dados.id');
        $this->checkin(['produto_quarto_id' => $this->ids['q1']], null, $lojaSessao)->assertStatus(422)->assertJsonPath('codigo', 'TERMINAL_NAO_HOTELARIA');
        $this->checkin(['produto_quarto_id' => $this->ids['p']])->assertStatus(422)->assertJsonPath('codigo', 'QUARTO_INVALIDO');
        $this->checkin(['produto_quarto_id' => $this->ids['q1'], 'quantidade' => 1.5])->assertStatus(422)->assertJsonPath('codigo', 'QUANTIDADE_INVALIDA');
        $this->checkin(['produto_quarto_id' => $this->ids['q1'], 'modo' => 'HORA', 'quantidade' => 1])->assertStatus(422)->assertJsonPath('codigo', 'MINIMO_HORAS');
        $this->checkin(['produto_quarto_id' => $this->ids['q2'], 'modo' => 'HORA', 'quantidade' => 2])->assertStatus(422)->assertJsonPath('codigo', 'SEM_PRECO_HORA');
        $this->checkin(['produto_quarto_id' => $this->ids['q1'], 'modo' => 'HORA', 'quantidade' => 2, 'entrada_em' => "{$this->ano}-03-10 22:30"])
            ->assertStatus(422)->assertJsonPath('codigo', 'HORA_BLOQUEADA');
        // preço diferente do do quarto exige pos_desconto (no legado não era protegido)
        $recepcao = $this->sessao('recepcao', ['hotel_estadias', 'pos_venda']);
        $this->checkin(['produto_quarto_id' => $this->ids['q1'], 'preco_unitario' => 9000], $recepcao)->assertForbidden();

        // diária: entrada 10/03 15:00, 2 diárias → saída 12/03 às 12:00; o preço por diária do Q2 cai no preço unitário
        $e = $this->checkin(['produto_quarto_id' => $this->ids['q1'], 'quantidade' => 2, 'numero_hospedes' => 2], $recepcao)->assertCreated()->json('dados');
        $this->assertSame(['ABERTA', 'DIA', '2.000', '11400.00', '14.0000', 'Quarto 1', 'Hóspede A', 'H01'],
            [$e['estado'], $e['modo'], $e['quantidade'], $e['preco_unitario'], $e['taxa_imposto'], $e['nome_quarto'], $e['nome_hospede'], $e['codigo_terminal']]);
        $this->assertSame("{$this->ano}-03-12 12:00", Carbon::parse($e['saida_prevista_em'])->setTimezone('Africa/Luanda')->format('Y-m-d H:i'));
        $this->assertStringStartsWith('Check-in: 2 diária(s) × 11 400,00 Kz, saída prevista 12/03/', $e['historico_alteracoes'][0]['texto']);
        $this->checkin(['produto_quarto_id' => $this->ids['q1'], 'cliente_hospede_id' => $this->ids['h2']])->assertStatus(422)->assertJsonPath('codigo', 'QUARTO_OCUPADO');
        $this->assertSame('5700.00', $this->checkin(['produto_quarto_id' => $this->ids['q2']])->assertCreated()->json('dados.preco_unitario'));

        // à hora: 3 h às 15:00 → saída 18:00; alterar a entrada para a janela bloqueada é recusado
        $this->postJson("/api/pos/hotelaria/estadias/{$e['id']}/anular", ['motivo' => 'Engano de quarto'], $this->s)->assertOk()->assertJsonPath('dados.estado', 'ANULADA');
        $h = $this->checkin(['produto_quarto_id' => $this->ids['q1'], 'modo' => 'HORA', 'quantidade' => 3])->assertCreated()->assertJsonPath('dados.preco_unitario', '1140.00')->json('dados');
        $this->assertSame("{$this->ano}-03-10 18:00", Carbon::parse($h['saida_prevista_em'])->setTimezone('Africa/Luanda')->format('Y-m-d H:i'));
        $this->putJson("/api/pos/hotelaria/estadias/{$h['id']}", ['entrada_em' => "{$this->ano}-03-10 07:00"], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'HORA_BLOQUEADA');
        $alt = $this->putJson("/api/pos/hotelaria/estadias/{$h['id']}", ['quantidade' => 4, 'cliente_hospede_id' => $this->ids['h2']], $this->s)->assertOk()->json('dados');
        $this->assertSame(['4.000', 'Hóspede B'], [$alt['quantidade'], $alt['nome_hospede']]);
        $this->assertSame("{$this->ano}-03-10 19:00", Carbon::parse($alt['saida_prevista_em'])->setTimezone('Africa/Luanda')->format('Y-m-d H:i'));
        $this->assertSame('Estadia alterada: hóspede Hóspede A → Hóspede B; 3 hora(s) → 4 hora(s).', end($alt['historico_alteracoes'])['texto']);

        // mapa de quartos
        $mapa = collect($this->getJson("/api/pos/hotelaria/quartos?terminal_pos_id={$this->ids['t']}", $recepcao)->assertOk()->json('dados'))->keyBy('codigo');
        $this->assertSame(['OCUPADO', 'OCUPADO', false, '4560.00'], [$mapa['Q1']['estado'], $mapa['Q2']['estado'], $mapa['Q1']['estadia']['atrasado'], $mapa['Q1']['estadia']['total_em_aberto']]);
    }

    #[Test]
    public function consumos_e_anulacao_so_sem_consumos(): void
    {
        $e = $this->checkin(['produto_quarto_id' => $this->ids['q1']])->assertCreated()->json('dados.id');
        $url = "/api/pos/hotelaria/estadias/{$e}";
        $this->putJson("{$url}/consumos", ['linhas' => [['produto_id' => $this->ids['q2'], 'quantidade' => 1]]], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'CONSUMO_QUARTO');
        $caixa = $this->sessao('caixa', ['pos_venda']);
        $this->putJson("{$url}/consumos", ['linhas' => [['produto_id' => $this->ids['p'], 'quantidade' => 2, 'preco_unitario' => 1000]]], $caixa)->assertForbidden();
        $c = $this->putJson("{$url}/consumos", ['linhas' => [['produto_id' => $this->ids['p'], 'quantidade' => 2]]], $caixa)->assertOk()->json('dados.itens');
        $this->assertEquals([['descricao' => 'Bebida', 'preco_unitario' => 1140, 'produto_id' => $this->ids['p'], 'quantidade' => 2, 'taxa_imposto' => 14]],
            array_map(fn ($i) => collect($i)->sortKeys()->all(), $c));
        $this->getJson($url, $this->s)->assertOk()->assertJsonPath('dados.total_consumos', '2280.00')->assertJsonPath('dados.total_em_aberto', '13680.00')
            ->assertJsonPath('dados.proposta_atraso', null);

        $this->postJson("{$url}/anular", ['motivo' => 'Desistiu'], $caixa)->assertForbidden();
        $this->postJson("{$url}/anular", ['motivo' => 'Desistiu'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'ESTADIA_COM_CONSUMOS');
        $this->putJson("{$url}/consumos", ['linhas' => []], $caixa)->assertOk()->assertJsonPath('dados.itens', []);
        $a = $this->postJson("{$url}/anular", ['motivo' => 'Desistiu'], $this->s)->assertOk()->json('dados');
        $this->assertSame(['ANULADA', 'Desistiu', 'Check-in anulado — Desistiu.'], [$a['estado'], $a['motivo_cancelamento'], end($a['historico_alteracoes'])['texto']]);
        $this->putJson("{$url}/consumos", ['linhas' => []], $caixa)->assertStatus(422)->assertJsonPath('codigo', 'ESTADIA_NAO_ABERTA');
        $this->checkin(['produto_quarto_id' => $this->ids['q1']])->assertCreated();   // o quarto fica livre
    }

    #[Test]
    public function checkout_por_quarto_com_saida_tardia_desconto_e_rateio_dos_pagamentos(): void
    {
        $e1 = $this->checkin(['produto_quarto_id' => $this->ids['q1']])->assertCreated()->json('dados.id');
        $e2 = $this->checkin(['produto_quarto_id' => $this->ids['q2'], 'cliente_hospede_id' => $this->ids['h2']])->assertCreated()->json('dados.id');
        $this->putJson("/api/pos/hotelaria/estadias/{$e1}/consumos", ['linhas' => [['produto_id' => $this->ids['p'], 'quantidade' => 2]]], $this->s)->assertOk();

        // 11/03 às 14:00: 2 h depois da saída das 12:00 (tolerância 60 min) → proposta de +1 diária
        $this->irPara(11, 14);
        $url = "/api/pos/hotelaria/sessoes/{$this->ids['sessao']}/checkout";
        $base = ['estadias' => [['id' => $e1], ['id' => $e2]], 'modo_faturacao' => 'POR_QUARTO', 'percentagem_desconto' => 10];
        $sim = $this->postJson("{$url}/simular", $base, $this->s)->assertOk()->json('dados');
        $this->assertSame(['Quarto 1', 'Quarto 2'], $sim['saidas_por_decidir']);
        $this->assertSame(['quantidade' => '2.000', 'extra' => '1.000'], $sim['facturas'][0]['estadias'][0]['proposta']);
        // decisão 10: na pré-visualização o arredondamento AGT fica separado do desconto (bruto − desconto − arredondamento = total)
        foreach ($sim['facturas'] as $f) {
            $this->assertSame($f['total'], bcsub(bcsub($f['bruto'], $f['desconto'], 2), $f['arredondamento_agt'], 2));
        }
        $this->assertSame('570.00', $sim['facturas'][1]['desconto']);   // Q2: 10 % de 5 700, sem o arredondamento
        $pagamentos = [['meio_id' => 'pm_tpa', 'valor' => 20000], ['meio_id' => 'pm_num', 'valor' => 8000]];
        $this->postJson($url, $base + ['pagamentos' => $pagamentos], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'ATRASO_POR_DECIDIR');
        $base['estadias'] = [['id' => $e1, 'opcao_atraso' => 'RECALCULAR'], ['id' => $e2, 'opcao_atraso' => 'MANTER']];
        $checkout = $this->sessao('checkout', ['hotel_checkout', 'pos_venda']);
        $this->postJson($url, $base + ['pagamentos' => $pagamentos], $checkout)->assertForbidden();   // o desconto exige pos_desconto

        // Q1: 2 × 11 400 + 2 × 1 140 = 25 080 − 10 % = 22 572; Q2: 5 700 − 10 % = 5 130; total 27 702, recebido 28 000 → troco 298
        $r = $this->postJson($url, $base + ['pagamentos' => $pagamentos], $this->s)->assertCreated()->json('dados');
        $this->assertSame(['27702.00', '298.00'], [$r['total'], $r['troco']]);
        [$v1, $v2] = $r['vendas'];
        $this->assertSame(['22572.00', '19800.00', '2772.00', '2508.00', '0.00', $this->ids['h1'], 'Quarto 1'],
            [$v1['total_bruto'], $v1['total_liquido'], $v1['total_imposto'], $v1['desconto'], $v1['pos_troco'], $v1['cliente_id'], $v1['nome_tabela']]);
        $this->assertSame(['5130.00', '298.00', $this->ids['h2']], [$v2['total_bruto'], $v2['pos_troco'], $v2['cliente_id']]);
        $this->assertSame([['pm_tpa', '16296.30'], ['pm_num', '6275.70']], array_map(fn ($p) => [$p['meio_id'], $p['valor']], $v1['pos_pagamentos']));
        $this->assertSame([['pm_tpa', '3703.70'], ['pm_num', '1426.30']], array_map(fn ($p) => [$p['meio_id'], $p['valor']], $v2['pos_pagamentos']));
        $this->assertSame(sprintf('FR H01%d/1', $this->ano), $v1['numero_documento']);
        $this->assertStringStartsWith('Alojamento Quarto 1 · 2 diária(s) · 10/03/', $v1['itens_venda'][0]['descricao']);
        $this->assertSame('8.000', $this->stock());   // os consumos saem do stock no check-out

        $f1 = $this->getJson("/api/pos/hotelaria/estadias/{$e1}", $this->s)->assertOk()->json('dados');
        $this->assertSame(['FECHADA', '2.000', 'RECALCULAR', '10.0000', $v1['id'], $v1['numero_documento'], $this->ids['sessao']],
            [$f1['estado'], $f1['quantidade_final'], $f1['opcao_atraso'], $f1['percentagem_desconto'], $f1['venda_id'], $f1['numero_venda'], $f1['sessao_fecho_id']]);
        $this->assertSame("Check-out: {$v1['numero_documento']} (2 diária(s), saída tardia: recálculo cobrado, desconto 10%).", end($f1['historico_alteracoes'])['texto']);
        $this->getJson("/api/pos/hotelaria/estadias/{$e2}", $this->s)->assertJsonPath('dados.quantidade_final', '1.000')->assertJsonPath('dados.opcao_atraso', 'MANTER');
        $this->assertSame([[$v1['id'], $e1], [$v2['id'], $e2]], app(ContextoEmpresa::class)->executarComo($this->empresa->id,
            fn () => DB::table('vendas_estadias_hotel')->orderBy('venda_id')->get()->map(fn ($x) => [(int) $x->venda_id, (int) $x->estadia_hotel_id])->all()));
        $this->postJson($url, $base + ['pagamentos' => $pagamentos], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'ESTADIA_NAO_ABERTA');
    }

    #[Test]
    public function checkout_em_factura_unica_e_saida_tardia_a_hora(): void
    {
        $e1 = $this->checkin(['produto_quarto_id' => $this->ids['q1'], 'modo' => 'HORA', 'quantidade' => 3])->assertCreated()->json('dados.id');
        $e2 = $this->checkin(['produto_quarto_id' => $this->ids['q2'], 'cliente_hospede_id' => $this->ids['h2']])->assertCreated()->json('dados.id');
        $url = "/api/pos/hotelaria/sessoes/{$this->ids['sessao']}/checkout";

        // 20:30: 2 h 30 depois das 18:00 → à hora, 6 h decorridas (5 h 30 arredondadas para cima)
        $this->irPara(10, 20, 30);
        $this->getJson("/api/pos/hotelaria/estadias/{$e1}", $this->s)->assertJsonPath('dados.proposta_atraso', ['quantidade' => '6.000', 'extra' => '3.000']);
        $unica = ['estadias' => [['id' => $e1, 'opcao_atraso' => 'MANTER'], ['id' => $e2]], 'modo_faturacao' => 'UNICA'];
        $this->postJson($url, $unica + ['pagamentos' => [['meio_id' => 'pm_num', 'valor' => 10000]]], $this->s)->assertStatus(422);   // falta o cliente
        // 3 × 1 140 + 5 700 = 9 120; numerário 10 000 → troco 880
        $r = $this->postJson($url, $unica + ['cliente_id' => $this->ids['emp'], 'pagamentos' => [['meio_id' => 'pm_num', 'valor' => 10000]]], $this->s)->assertCreated()->json('dados');
        $this->assertCount(1, $r['vendas']);
        $v = $r['vendas'][0];
        $this->assertSame(['9120.00', '880.00', $this->ids['emp'], 'Quarto 1, Quarto 2', 2], [$v['total_bruto'], $v['pos_troco'], $v['cliente_id'], $v['nome_tabela'], count($v['itens_venda'])]);
        $this->assertSame([['pm_num', '9120.00']], array_map(fn ($p) => [$p['meio_id'], $p['valor']], $v['pos_pagamentos']));
        foreach ([$e1, $e2] as $e) {
            $this->getJson("/api/pos/hotelaria/estadias/{$e}", $this->s)->assertJsonPath('dados.estado', 'FECHADA')->assertJsonPath('dados.venda_id', $v['id']);
        }

        // TPA pelo total e numerário a mais: o troco vem todo do numerário
        $e3 = $this->checkin(['produto_quarto_id' => $this->ids['q2']])->assertCreated()->json('dados.id');
        $e4 = $this->checkin(['produto_quarto_id' => $this->ids['q1']])->assertCreated()->json('dados.id');
        $r = $this->postJson($url, ['estadias' => [['id' => $e3], ['id' => $e4]], 'modo_faturacao' => 'POR_QUARTO',
            'pagamentos' => [['meio_id' => 'pm_tpa', 'valor' => 17100], ['meio_id' => 'pm_num', 'valor' => 500]]], $this->s)->assertCreated()->json('dados');
        $this->assertSame(['500.00', '0.00', '500.00'], [$r['troco'], $r['vendas'][0]['pos_troco'], $r['vendas'][1]['pos_troco']]);
        $this->assertSame([['pm_tpa', '11400.00']], array_map(fn ($p) => [$p['meio_id'], $p['valor']], $r['vendas'][1]['pos_pagamentos']));
    }

    #[Test]
    public function normalizar_traduz_os_json_do_legado_e_e_idempotente(): void
    {
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            $e = EstadiaHotel::create(['produto_quarto_id' => $this->ids['q1'], 'nome_quarto' => 'Quarto 1', 'estado' => 'FECHADA', 'modo' => 'DIA', 'quantidade' => 1,
                'preco_unitario' => 11400, 'entrada_em' => now(), 'saida_prevista_em' => now()->addDay(),
                'itens' => [['name' => 'Bebida', 'price' => 1140, 'quantity' => 1, 'tax_rate' => 14, 'product_id' => $this->ids['p']]],
                'historico_alteracoes' => [['at' => '2026-09-15T12:01:36.085Z', 'by' => 'admin', 'text' => 'Check-in']]]);
            $svc = app(ServicoMigracaoHotelaria::class);
            $svc->normalizar();
            $svc->normalizar();
            $e->refresh();
            $this->assertSame([['descricao' => 'Bebida', 'preco_unitario' => 1140, 'produto_id' => $this->ids['p'], 'quantidade' => 1, 'taxa_imposto' => 14]],
                array_map(fn ($i) => collect($i)->sortKeys()->all(), $e->itens));
            $this->assertSame([['em' => '2026-09-15T12:01:36.085Z', 'por' => 'admin', 'texto' => 'Check-in']], $e->historico_alteracoes);
            $this->assertSame('1140.00', $e->totalConsumos());
        });
    }
}
