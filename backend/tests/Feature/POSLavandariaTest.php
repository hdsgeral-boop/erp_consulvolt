<?php

namespace Tests\Feature;

use App\Models\Armazem;
use App\Models\Colaborador;
use App\Models\DocumentoTesouraria;
use App\Models\Empresa;
use App\Models\LancamentoContabil;
use App\Models\PagamentoLavandaria;
use App\Models\PedidoLavandaria;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\StockArmazem;
use App\Models\Terceiro;
use App\Models\Venda;
use App\Services\Logistica\ServicoStock;
use App\Services\POS\ServicoMigracaoLavandaria;
use App\Services\Vendas\ServicoConfigVendas;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * POS parte 3a (ADR-049): lavandaria e alfaiataria — tabelas, ordens de serviço, orçamentos, materiais, facturação
 * (factura-recibo × factura + recibo), Consumidor Final, entrega com armazenagem, integração na sessão POS, anulações,
 * reclamações com segregação decidir × pagar e normalização dos dados migrados.
 * Peça «Camisa»: 1 140 Kz com IVA 14 % (base 1 000); serviço «Lavar» (6221); taxas na 6222.
 */
final class POSLavandariaTest extends TestCase
{
    private Empresa $empresa;

    private array $ids = [];

    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000049']);
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['311' => 'Clientes', '3452' => 'IVA liquidado', '6221' => 'Serviços de lavandaria', '6222' => 'Taxas da lavandaria', '611' => 'Vendas',
                '487' => 'Transitória transferências', '488' => 'Transitória TPA', '489' => 'Transitória numerário', '4511' => 'Caixa', '43101' => 'Banco',
                '7811' => 'Indemnizações', '2611' => 'Mercadorias', '7111' => 'CMV', '6881' => 'Sobras', '7881' => 'Quebras'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            app(ServicoConfigVendas::class)->definir(['clientes_default' => '311', 'iva_vendas' => '3452']);
            $this->ids['a'] = Armazem::create(['nome' => 'Loja', 'predefinido' => true])->id;
            $this->ids['cliente'] = Terceiro::create(['nome' => 'Cliente Empresa', 'nif' => '5000000009', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '311'])->id;
            $this->ids['cf'] = Terceiro::create(['nome' => 'Consumidor Final', 'nif' => '999999999', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '311'])->id;
            $this->ids['mat'] = Produto::create(['codigo' => 'MAT1', 'nome' => 'Linha de coser', 'preco_unitario' => 100, 'taxa_imposto' => 14, 'codigo_conta' => '611',
                'movimenta_stock' => true, 'conta_inventario' => '2611', 'conta_custo' => '7111'])->id;
            app(ServicoStock::class)->entrada($this->ids['mat'], $this->ids['a'], '10', '50', now()->toDateString(), 'Stock inicial');
        });
        $this->s = $this->sessao(['pos_view', 'pos_venda', 'pos_fecho', 'pos_integrar', 'pos_descontabilizar', 'pos_terminais_gerir', 'lav_ordens', 'lav_receber',
            'lav_anular', 'lav_tabelas']);
        $this->putJson('/api/pos/definicoes', ['conta_sobra' => '6881', 'conta_quebra' => '7881', 'tolerancia_desvio' => 5], $this->s)->assertOk();
        $this->ids['t'] = $this->postJson('/api/pos/terminais', ['codigo' => 'L01', 'nome' => 'Lavandaria', 'tipo' => 'LAVANDARIA', 'armazem_id' => $this->ids['a'],
            'fundo_maneio_padrao' => 0, 'meios_pagamento' => [
                ['id' => 'pm_num', 'tipo' => 'NUMERARIO', 'nome' => 'Numerário', 'conta_transitoria' => '489', 'conta_liquidacao' => '4511'],
                ['id' => 'pm_tpa', 'tipo' => 'TPA', 'nome' => 'Multicaixa', 'conta_transitoria' => '488', 'conta_liquidacao' => '43101'],
                ['id' => 'pm_trf', 'tipo' => 'TRANSFERENCIA', 'nome' => 'Transferência', 'conta_transitoria' => '487', 'conta_liquidacao' => '43101'],
            ]], $this->s)->assertCreated()->json('dados.id');
        $this->putJson('/api/pos/lavandaria/definicoes', ['conta_extras' => '6222', 'conta_compensacao' => '7811'], $this->s)->assertOk();
        $this->ids['lavar'] = $this->postJson('/api/pos/lavandaria/servicos', ['nome' => 'Lavar e engomar', 'grupo' => 'LAVANDARIA', 'codigo_conta' => '6221',
            'taxa_imposto' => 14, 'dias_entrega' => 2], $this->s)->assertCreated()->assertJsonPath('dados.codigo', 'SV0001')->json('dados.id');
        $this->ids['bainha'] = $this->postJson('/api/pos/lavandaria/servicos', ['nome' => 'Bainha', 'grupo' => 'ALFAIATARIA', 'codigo_conta' => '6221', 'taxa_imposto' => 14,
            'requer_orcamento' => true], $this->s)->assertCreated()->json('dados.id');
        $this->ids['camisa'] = $this->postJson('/api/pos/lavandaria/pecas', ['nome' => 'Camisa', 'tecido' => 'Algodão', 'cor' => 'Branco', 'unidade' => 'PECA',
            'preco' => 1140], $this->s)->assertCreated()->assertJsonPath('dados.codigo', 'PC0001')->json('dados.id');
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function naEmpresa(callable $fn): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, $fn);
    }

    private function lancamentos(string $origem): array
    {
        return $this->naEmpresa(fn () => LancamentoContabil::query()->where('tipo_origem', $origem)->whereNull('estorno_de_id')
            ->whereNull('estornado_por_id')->get()->map(fn ($l) => "{$l->tipo_dc} {$l->codigo_conta} {$l->valor}")->sort()->values()->all());
    }

    private function abrir(): int
    {
        return $this->postJson("/api/pos/terminais/{$this->ids['t']}/sessoes", [], $this->s)->assertCreated()->json('dados.id');
    }

    private function linha(array $extra = []): array
    {
        return $extra + ['peca_id' => $this->ids['camisa'], 'produto_id' => $this->ids['lavar'], 'quantidade' => 1, 'estado_entrada' => 'Bom estado'];
    }

    #[Test]
    public function tabelas_e_definicoes_validam_no_servidor(): void
    {
        $s = $this->s;
        $this->postJson('/api/pos/lavandaria/servicos', ['nome' => 'X', 'grupo' => 'LAVANDARIA', 'codigo_conta' => '611'], $s)
            ->assertStatus(422)->assertJsonPath('codigo', 'CONTA_CLASSE_INVALIDA');
        $this->postJson('/api/pos/lavandaria/pecas', ['nome' => 'camisa', 'tecido' => 'algodão', 'unidade' => 'PECA', 'preco' => 10], $s)
            ->assertStatus(422)->assertJsonPath('codigo', 'PECA_DUPLICADA');
        $this->postJson('/api/pos/lavandaria/pecas', ['nome' => 'Roupa diversa', 'unidade' => 'KG', 'preco' => 800,
            'precos_servico' => [['produto_id' => $this->ids['lavar'], 'preco' => 900], ['produto_id' => $this->ids['mat'], 'preco' => 5]]], $s)
            ->assertStatus(422)->assertJsonPath('codigo', 'SERVICO_INVALIDO');
        $this->postJson('/api/pos/lavandaria/pecas', ['nome' => 'Roupa diversa', 'unidade' => 'KG', 'preco' => 800,
            'precos_servico' => [['produto_id' => $this->ids['lavar'], 'preco' => 900]]], $s)->assertCreated()->assertJsonPath('dados.codigo', 'PC0002')
            ->assertJsonPath('dados.precos_servico.0.preco', '900.00');
        $this->putJson('/api/pos/lavandaria/definicoes', ['conta_compensacao' => '6881'], $s)->assertStatus(422)->assertJsonPath('codigo', 'CONTA_CLASSE_INVALIDA');
        $d = $this->putJson('/api/pos/lavandaria/definicoes', ['fator_prazo_urgencia' => 0.5, 'percentagem_adiantamento' => 40], $s)->assertOk()->json('dados');
        $this->assertSame([0.5, '40.00', '6222'], [$d['fator_prazo_urgencia'], $d['percentagem_adiantamento'], $d['conta_extras']]);
        $this->putJson('/api/pos/lavandaria/definicoes', ['percentagem_adiantamento' => 50], $this->sessao(['lav_ordens']))->assertForbidden();
    }

    #[Test]
    public function consumidor_final_adiantamento_factura_entrega_e_integracao_da_sessao(): void
    {
        $s = $this->s;
        $sessao = $this->abrir();
        $base = ['cliente_id' => $this->ids['cf'], 'urgente' => true, 'itens' => [$this->linha(['quantidade' => 2])]];
        $this->postJson("/api/pos/lavandaria/sessoes/{$sessao}/ordens", ['itens' => [$this->linha(['estado_entrada' => null])]] + $base, $s)
            ->assertStatus(422)->assertJsonPath('codigo', 'ESTADO_ENTRADA_EM_FALTA');
        $this->postJson("/api/pos/lavandaria/sessoes/{$sessao}/ordens", ['itens' => [$this->linha(['estado_entrada' => 'Manchas'])]] + $base, $s)
            ->assertStatus(422)->assertJsonPath('codigo', 'DANO_SEM_DESCRICAO');
        // 2 × 1 140 + urgência 50 % (1 140) = 3 420; Consumidor Final paga pelo menos 50 % (1 710)
        $this->postJson("/api/pos/lavandaria/sessoes/{$sessao}/ordens", $base + ['valor' => 1000, 'pagamentos' => [['meio_id' => 'pm_num', 'valor' => 1000]]], $s)
            ->assertStatus(422)->assertJsonPath('codigo', 'ADIANTAMENTO_MINIMO')->assertJsonPath('erros.minimo', '1710.00');
        $r = $this->postJson("/api/pos/lavandaria/sessoes/{$sessao}/ordens", $base + ['valor' => 1710, 'pagamentos' => [['meio_id' => 'pm_num', 'valor' => 2000]]], $s)
            ->assertCreated()->json('dados');
        $ano = now()->year;
        $this->assertSame(["OS/L01/{$ano}/00001", ['L0100001-01', 'L0100001-02'], 'RECEBIDA', 'LAV-URG'],
            [$r['pedido']['numero_encomenda'], $r['pedido']['itens'][0]['etiquetas'], $r['pedido']['estado'], Produto::withoutGlobalScopes()->find($r['pedido']['extras'][0]['produto_id'])->codigo]);
        // recebido ≠ total: factura em conta corrente (serviços confirmados) + recibo; troco 290 em numerário
        $this->assertSame(['FT', '3420.00'], [$r['documento']['tipo_documento'], $r['documento']['total_bruto']]);
        $this->assertSame(["RC-LAV/L01/{$ano}/00001", 'PAGAMENTO', '1710.00', '290.00'],
            [$r['recibo']['numero_recibo'], $r['recibo']['natureza_registo'], $r['recibo']['montante'], $r['recibo']['troco']]);
        $os = $r['pedido']['id'];
        $this->assertSame(['PARCIAL', '1710.00'], $this->naEmpresa(fn () => [Venda::find($r['documento']['id'])->estado, (string) Venda::find($r['documento']['id'])->valor_pendente]));

        $this->postJson("/api/pos/lavandaria/ordens/{$os}/linhas", ['accao' => 'PRONTA'], $s)->assertStatus(422)->assertJsonPath('codigo', 'SEM_LINHAS_EM_CONDICOES');
        $this->postJson("/api/pos/lavandaria/ordens/{$os}/linhas", ['accao' => 'INICIAR', 'executado_por' => 'Técnico'], $s)->assertOk()->assertJsonPath('dados.estado', 'EM_EXECUCAO');
        $this->postJson("/api/pos/lavandaria/ordens/{$os}/linhas", ['accao' => 'PRONTA'], $s)->assertOk()->assertJsonPath('dados.estado', 'PRONTA');

        // entrega: o Consumidor Final paga o saldo facturado antes de levantar
        $this->postJson("/api/pos/lavandaria/ordens/{$os}/entrega/simulacao", ['linhas' => [1]], $s)->assertOk()->assertJsonPath('dados.saldo_facturado', '1710.00')
            ->assertJsonPath('dados.venda_directa_possivel', false);
        $this->postJson("/api/pos/lavandaria/sessoes/{$sessao}/ordens/{$os}/entregar", ['linhas' => [1], 'valor' => 1000, 'pagamentos' => [['meio_id' => 'pm_tpa', 'valor' => 1000]]], $s)
            ->assertStatus(422)->assertJsonPath('codigo', 'PAGAMENTO_OBRIGATORIO');
        $e = $this->postJson("/api/pos/lavandaria/sessoes/{$sessao}/ordens/{$os}/entregar", ['linhas' => [1], 'valor' => 1710, 'pagamentos' => [['meio_id' => 'pm_tpa', 'valor' => 1710]]], $s)
            ->assertOk()->json('dados');
        $this->assertSame(['ENTREGUE', 'PAGAMENTO', null], [$e['pedido']['estado'], $e['recibo']['natureza_registo'], $e['documento']]);
        $this->assertSame('PAGO', $this->naEmpresa(fn () => Venda::find($r['documento']['id'])->estado));
        $this->getJson("/api/pos/lavandaria/ordens/{$os}", $s)->assertOk()->assertJsonPath('dados.totais.saldo', '0.00')->assertJsonPath('dados.totais.facturado', '3420.00');

        // Z: os recibos entram por meio e no numerário, mas não nas vendas
        $x = $this->getJson("/api/pos/sessoes/{$sessao}/relatorio-x", $s)->assertOk()->json('dados');
        $this->assertSame(['0.00', 0, '1710.00', '1710.00', 2, '3420.00', 1], [$x['total_vendas'], $x['numero_vendas'], $x['vendas_numerario'], $x['numerario_esperado'],
            $x['lavandaria']['numero_recibos'], $x['lavandaria']['total_recibos'], $x['lavandaria']['numero_faturas']]);
        $this->assertSame([['pm_num', '1710.00', 1], ['pm_tpa', '1710.00', 1]], array_map(fn ($m) => [$m['meio_id'], $m['valor'], $m['quantidade']], $x['totais_por_metodo']));
        $z = $this->postJson("/api/pos/sessoes/{$sessao}/fechar", ['numerario_contado' => 1710, 'fechos_tpa' => [['meio_id' => 'pm_tpa', 'valor_talao' => 1710, 'operacoes_talao' => 1]]], $s)
            ->assertOk()->json('dados');
        $this->assertSame(['SEM_DESVIO', 'PENDENTE'], [$z['estado_desvio'], $z['estado_contabilizacao']]);

        // integração: FT D cliente / C proveitos + taxas + IVA; recibos D transitórias / C cliente
        $this->postJson("/api/pos/sessoes/{$sessao}/contabilizar", [], $s)->assertOk()->assertJsonPath('dados.estado_contabilizacao', 'CONTABILIZADA');
        $this->assertSame(['C 311 1710.00', 'C 311 1710.00', 'C 3452 420.00', 'C 6221 2000.00', 'C 6222 1000.00', 'D 311 3420.00', 'D 488 1710.00', 'D 489 1710.00'],
            $this->lancamentos('POS'));
        $this->assertTrue($this->naEmpresa(fn () => Venda::find($r['documento']['id'])->contabilizado));
        $this->postJson("/api/vendas/documentos/{$r['documento']['id']}/contabilizar", [], $this->sessao(['vendas_fat_contabilizar']))
            ->assertStatus(422);
        $this->postJson("/api/pos/sessoes/{$sessao}/descontabilizar", ['motivo' => 'Correcção'], $s)->assertOk();
        $this->assertSame([], $this->lancamentos('POS'));
        $this->assertSame([null, null], $this->naEmpresa(fn () => PagamentoLavandaria::query()->orderBy('id')->pluck('lans_contabilizacao')->all()));
    }

    #[Test]
    public function factura_recibo_orcamento_materiais_e_permissoes(): void
    {
        $s = $this->s;
        $sessao = $this->abrir();
        $itens = [$this->linha(), $this->linha(['produto_id' => $this->ids['bainha'], 'preco' => 500])];
        $this->postJson("/api/pos/lavandaria/sessoes/{$sessao}/ordens", ['cliente_id' => $this->ids['cliente'], 'itens' => $itens, 'valor' => 1140,
            'pagamentos' => [['meio_id' => 'pm_tpa', 'valor' => 1140]]], $this->sessao(['lav_receber']))->assertForbidden();
        // recepção: o recebido cobre os serviços confirmados (o orçamento fica de fora) → factura-recibo na série do terminal
        $r = $this->postJson("/api/pos/lavandaria/sessoes/{$sessao}/ordens", ['cliente_id' => $this->ids['cliente'], 'modo_faturacao' => 'RECEPCAO', 'itens' => $itens,
            'valor' => 1140, 'pagamentos' => [['meio_id' => 'pm_tpa', 'valor' => 1140]]], $s)->assertCreated()->json('dados');
        $ano = now()->year;
        $this->assertSame(['FR', "FR L01{$ano}/1", '1140.00', null, 'ORCAMENTO'],
            [$r['documento']['tipo_documento'], $r['documento']['numero_documento'], $r['documento']['total_bruto'], $r['recibo'], $r['pedido']['itens'][1]['estado']]);
        $os = $r['pedido']['id'];
        $this->assertSame(['FR', '1140.00'], $this->naEmpresa(fn () => [PagamentoLavandaria::first()->natureza_registo, (string) PagamentoLavandaria::first()->montante]));

        // orçamento aprovado de 3 000: o valor é o facturável pela regra AGT (preço com IVA a 2 casas)
        $this->postJson('/api/pos/lavandaria/orcamentos', ['decisao' => 'RECUSAR', 'linhas' => [['pedido_id' => $os, 'linha_id' => 2]]], $s)
            ->assertStatus(422)->assertJsonPath('codigo', 'MOTIVO_OBRIGATORIO');
        $o = $this->postJson('/api/pos/lavandaria/orcamentos', ['decisao' => 'APROVAR', 'canal' => 'WhatsApp', 'linhas' => [['pedido_id' => $os, 'linha_id' => 2, 'valor' => 3000]]], $s)
            ->assertOk()->json('dados');
        $this->assertSame(1, $o['tratados']);
        $linha = $this->getJson("/api/pos/lavandaria/ordens/{$os}", $s)->assertOk()->json('dados.pedido.itens.1');
        $this->assertSame(['RECEBIDA', 'APROVADO', '3000.00', '2999.99'], [$linha['estado'], $linha['estado_orcamento'], $linha['preco'], $linha['valor']]);

        // materiais de alfaiataria: guia de consumo ao custo médio e CMV lançado
        $this->postJson("/api/pos/lavandaria/ordens/{$os}/linhas/1/materiais", ['produto_id' => $this->ids['mat'], 'quantidade' => 2], $s)
            ->assertStatus(422)->assertJsonPath('codigo', 'LINHA_INVALIDA');
        $this->postJson("/api/pos/lavandaria/ordens/{$os}/linhas/2/materiais", ['produto_id' => $this->ids['mat'], 'quantidade' => 20], $s)
            ->assertStatus(422)->assertJsonPath('codigo', 'STOCK_INSUFICIENTE');
        $m = $this->postJson("/api/pos/lavandaria/ordens/{$os}/linhas/2/materiais", ['produto_id' => $this->ids['mat'], 'quantidade' => 2], $s)->assertOk()->json('dados.itens.1.materiais.0');
        $this->assertSame(['2', '100.00'], [$m['quantidade'], $m['valor']]);
        $this->assertSame('8.000', (string) $this->naEmpresa(fn () => StockArmazem::query()->where('produto_id', $this->ids['mat'])->value('quantidade_stock')));
        $this->assertSame(['C 2611 100.00', 'D 7111 100.00'], $this->lancamentos('LOGISTICA'));

        // receber o orçamento: sem saldos anteriores e recebido = por facturar → nova factura-recibo
        $this->postJson("/api/pos/lavandaria/sessoes/{$sessao}/ordens/{$os}/pagamentos", ['valor' => 5000, 'pagamentos' => [['meio_id' => 'pm_num', 'valor' => 5000]]], $s)
            ->assertStatus(422)->assertJsonPath('codigo', 'VALOR_EXCEDE_SALDO');
        $p = $this->postJson("/api/pos/lavandaria/sessoes/{$sessao}/ordens/{$os}/pagamentos", ['valor' => 2999.99, 'pagamentos' => [['meio_id' => 'pm_num', 'valor' => 3000]]], $s)
            ->assertCreated()->json('dados');
        $this->assertSame(['FR', '2999.99', '0.01'], [$p['documento']['tipo_documento'], $p['documento']['total_bruto'], $p['documento']['pos_troco']]);
        $x = $this->getJson("/api/pos/sessoes/{$sessao}/relatorio-x", $s)->assertOk()->json('dados');
        $this->assertSame(['4139.99', 2, 0], [$x['total_vendas'], $x['numero_vendas'], $x['lavandaria']['numero_recibos']]);
        $this->getJson('/api/pos/lavandaria/relatorio?de='.now()->toDateString().'&ate='.now()->toDateString(), $s)->assertOk()
            ->assertJsonPath('dados.resumo.faturas_recibo_numero', 2)->assertJsonPath('dados.resumo.facturado', '4139.99')->assertJsonPath('dados.resumo.recebido', '4139.99');
    }

    #[Test]
    public function armazenagem_anulacoes_reclamacoes_e_segregacao(): void
    {
        $s = $this->s;
        $this->putJson('/api/pos/lavandaria/definicoes', ['faturar_no_adiantamento' => false], $s)->assertOk();
        $sessao = $this->abrir();
        $nova = fn (array $extra = []) => $this->postJson("/api/pos/lavandaria/sessoes/{$sessao}/ordens", $extra + ['cliente_id' => $this->ids['cliente'], 'itens' => [$this->linha()]], $s)
            ->assertCreated()->json('dados');

        // anular por «alterar estado» exige lav_anular
        $o1 = $nova()['pedido']['id'];
        $this->postJson('/api/pos/lavandaria/ordens/estado', ['ids' => [$o1], 'estado' => 'ANULADA', 'motivo' => 'Desistiu'], $this->sessao(['lav_ordens']))->assertForbidden();
        $this->postJson("/api/pos/lavandaria/ordens/{$o1}/anular", ['motivo' => 'Desistiu'], $s)->assertOk()->assertJsonPath('dados.estado', 'ANULADA');

        // adiantamento sem facturação; a ordem com recebimentos não se anula; o recibo anula-se com a sessão aberta
        $r = $nova(['valor' => 500, 'pagamentos' => [['meio_id' => 'pm_num', 'valor' => 500]]]);
        $o2 = $r['pedido']['id'];
        $this->assertSame([null, 'ADIANTAMENTO'], [$r['documento'], $r['recibo']['natureza_registo']]);
        $this->postJson("/api/pos/lavandaria/ordens/{$o2}/anular", ['motivo' => 'Teste'], $s)->assertStatus(422)->assertJsonPath('codigo', 'ORDEM_NAO_ANULAVEL');
        $this->postJson("/api/pos/lavandaria/pagamentos/{$r['recibo']['id']}/anular", ['motivo' => 'Engano'], $this->sessao(['lav_receber']))->assertForbidden();
        $this->postJson("/api/pos/lavandaria/pagamentos/{$r['recibo']['id']}/anular", ['motivo' => 'Engano'], $s)->assertOk()->assertJsonPath('dados.estado', 'ANULADO');
        $this->postJson("/api/pos/lavandaria/pagamentos/{$r['recibo']['id']}/anular", ['motivo' => 'Engano'], $s)->assertStatus(422)->assertJsonPath('codigo', 'JA_ANULADO');
        $this->assertSame('0.00', $this->getJson("/api/pos/sessoes/{$sessao}/relatorio-x", $s)->json('dados.numerario_esperado'));

        // atribuição a um colaborador activo
        $colab = $this->naEmpresa(fn () => Colaborador::create(['nome_completo' => 'Técnico Um', 'estado' => 'ACTIVO'])->id);
        $this->postJson('/api/pos/lavandaria/ordens/atribuir', ['ids' => [$o2], 'colaborador_id' => $colab], $s)->assertOk()->assertJsonPath('dados.alteradas', 1);
        $this->assertSame(['Técnico Um', 'Técnico Um'], $this->naEmpresa(fn () => [PedidoLavandaria::find($o2)->nome_atribuido, PedidoLavandaria::find($o2)->itens[0]['executado_por']]));

        // peça pronta há 35 dias (prazo 30): 5 dias × 2 % × 1 140 = 114 de armazenagem, facturada na entrega em conta corrente
        $this->postJson('/api/pos/lavandaria/ordens/estado', ['ids' => [$o2], 'estado' => 'PRONTA'], $s)->assertOk()->assertJsonPath('dados.alteradas', 1);
        $this->naEmpresa(function () use ($o2) {
            $p = PedidoLavandaria::find($o2);
            $p->update(['itens' => [array_merge($p->itens[0], ['pronta_em' => now()->subDays(35)->toIso8601String()])]]);
        });
        $this->postJson("/api/pos/lavandaria/ordens/{$o2}/entrega/simulacao", ['linhas' => [1]], $s)->assertOk()
            ->assertJsonPath('dados.taxa_armazenagem.valor', '114.00')->assertJsonPath('dados.por_facturar', '1254.00');
        $e = $this->postJson("/api/pos/lavandaria/sessoes/{$sessao}/ordens/{$o2}/entregar", ['linhas' => [1]], $s)->assertOk()->json('dados');
        $this->assertSame(['FT', '1254.00', 'ENTREGUE', 'ARMAZEM'], [$e['documento']['tipo_documento'], $e['documento']['total_bruto'], $e['pedido']['estado'],
            $e['pedido']['extras'][0]['chave']]);

        // reclamação: comprovativo obrigatório; quem decide não paga; o pagamento é um documento de tesouraria
        $rc = $this->postJson("/api/pos/lavandaria/ordens/{$o2}/linhas/1/reclamacoes", ['descricao' => 'Mancha na gola após a lavagem', 'valor_declarado' => 6000], $s)
            ->assertCreated()->assertJsonPath('dados.estado', 'AGUARDA_COMPROVATIVO')->json('dados.id');
        $decisor = $this->sessao(['lav_dano_decidir', 'lav_dano_pagar']);
        $this->postJson("/api/pos/lavandaria/reclamacoes/{$rc}/decidir", ['decisao' => 'APROVADA'], $decisor)->assertStatus(422)->assertJsonPath('codigo', 'COMPROVATIVO_EM_FALTA');
        $this->postJson("/api/pos/lavandaria/reclamacoes/{$rc}/pagar", ['data' => now()->toDateString(), 'conta_financeira' => '4511'], $decisor)
            ->assertStatus(422)->assertJsonPath('codigo', 'RECLAMACAO_NAO_APROVADA');
        $this->postJson("/api/pos/lavandaria/reclamacoes/{$rc}/decidir", ['decisao' => 'APROVADA', 'referencia_comprovativo' => 'FT 123', 'valor_comprovativo' => 5000], $decisor)
            ->assertOk()->assertJsonPath('dados.valor_compensacao', '5000.00');
        $this->postJson("/api/pos/lavandaria/reclamacoes/{$rc}/pagar", ['data' => now()->toDateString(), 'conta_financeira' => '4511'], $decisor)
            ->assertStatus(403)->assertJsonPath('codigo', 'SEGREGACAO_FUNCOES');
        $pago = $this->postJson("/api/pos/lavandaria/reclamacoes/{$rc}/pagar", ['data' => now()->toDateString(), 'conta_financeira' => '4511'], $this->sessao(['lav_dano_pagar']))
            ->assertOk()->assertJsonPath('dados.estado', 'PAGA')->json('dados');
        $doc = $this->naEmpresa(fn () => DocumentoTesouraria::with('itensDocumentoTesouraria')->find($pago['documento_tesouraria_id']));
        $this->assertSame(['PAGAMENTO', 'PENDENTE', '5000.00', '7811', 'D'], [$doc->tipo, $doc->estado, (string) $doc->valor_total,
            $doc->itensDocumentoTesouraria[0]->codigo_conta, $doc->itensDocumentoTesouraria[0]->tipo_dc]);

        // depois do Z o recibo já não se anula
        $r3 = $nova(['valor' => 300, 'pagamentos' => [['meio_id' => 'pm_trf', 'valor' => 300, 'referencia' => 'TRF-9']]]);
        $trf = $this->getJson("/api/pos/sessoes/{$sessao}/relatorio-x", $s)->assertOk()->json('dados.transferencias.0');
        $this->assertSame([null, $r3['recibo']['numero_recibo'], '300.00', 'TRF-9', $this->ids['cliente']],
            [$trf['venda_id'], $trf['numero_documento'], $trf['valor'], $trf['referencia'], $trf['terceiro_id']]);
        $this->postJson("/api/pos/sessoes/{$sessao}/fechar", ['numerario_contado' => 0], $s)->assertOk()->assertJsonPath('dados.estado_desvio', 'SEM_DESVIO')
            ->assertJsonPath('dados.transferencias.0.numero_documento', $r3['recibo']['numero_recibo']);
        $this->postJson("/api/pos/sessoes/{$sessao}/contabilizar", [], $s)->assertOk();
        $this->assertSame(['C 311 300.00', 'C 3452 154.00', 'C 6221 1000.00', 'C 6222 100.00', 'D 311 1254.00', 'D 487 300.00'],
            $this->lancamentos('POS'));
        $this->postJson("/api/pos/lavandaria/pagamentos/{$r3['recibo']['id']}/anular", ['motivo' => 'Tarde'], $s)->assertStatus(422)->assertJsonPath('codigo', 'RECIBO_SESSAO_FECHADA');
        $this->postJson("/api/pos/lavandaria/sessoes/{$sessao}/ordens/{$r3['pedido']['id']}/pagamentos", ['valor' => 100, 'pagamentos' => [['meio_id' => 'pm_num', 'valor' => 100]]], $s)
            ->assertStatus(422)->assertJsonPath('codigo', 'SESSAO_NAO_ABERTA');
        $l = $this->getJson('/api/pos/lavandaria/ordens?estado=TODAS', $s)->assertOk()->json('dados');
        $this->assertSame(3, count($l['ordens']));
    }

    #[Test]
    public function normalizacao_dos_dados_migrados_e_idempotente(): void
    {
        $id = $this->naEmpresa(fn () => DB::table('pedidos_lavandaria')->insertGetId(['empresa_id' => $this->empresa->id, 'numero_encomenda' => 'OS/L01/2026/00009',
            'estado' => 'RECEBIDA', 'cliente_id' => $this->ids['cliente'], 'recolha' => json_encode(['enabled' => false, 'address' => '', 'date' => '', 'fee' => null]),
            'itens' => json_encode([['line_id' => 1, 'name' => 'Lavar', 'qty' => 3, 'price' => 3333.33, 'tax_rate' => 14, 'status' => 'RECEBIDA', 'tags' => ['L0100009-01'],
                'materials' => [['product_id' => 5, 'name' => 'Linha', 'qty' => 1, 'at' => 'x', 'by' => 'y']], 'quote_required' => false, 'entry_state' => 'Bom estado']]),
            'extras' => json_encode([['id' => 'URGENCIA-1', 'key' => 'URGENCIA', 'product_id' => 7, 'name' => 'Urgência', 'amount' => 500, 'tax_rate' => 14]]),
            'historico_alteracoes' => json_encode([['at' => '2026-09-15T10:00:00Z', 'by' => 'admin', 'text' => 'Ordem recebida']]), 'atribuicoes' => json_encode([])]));
        $this->naEmpresa(fn () => DB::table('pagamentos_lavandaria')->insert(['empresa_id' => $this->empresa->id, 'pedido_lavandaria_id' => $id, 'montante' => 1000,
            'natureza_registo' => 'ADIANTAMENTO', 'estado' => 'REGISTADO', 'pos_pagamentos' => json_encode([['pm_id' => 'pm_num', 'kind' => 'NUMERARIO', 'name' => 'Numerário',
                'amount' => 1000, 'transit_account' => '489']])]));
        $servico = app(ServicoMigracaoLavandaria::class);
        $servico->normalizar();
        $antes = DB::table('pedidos_lavandaria')->where('id', $id)->first();
        $servico->normalizar();
        $depois = DB::table('pedidos_lavandaria')->where('id', $id)->first();
        $this->assertEquals($antes, $depois);
        $item = json_decode($depois->itens, true)[0];
        $this->assertSame(['Lavar', 3, 'RECEBIDA', '9999.99', 'Linha', 'L0100009-01'],
            [$item['nome'], $item['quantidade'], $item['estado'], $item['valor'], $item['materiais'][0]['nome'], $item['etiquetas'][0]]);
        $this->assertSame(['URGENCIA', '500.00'], [json_decode($depois->extras, true)[0]['chave'], json_decode($depois->extras, true)[0]['valor']]);
        $this->assertEquals(['ativa' => false, 'morada' => '', 'data' => '', 'taxa' => null], json_decode($depois->recolha, true));
        $this->assertSame('Ordem recebida', json_decode($depois->historico_alteracoes, true)[0]['texto']);
        $pag = json_decode(DB::table('pagamentos_lavandaria')->where('pedido_lavandaria_id', $id)->value('pos_pagamentos'), true)[0];
        $this->assertSame(['pm_num', 'NUMERARIO', 1000, '489'], [$pag['meio_id'], $pag['tipo'], $pag['valor'], $pag['conta_transitoria']]);
        $this->getJson("/api/pos/lavandaria/ordens/{$id}", $this->s)->assertOk()->assertJsonPath('dados.totais.total', '10499.99')->assertJsonPath('dados.totais.saldo', '9499.99');
    }

    /** Afinação da Fase 5 (ADR-064): colaboradores (só id e nome) com as permissões da lavandaria, sem as do RH. */
    #[Test]
    public function colaboradores_para_atribuir_com_lav_ordens(): void
    {
        $this->naEmpresa(function () {
            Colaborador::create(['nome_completo' => 'Beatriz', 'nif' => 'B1', 'estado' => 'ACTIVO']);
            Colaborador::create(['nome_completo' => 'Alberto', 'nif' => 'A1', 'estado' => 'ACTIVO']);
            Colaborador::create(['nome_completo' => 'Carlos', 'nif' => 'C1', 'estado' => 'INACTIVO']);
        });
        $s = $this->sessao(['lav_ordens']);
        $this->assertSame([['nome' => 'Alberto', 'activo' => true], ['nome' => 'Beatriz', 'activo' => true]],
            array_map(fn ($c) => array_diff_key($c, ['id' => 1]), $this->getJson('/api/pos/lavandaria/colaboradores', $s)->assertOk()->json('dados')));
        $this->assertSame(['Alberto', 'Beatriz', 'Carlos'], array_column($this->getJson('/api/pos/lavandaria/colaboradores?todos=1', $s)->json('dados'), 'nome'));
        $this->assertSame(['id', 'nome', 'activo'], array_keys($this->getJson('/api/pos/lavandaria/colaboradores', $s)->json('dados.0')));   // nada mais do RH
        $this->getJson('/api/rh/colaboradores', $s)->assertForbidden();
        $this->getJson('/api/pos/lavandaria/colaboradores', $this->sessao(['vendas_view']))->assertForbidden();
    }
}
