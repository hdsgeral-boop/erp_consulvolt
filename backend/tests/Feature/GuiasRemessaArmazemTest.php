<?php

namespace Tests\Feature;

use App\Models\Armazem;
use App\Models\Empresa;
use App\Models\EncomendaCompra;
use App\Models\GuiaSaida;
use App\Models\PedidoCompra;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\RececaoCompra;
use App\Models\StockArmazem;
use App\Models\Terceiro;
use App\Models\Venda;
use App\Services\Logistica\ServicoStock;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Armazém › «Emitir Guia de Saída» para clientes (js/ui_warehouse.js:603-1008) — a saída é a GR das Vendas (ADR-043):
 * encomendas vinculadas a compras (back-to-back), entrega parcial da encomenda, stock do armazém verificado, lista
 * «Guias de Saída e Entrega» com as GR do armazém e permissões (armazem_guias_emitir E vendas_fat_emitir).
 */
final class GuiasRemessaArmazemTest extends TestCase
{
    private Empresa $empresa;

    private array $ids = [];

    private array $s;

    private string $hoje;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hoje = now()->toDateString();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000777']);
        $this->naEmpresa(function () {
            foreach (['311' => 'Clientes', '3452' => 'IVA liquidado', '611' => 'Vendas', '2611' => 'Mercadorias', '7111' => 'CMV'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            $this->ids['cliente'] = Terceiro::create(['nome' => 'Cliente B2B', 'nif' => '5000000071', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '311'])->id;
            $this->ids['outro'] = Terceiro::create(['nome' => 'Outro cliente', 'nif' => '5000000072', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '311'])->id;
            $this->ids['forn'] = Terceiro::create(['nome' => 'Fornecedor', 'nif' => '5000000073', 'tipo' => Terceiro::FORNECEDOR])->id;
            $this->ids['p'] = Produto::create(['codigo' => 'P1', 'nome' => 'Bomba', 'preco_unitario' => 1000, 'taxa_imposto' => 14, 'codigo_conta' => '611',
                'conta_iva_liquidado' => '3452', 'movimenta_stock' => true, 'conta_inventario' => '2611', 'conta_custo' => '7111'])->id;
            $this->ids['a'] = Armazem::create(['nome' => 'Central', 'predefinido' => true])->id;
            $this->ids['b'] = Armazem::create(['nome' => 'Obra'])->id;
            app(ServicoStock::class)->entrada($this->ids['p'], $this->ids['a'], '10', '600', $this->hoje, 'Stock inicial');
            app(ServicoStock::class)->entrada($this->ids['p'], $this->ids['b'], '1', '600', $this->hoje, 'Stock inicial');
        });
        $this->s = $this->sessao(['vendas_faturacao_view', 'vendas_fat_emitir', 'armazem_guias_view', 'armazem_guias_emitir', 'armazem_rececoes_view']);
    }

    private function naEmpresa(callable $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, $f);
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function stock(int $armazem): string
    {
        return (string) $this->naEmpresa(fn () => StockArmazem::query()->where('armazem_id', $armazem)->where('produto_id', $this->ids['p'])->value('quantidade_stock'));
    }

    /** NE de 5 unidades com pedido de compra e encomenda ao fornecedor (estado da encomenda de compra à escolha). */
    private function encomendaBackToBack(string $estadoCompra = 'EM_PROCESSAMENTO'): array
    {
        $ne = $this->postJson('/api/vendas/documentos', ['tipo_documento' => 'NE', 'cliente_id' => $this->ids['cliente'], 'data_emissao' => $this->hoje,
            'linhas' => [['produto_id' => $this->ids['p'], 'quantidade' => 5]]], $this->s)->assertCreated()->json('dados');
        $ec = $this->naEmpresa(function () use ($ne, $estadoCompra) {
            $pc = PedidoCompra::create(['nome_requerente' => 'Armazém', 'data' => $this->hoje, 'estado' => 'APROVADO', 'venda_origem_id' => $ne['id'], 'numero_pedido' => 'PC 1']);

            return EncomendaCompra::create(['pedido_compra_id' => $pc->id, 'fornecedor_id' => $this->ids['forn'], 'numero_encomenda' => 'EC 1', 'data' => $this->hoje,
                'estado' => $estadoCompra, 'montante_total' => 3000]);
        });

        return ['ne' => $ne, 'ec' => $ec, 'item' => $ne['linhas'][0]['id']];
    }

    private function emitir(array $dados, ?array $sessao = null): TestResponse
    {
        return $this->postJson('/api/logistica/guias-saida/remessa', $dados + ['armazem_id' => $this->ids['a'], 'data' => $this->hoje], $sessao ?? $this->s);
    }

    #[Test]
    public function lista_as_encomendas_vinculadas_a_compras_e_as_satisfeitas(): void
    {
        $b2b = $this->encomendaBackToBack();
        // NE sem compra ligada: não aparece (não é back-to-back)
        $this->postJson('/api/vendas/documentos', ['tipo_documento' => 'NE', 'cliente_id' => $this->ids['outro'], 'data_emissao' => $this->hoje,
            'linhas' => [['produto_id' => $this->ids['p'], 'quantidade' => 1]]], $this->s)->assertCreated();

        $lista = $this->getJson('/api/logistica/guias-saida/encomendas-clientes', $this->s)->assertOk()->json('dados');
        $this->assertCount(1, $lista);
        $this->assertSame($b2b['ne']['id'], $lista[0]['id']);
        $this->assertSame('Cliente B2B', $lista[0]['cliente']['nome']);
        $this->assertFalse($lista[0]['satisfeita']);
        $this->assertSame('5.000', $lista[0]['linhas'][0]['por_entregar']);

        // recepção validada da compra: passa a «satisfeita»
        $this->naEmpresa(fn () => RececaoCompra::create(['encomenda_compra_id' => $b2b['ec']->id, 'data' => $this->hoje, 'estado' => 'RECEBIDO', 'validado' => true,
            'armazem_id' => $this->ids['a'], 'numero_entrega' => 'GF 1']));
        $this->getJson('/api/logistica/guias-saida/encomendas-clientes', $this->s)->assertJsonPath('dados.0.satisfeita', true);

        // a recepção mostra a NE de origem (botão «Gerar Guia de Saída para Cliente (Back-to-Back)»)
        $this->getJson('/api/logistica/paridade/rececoes', $this->s)->assertOk()
            ->assertJsonPath('dados.0.encomenda_cliente_id', $b2b['ne']['id'])->assertJsonPath('dados.0.encomenda_cliente', $b2b['ne']['numero_documento']);

        // stock do armazém para a coluna «Stock Disp.»
        $this->getJson("/api/logistica/guias-saida/stock-armazem?armazem_id={$this->ids['a']}", $this->s)->assertOk()->assertJsonPath("dados.{$this->ids['p']}", '10.000');
    }

    #[Test]
    public function back_to_back_emite_gr_parcial_da_encomenda_sem_segundo_documento_de_stock(): void
    {
        $b2b = $this->encomendaBackToBack('RECEBIDO');
        $guiasAntes = $this->naEmpresa(fn () => GuiaSaida::query()->count());

        $gr = $this->emitir(['tipo' => 'BACK_TO_BACK', 'encomenda_id' => $b2b['ne']['id'],
            'linhas' => [['produto_id' => $this->ids['p'], 'quantidade' => 2, 'item_origem_id' => $b2b['item']]]])
            ->assertCreated()->assertJsonPath('dados.tipo_documento', 'GR')->assertJsonPath('dados.saida_armazem', 'BACK_TO_BACK')
            ->assertJsonPath('dados.cliente_id', $this->ids['cliente'])->assertJsonPath('dados.armazem_id', $this->ids['a'])->json('dados');

        $this->assertSame('8.000', $this->stock($this->ids['a']));   // um único movimento de saída (o da GR)
        $this->assertSame($guiasAntes, $this->naEmpresa(fn () => GuiaSaida::query()->count()));   // sem guia espelho no armazém
        $ne = $this->naEmpresa(fn () => Venda::query()->with('itensVenda')->find($b2b['ne']['id']));
        $this->assertSame('2.000', (string) $ne->itensVenda->first()->quantidade_entregue);   // como o delivered_qty do legado
        $this->assertSame('PENDENTE', $ne->estado);
        $this->getJson('/api/logistica/guias-saida/encomendas-clientes', $this->s)->assertJsonPath('dados.0.linhas.0.por_entregar', '3.000');

        // mais do que falta entregar: recusado; o resto entrega-se noutra guia e a NE sai da lista
        $this->emitir(['tipo' => 'BACK_TO_BACK', 'encomenda_id' => $b2b['ne']['id'],
            'linhas' => [['produto_id' => $this->ids['p'], 'quantidade' => 4, 'item_origem_id' => $b2b['item']]]])->assertStatus(422)->assertJsonPath('codigo', 'QUANTIDADE_EXCEDIDA');
        $this->emitir(['tipo' => 'VENDA', 'encomenda_id' => $b2b['ne']['id'],
            'linhas' => [['produto_id' => $this->ids['p'], 'quantidade' => 3, 'item_origem_id' => $b2b['item']]]])->assertCreated();
        $this->assertSame('5.000', $this->stock($this->ids['a']));
        $this->getJson('/api/logistica/guias-saida/encomendas-clientes', $this->s)->assertJsonCount(0, 'dados');

        // a lista do armazém mostra as GR (tipo e cliente) a par das guias de consumo, só para consulta
        $lista = $this->getJson('/api/logistica/guias-saida', $this->s)->assertOk()->json('dados');
        $this->assertCount(2, $lista);
        $this->assertSame(['GR', 'GR'], array_column($lista, 'origem'));
        $this->assertContains('BACK_TO_BACK', array_column($lista, 'tipo'));
        $this->assertSame('Cliente B2B', $lista[0]['terceiro']['nome']);
        $this->getJson('/api/logistica/guias-saida?tipo=BACK_TO_BACK', $this->s)->assertJsonCount(1, 'dados')->assertJsonPath('dados.0.venda_id', $gr['id']);
        $this->getJson('/api/logistica/guias-saida?tipo=CONSUMO', $this->s)->assertJsonCount(0, 'dados');
    }

    #[Test]
    public function a_lista_do_armazem_mostra_tambem_as_gr_emitidas_nas_vendas(): void
    {
        // GR convertida nas Vendas a partir de uma NE com processo de compra → BACK_TO_BACK; GR directa → VENDA
        $b2b = $this->encomendaBackToBack('RECEBIDO');
        $grB2b = $this->postJson("/api/vendas/documentos/{$b2b['ne']['id']}/converter", ['tipo_destino' => 'GR'], $this->s)->assertCreated()->json('dados');
        $grDirecta = $this->postJson('/api/vendas/documentos', ['tipo_documento' => 'GR', 'cliente_id' => $this->ids['outro'], 'data_emissao' => $this->hoje,
            'linhas' => [['produto_id' => $this->ids['p'], 'quantidade' => 1]]], $this->s)->assertCreated()->json('dados');
        $this->assertNull($grB2b['saida_armazem'] ?? null);

        $lista = collect($this->getJson('/api/logistica/guias-saida', $this->s)->assertOk()->json('dados'))->keyBy('venda_id');
        $this->assertSame(['BACK_TO_BACK', 'Cliente B2B'], [$lista[$grB2b['id']]['tipo'], $lista[$grB2b['id']]['terceiro']['nome']]);
        $this->assertSame(['VENDA', 'Outro cliente'], [$lista[$grDirecta['id']]['tipo'], $lista[$grDirecta['id']]['terceiro']['nome']]);
        $this->getJson('/api/logistica/guias-saida?tipo=BACK_TO_BACK', $this->s)->assertJsonCount(1, 'dados')->assertJsonPath('dados.0.venda_id', $grB2b['id']);
        $this->getJson('/api/logistica/guias-saida?tipo=VENDA', $this->s)->assertJsonCount(1, 'dados')->assertJsonPath('dados.0.venda_id', $grDirecta['id']);
    }

    #[Test]
    public function back_to_back_exige_compra_recebida_e_linhas_da_encomenda(): void
    {
        $b2b = $this->encomendaBackToBack();   // compra ainda não recebida
        $linhas = [['produto_id' => $this->ids['p'], 'quantidade' => 1, 'item_origem_id' => $b2b['item']]];
        $this->emitir(['tipo' => 'BACK_TO_BACK', 'encomenda_id' => $b2b['ne']['id'], 'linhas' => $linhas])->assertStatus(422)->assertJsonPath('codigo', 'ENCOMENDA_NAO_SATISFEITA');
        $this->emitir(['tipo' => 'BACK_TO_BACK', 'linhas' => $linhas])->assertStatus(422);   // sem encomenda
        // pela «Venda» (cartão «Expedir agora») a encomenda vinculada pode sair, mas só com as suas linhas e o seu cliente
        $this->emitir(['tipo' => 'VENDA', 'encomenda_id' => $b2b['ne']['id'], 'linhas' => [['produto_id' => $this->ids['p'], 'quantidade' => 1]]])
            ->assertStatus(422)->assertJsonPath('codigo', 'LINHA_FORA_DA_ENCOMENDA');
        $this->emitir(['tipo' => 'VENDA', 'encomenda_id' => $b2b['ne']['id'], 'cliente_id' => $this->ids['outro'], 'linhas' => $linhas])
            ->assertStatus(422)->assertJsonPath('codigo', 'CLIENTE_DIFERENTE');
        $this->assertSame('10.000', $this->stock($this->ids['a']));
    }

    #[Test]
    public function venda_directa_verifica_o_stock_do_armazem_escolhido(): void
    {
        // armazém «Obra» só tem 1: o servidor recusa (o legado só o via no ecrã)
        $this->emitir(['tipo' => 'VENDA', 'cliente_id' => $this->ids['cliente'], 'armazem_id' => $this->ids['b'],
            'linhas' => [['produto_id' => $this->ids['p'], 'quantidade' => 2]]])->assertStatus(422)->assertJsonPath('codigo', 'STOCK_INSUFICIENTE');
        $this->emitir(['tipo' => 'VENDA', 'linhas' => [['produto_id' => $this->ids['p'], 'quantidade' => 1]]])->assertStatus(422)->assertJsonPath('codigo', 'CLIENTE_EM_FALTA');

        $gr = $this->emitir(['tipo' => 'VENDA', 'cliente_id' => $this->ids['cliente'], 'armazem_id' => $this->ids['b'],
            'linhas' => [['produto_id' => $this->ids['p'], 'quantidade' => 1]]])->assertCreated()->json('dados');
        $this->assertSame('0.000', $this->stock($this->ids['b']));
        $this->assertSame('10.000', $this->stock($this->ids['a']));
        $v = $this->naEmpresa(fn () => Venda::query()->find($gr['id']));
        $this->assertSame('GR', $v->tipo_documento);
        $this->assertSame('VENDA', $v->saida_armazem);
        $this->assertSame('1000.00', (string) $v->total_liquido);   // preço da ficha, como a «GR LOG» do legado
    }

    #[Test]
    public function emitir_a_guia_de_remessa_exige_as_duas_permissoes(): void
    {
        $dados = ['tipo' => 'VENDA', 'cliente_id' => $this->ids['cliente'], 'linhas' => [['produto_id' => $this->ids['p'], 'quantidade' => 1]]];
        $soArmazem = $this->sessao(['armazem_guias_view', 'armazem_guias_emitir']);
        $soVendas = $this->sessao(['vendas_faturacao_view', 'vendas_fat_emitir']);
        $this->emitir($dados, $soArmazem)->assertForbidden();
        $this->emitir($dados, $soVendas)->assertForbidden();
        $this->getJson('/api/logistica/guias-saida/encomendas-clientes', $soVendas)->assertForbidden();
        $this->getJson('/api/logistica/guias-saida/encomendas-clientes', $soArmazem)->assertOk();
        $this->assertSame('10.000', $this->stock($this->ids['a']));
    }
}
