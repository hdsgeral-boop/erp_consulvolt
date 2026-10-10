<?php

namespace Tests\Feature;

use App\Models\PedidoCompra;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\Terceiro;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** GET /api/vendas/documentos/{id}/fluxo — «Fluxo Completo do Processo» (showDocumentWorkflow, js/ui_sales.js:5758). */
final class VendasFluxoProcessoTest extends TestCase
{
    private int $empresa;

    private Terceiro $cliente;

    private Produto $produto;

    private string $hoje;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hoje = now()->toDateString();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000400'])->id;
        app(ContextoEmpresa::class)->executarComo($this->empresa, function () {
            foreach ([['311', 'Clientes'], ['3452', 'IVA liquidado'], ['451', 'Depósitos à ordem'], ['611', 'Vendas']] as [$c, $d]) {
                PlanoConta::create(['codigo' => $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            $this->cliente = Terceiro::create(['nome' => 'Cliente Fluxo', 'nif' => '5000000401', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '311']);
            $this->produto = Produto::create(['codigo' => 'P1', 'nome' => 'Produto 1', 'preco_unitario' => 1000, 'taxa_imposto' => 14,
                'codigo_conta' => '611', 'conta_iva_liquidado' => '3452', 'movimenta_stock' => false]);
        });
    }

    private function sessao(array $permissoes, ?int $empresa = null): array
    {
        $empresa ??= $this->empresa;
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($empresa);

        return $this->entrar($u) + ['X-Empresa-Id' => $empresa];
    }

    private function base(): array
    {
        return ['vendas_faturacao_view', 'vendas_fat_emitir', 'vendas_fat_contabilizar', 'vendas_fat_descontab', 'vendas_recibos', 'vendas_fat_unpost'];
    }

    private function emitir(array $dados, array $s): TestResponse
    {
        return $this->postJson('/api/vendas/documentos', $dados + [
            'tipo_documento' => 'FT', 'cliente_id' => $this->cliente->id, 'data_emissao' => $this->hoje,
            'linhas' => [['produto_id' => $this->produto->id, 'quantidade' => 1]],
        ], $s);
    }

    #[Test]
    public function cadeia_orcamento_factura_recibo_com_lancamentos_e_aprovisionamento(): void
    {
        $s = $this->sessao($this->base());
        $or = $this->emitir(['tipo_documento' => 'OR'], $s)->assertCreated()->json('dados.id');
        $ft = $this->postJson("/api/vendas/documentos/{$or}/converter", ['tipo_destino' => 'FT'], $s)->assertCreated()->json('dados');
        $lanFt = $this->postJson("/api/vendas/documentos/{$ft['id']}/contabilizar", [], $s)->assertOk()->json('dados.numero_lan_contabilizacao');
        $recibo = $this->postJson('/api/vendas/recibos', ['cliente_id' => $this->cliente->id, 'data' => $this->hoje, 'codigo_conta' => '451',
            'alocacoes' => [['venda_id' => $ft['id'], 'montante' => 500]]], $s)->assertCreated()->json('dados');
        $pedido = app(ContextoEmpresa::class)->executarComo($this->empresa, fn () => PedidoCompra::create([
            'nome_requerente' => 'Compras', 'data' => $this->hoje, 'estado' => 'PENDENTE', 'venda_origem_id' => $or, 'numero_pedido' => 'PC 2026/1',
        ])->id);

        // aberto a partir do orçamento: desce até à factura
        $r = $this->getJson("/api/vendas/documentos/{$or}/fluxo", $s)->assertOk()
            ->assertJsonCount(2, 'dados.vendas')
            ->assertJsonPath('dados.vendas.0.id', $or)->assertJsonPath('dados.vendas.0.tipo_documento', 'OR')->assertJsonPath('dados.vendas.0.actual', true)
            ->assertJsonPath('dados.vendas.1.id', $ft['id'])->assertJsonPath('dados.vendas.1.actual', false)
            ->assertJsonPath('dados.vendas.1.total_bruto', '1140.00')
            ->assertJsonCount(1, 'dados.recibos')
            ->assertJsonPath('dados.recibos.0.numero', $recibo['numero_recibo'])->assertJsonPath('dados.recibos.0.valor', '500.00')
            ->assertJsonPath('dados.recibos.0.numero_documento', $ft['numero_documento'])
            ->assertJsonCount(2, 'dados.lancamentos')
            ->assertJsonPath('dados.lancamentos.0.referencia', "FT {$ft['numero_documento']}")->assertJsonPath('dados.lancamentos.0.numero_lan', $lanFt)
            ->assertJsonPath('dados.lancamentos.1.referencia', $recibo['numero_recibo'])
            ->assertJsonPath('dados.aprovisionamento.0.tipo', 'Pedido de Compra')->assertJsonPath('dados.aprovisionamento.0.id', $pedido)
            ->assertJsonPath('dados.aprovisionamento.0.documento', 'PC 2026/1')
            ->json('dados');
        $linhasFt = collect($r['lancamentos'][0]['linhas']);
        $this->assertSame('1140.00', $linhasFt->firstWhere('conta', '311')['debito']);
        $this->assertNull($linhasFt->firstWhere('conta', '311')['credito']);
        $this->assertSame('1000.00', $linhasFt->firstWhere('conta', '611')['credito']);
        $this->assertSame(['500.00', null], [collect($r['lancamentos'][1]['linhas'])->firstWhere('conta', '451')['debito'], collect($r['lancamentos'][1]['linhas'])->firstWhere('conta', '451')['credito']]);

        // aberto a partir da factura: sobe ao orçamento e o aprovisionamento da raiz aparece (como no legado)
        $this->getJson("/api/vendas/documentos/{$ft['id']}/fluxo", $s)->assertOk()
            ->assertJsonPath('dados.vendas.0.id', $or)->assertJsonPath('dados.vendas.0.actual', false)
            ->assertJsonPath('dados.vendas.1.actual', true)->assertJsonCount(1, 'dados.aprovisionamento');

        // recibo descontabilizado: o lançamento estornado deixa de aparecer
        $this->postJson("/api/vendas/recibos/{$recibo['id']}/descontabilizar", ['motivo' => 'Correcção de teste'], $s)->assertOk();
        $this->getJson("/api/vendas/documentos/{$ft['id']}/fluxo", $s)->assertOk()->assertJsonCount(1, 'dados.lancamentos')
            ->assertJsonPath('dados.lancamentos.0.referencia', "FT {$ft['numero_documento']}");
    }

    #[Test]
    public function documento_sem_ligacoes_devolve_so_o_proprio(): void
    {
        $s = $this->sessao($this->base());
        $ft = $this->emitir([], $s)->assertCreated()->json('dados.id');

        $this->getJson("/api/vendas/documentos/{$ft}/fluxo", $s)->assertOk()
            ->assertJsonCount(1, 'dados.vendas')->assertJsonPath('dados.vendas.0.actual', true)->assertJsonPath('dados.vendas.0.contabilizado', false)
            ->assertJsonCount(0, 'dados.recibos')->assertJsonCount(0, 'dados.lancamentos')->assertJsonCount(0, 'dados.aprovisionamento');
    }

    #[Test]
    public function isolamento_por_empresa_e_permissao(): void
    {
        $s = $this->sessao($this->base());
        $ft = $this->emitir([], $s)->assertCreated()->json('dados.id');

        $outra = $this->criarEmpresa(['nif' => '5417000401'])->id;
        $this->getJson("/api/vendas/documentos/{$ft}/fluxo", $this->sessao($this->base(), $outra))->assertNotFound();
        $this->getJson("/api/vendas/documentos/{$ft}/fluxo", $this->sessao(['compras_view']))->assertForbidden();
        $this->getJson('/api/vendas/documentos/abc/fluxo', $s)->assertNotFound();
    }
}
