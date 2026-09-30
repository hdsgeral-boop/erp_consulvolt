<?php

namespace Tests\Feature;

use App\Models\ContratoFornecedor;
use App\Models\Empresa;
use App\Models\EncomendaCompra;
use App\Models\FaturaCompra;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\Terceiro;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Compras (parte 2): contratos de fornecedores com marcos e consumo; encomendas de clientes → pedidos de compra. */
final class ComprasContratosTest extends TestCase
{
    private Empresa $empresa;

    private Terceiro $fornecedor;

    private Terceiro $outroFornecedor;

    private array $s;

    private string $hoje;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hoje = now()->toDateString();
        $this->empresa = $this->criarEmpresa();
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['3111' => 'Clientes', '3211' => 'Fornecedores', '611' => 'Vendas', '3452' => 'IVA'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            $this->fornecedor = Terceiro::create(['nome' => 'Fornecedor A', 'nif' => '5000000002', 'tipo' => Terceiro::FORNECEDOR, 'codigo_conta' => '3211']);
            $this->outroFornecedor = Terceiro::create(['nome' => 'Fornecedor B', 'nif' => '5000000003', 'tipo' => Terceiro::FORNECEDOR, 'codigo_conta' => '3211']);
            Terceiro::create(['nome' => 'Cliente', 'nif' => '5000000001', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '3111']);
            Produto::create(['codigo' => 'P1', 'nome' => 'Cimento', 'preco_unitario' => 1000, 'taxa_imposto' => 14, 'codigo_conta' => '611', 'conta_iva_liquidado' => '3452',
                'movimenta_stock' => true, 'custo_medio' => 800, 'quantidade_stock' => 3]);
            Produto::create(['codigo' => 'P2', 'nome' => 'Areia', 'preco_unitario' => 500, 'taxa_imposto' => 14, 'codigo_conta' => '611', 'conta_iva_liquidado' => '3452']);
        });
        $this->s = $this->sessao(['compras_contratos_view', 'compras_contratos_gerir', 'compras_contratos_cancelar', 'compras_encomendas_clientes_view',
            'compras_gerar_pedidos', 'compras_pedidos_view', 'compras_ped_eliminar', 'vendas_faturacao_view', 'vendas_fat_emitir']);
    }

    private function sessao(array $permissoes): array
    {
        $perfil = $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true));
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function encomenda(Terceiro $f, string $montante, string $numero): int
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => EncomendaCompra::create(['fornecedor_id' => $f->id, 'numero_encomenda' => $numero,
            'data' => $this->hoje, 'estado' => 'EM_PROCESSAMENTO', 'montante_total' => $montante, 'total_com_imposto' => $montante])->id);
    }

    private function contrato(array $d = []): TestResponse
    {
        return $this->postJson('/api/compras/contratos', $d + ['fornecedor_id' => $this->fornecedor->id, 'referencia' => 'CT-2026/01', 'data_inicio' => now()->subMonth()->toDateString(),
            'data_fim' => now()->addYear()->toDateString(), 'valor_total' => 100000], $this->s);
    }

    #[Test]
    public function contrato_encomendas_do_mesmo_fornecedor_e_consumo(): void
    {
        $c = $this->contrato()->assertCreated()->assertJsonPath('dados.estado', 'ATIVO')->json('dados.id');
        $this->contrato(['referencia' => 'ct-2026/01'])->assertStatus(422)->assertJsonPath('codigo', 'CONTRATO_DUPLICADO');
        $this->contrato(['referencia' => 'CT-X', 'data_inicio' => $this->hoje, 'data_fim' => now()->subDay()->toDateString()])->assertStatus(422)->assertJsonPath('codigo', 'DATAS_INVALIDAS');

        $e1 = $this->encomenda($this->fornecedor, '60000', 'EC A2026/1');
        $e2 = $this->encomenda($this->fornecedor, '50000', 'EC A2026/2');
        $eOutro = $this->encomenda($this->outroFornecedor, '10', 'EC A2026/3');
        $this->postJson("/api/compras/contratos/{$c}/encomendas", ['encomendas' => [$eOutro]], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'ENCOMENDA_OUTRO_FORNECEDOR');
        $this->postJson("/api/compras/contratos/{$c}/encomendas", ['encomendas' => [$e1, $e2]], $this->s)->assertOk()
            ->assertJsonPath('dados.consumo.encomendado', '110000.00')->assertJsonPath('dados.consumo.excedido', true)->assertJsonCount(2, 'dados.encomendas');

        // cada encomenda num só contrato: mover é explícito
        $c2 = $this->contrato(['referencia' => 'CT-2026/02'])->json('dados.id');
        $this->postJson("/api/compras/contratos/{$c2}/encomendas", ['encomendas' => [$e2]], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'ENCOMENDA_NOUTRO_CONTRATO');
        $this->postJson("/api/compras/contratos/{$c2}/encomendas", ['encomendas' => [$e2], 'mover' => true], $this->s)->assertOk()->assertJsonPath('dados.consumo.encomendado', '50000.00');
        $this->getJson("/api/compras/contratos/{$c}", $this->s)->assertJsonPath('dados.consumo.encomendado', '60000.00')->assertJsonPath('dados.consumo.excedido', false);
        $this->deleteJson("/api/compras/contratos/{$c}/encomendas/{$e1}", [], $this->s)->assertOk()->assertJsonCount(0, 'dados.encomendas');
    }

    #[Test]
    public function marcos_limitados_ao_contrato_e_estado_pela_factura(): void
    {
        $c = $this->contrato()->json('dados.id');
        $marco = fn (array $d) => $this->postJson("/api/compras/contratos/{$c}/marcos", $d + ['titulo' => 'Adiantamento', 'data_prevista' => $this->hoje], $this->s);
        $marco(['montante' => 60000])->assertOk();
        $marco(['montante' => 50000])->assertStatus(422)->assertJsonPath('codigo', 'MARCOS_EXCEDEM_CONTRATO')->assertJsonPath('erros.disponivel', '40000.00');
        $marco(['montante' => 10, 'data_prevista' => now()->addYears(2)->toDateString()])->assertStatus(422)->assertJsonPath('codigo', 'DATA_FORA_DO_CONTRATO');
        $m = $marco(['montante' => 40000, 'titulo' => 'Entrega final'])->assertOk()->json('dados.marcos.1.id');
        $this->putJson("/api/compras/contratos/{$c}", ['fornecedor_id' => $this->fornecedor->id, 'referencia' => 'CT-2026/01', 'valor_total' => 90000], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'MARCOS_EXCEDEM_CONTRATO');

        $f = app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => FaturaCompra::create(['fornecedor_id' => $this->fornecedor->id, 'numero_fatura' => 'F-1',
            'data' => $this->hoje, 'montante_total' => 40000, 'estado' => 'PENDENTE', 'contabilizado' => true]));
        $this->postJson("/api/compras/contratos/{$c}/marcos/{$m}/fatura", ['fatura_compra_id' => $f->id], $this->s)->assertOk()
            ->assertJsonPath('dados.marcos.1.estado_efetivo', 'FATURADO')->assertJsonPath('dados.consumo.faturado', '40000.00');
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => $f->update(['estado' => 'PAGO']));
        $this->getJson("/api/compras/contratos/{$c}", $this->s)->assertJsonPath('dados.marcos.1.estado_efetivo', 'PAGO')->assertJsonPath('dados.consumo.pago', '40000.00');
        $this->deleteJson("/api/compras/contratos/{$c}/marcos/{$m}", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'MARCO_FATURADO');
    }

    #[Test]
    public function expiracao_automatica_e_cancelamento(): void
    {
        $c = $this->contrato(['data_inicio' => now()->subYear()->toDateString(), 'data_fim' => now()->addDay()->toDateString()])->json('dados.id');
        // a data de fim passou (sem mexer no relógio: a sessão expiraria por inactividade)
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => ContratoFornecedor::query()->whereKey($c)->update(['data_fim' => now()->subDay()->toDateString()]));
        $this->getJson('/api/compras/contratos', $this->s)->assertJsonPath('dados.0.estado', 'EXPIRADO');
        $this->postJson("/api/compras/contratos/{$c}/encomendas", ['encomendas' => [$this->encomenda($this->fornecedor, '1', 'EC 9')]], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'CONTRATO_NAO_ATIVO');
        $this->postJson("/api/compras/contratos/{$c}/cancelar", ['motivo' => 'Fornecedor falido'], $this->s)->assertOk()->assertJsonPath('dados.estado', 'CANCELADO');
        $this->putJson("/api/compras/contratos/{$c}", ['fornecedor_id' => $this->fornecedor->id, 'referencia' => 'CT-2026/01', 'valor_total' => 1], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'CONTRATO_CANCELADO');
        $this->postJson("/api/compras/contratos/{$c}/cancelar", ['motivo' => 'Outra vez'], $this->sessao(['compras_contratos_view']))->assertForbidden();
    }

    #[Test]
    public function encomendas_de_clientes_geram_pedido_consolidado_uma_so_vez(): void
    {
        [$p1, $p2, $cliente] = app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => [Produto::query()->where('codigo', 'P1')->value('id'),
            Produto::query()->where('codigo', 'P2')->value('id'), Terceiro::query()->where('nif', '5000000001')->value('id')]);
        $ne = fn (array $linhas) => $this->postJson('/api/vendas/documentos', ['tipo_documento' => 'NE', 'cliente_id' => $cliente, 'data_emissao' => $this->hoje,
            'linhas' => $linhas], $this->s)->assertCreated()->json('dados.id');
        $ne([['produto_id' => $p1, 'quantidade' => 5], ['produto_id' => $p2, 'quantidade' => 2]]);
        $ne([['produto_id' => $p1, 'quantidade' => 7]]);

        $lista = $this->getJson('/api/compras/encomendas-clientes', $this->s)->assertOk()->assertJsonCount(2, 'dados')->json('dados');
        $linhas = collect($lista)->flatMap(fn ($v) => $v['linhas']);
        $this->assertTrue($linhas->every(fn ($l) => $l['por_comprar']));
        $this->assertSame('3.000', $linhas->firstWhere('produto_id', $p1)['stock_disponivel']);

        $ids = $linhas->pluck('id')->all();
        $pedido = $this->postJson('/api/compras/encomendas-clientes/pedido', ['itens' => $ids], $this->s)->assertCreated()->json('dados.id');
        $detalhe = $this->getJson("/api/compras/pedidos/{$pedido}", $this->s)->assertOk()->assertJsonPath('dados.estado', 'PENDENTE')->json('dados');
        $porProduto = collect($detalhe['linhas'])->keyBy('produto_id');
        $this->assertSame('12.000', $porProduto[$p1]['quantidade']);   // 5 + 7 consolidados
        $this->assertSame('800.00', $porProduto[$p1]['preco_unitario']);   // custo médio, não o preço de venda
        $this->assertNotNull($detalhe['criado_por']);
        $this->postJson('/api/compras/encomendas-clientes/pedido', ['itens' => [$ids[0]]], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'LINHA_JA_EM_PEDIDO');

        // anular o pedido liberta as linhas
        $this->postJson("/api/compras/pedidos/{$pedido}/anular", ['motivo' => 'Cliente desistiu'], $this->s)->assertOk();
        $this->assertTrue(collect($this->getJson('/api/compras/encomendas-clientes', $this->s)->json('dados'))->flatMap(fn ($v) => $v['linhas'])->every(fn ($l) => $l['por_comprar']));
        $this->postJson('/api/compras/encomendas-clientes/pedido', ['itens' => [$ids[0]]], $this->s)->assertCreated();
    }
}
