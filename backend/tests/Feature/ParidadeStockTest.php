<?php

namespace Tests\Feature;

use App\Models\Armazem;
use App\Models\Empresa;
use App\Models\EncomendaCompra;
use App\Models\GuiaSaida;
use App\Models\MovimentoInventario;
use App\Models\Produto;
use App\Models\RececaoCompra;
use App\Models\StockArmazem;
use App\Models\Terceiro;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Leituras de paridade da Logística (RELATORIO.md 2.2, 2.4, 2.5): /api/logistica/paridade/*. Dados fictícios. */
final class ParidadeStockTest extends TestCase
{
    private Empresa $empresa;

    private Empresa $outra;

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa();
        $this->outra = $this->criarEmpresa();
        $ctx = app(ContextoEmpresa::class);
        $this->ids['armazem_outra'] = $ctx->executarComo($this->outra->id, function () {
            $a = Armazem::create(['nome' => 'Armazém Alheio', 'predefinido' => true]);
            $t = Terceiro::create(['nome' => 'Fornecedor Alheio', 'tipo' => 'FORNECEDOR']);
            $e = EncomendaCompra::create(['numero_encomenda' => 'ORD-9999', 'fornecedor_id' => $t->id, 'data' => '2026-01-10', 'estado' => 'EM_PROCESSAMENTO']);
            RececaoCompra::create(['encomenda_compra_id' => $e->id, 'numero_entrega' => 'GF-ALHEIA', 'data' => '2026-01-11', 'estado' => 'RECEBIDO']);

            return $a->id;
        });
        $ctx->executarComo($this->empresa->id, function () {
            $a = Armazem::create(['nome' => 'Central Teste', 'predefinido' => true]);
            $b = Armazem::create(['nome' => 'Loja Teste']);
            $f = Terceiro::create(['nome' => 'Fornecedor Exemplo', 'tipo' => 'FORNECEDOR']);
            $c = Terceiro::create(['nome' => 'Cliente Exemplo', 'tipo' => 'CLIENTE']);
            $p = Produto::create(['codigo' => 'PX1', 'nome' => 'Artigo X', 'preco_unitario' => 100, 'movimenta_stock' => true, 'custo_medio' => 10]);
            $e = EncomendaCompra::create(['numero_encomenda' => 'ORD-4205', 'fornecedor_id' => $f->id, 'data' => '2026-02-01', 'estado' => 'PARCIAL']);
            $r = fn (string $guia, array $x) => RececaoCompra::create(['encomenda_compra_id' => $e->id, 'numero_entrega' => $guia, 'data' => '2026-02-02'] + $x)->id;
            $this->ids += [
                'a' => $a->id, 'b' => $b->id, 'f' => $f->id, 'c' => $c->id, 'p' => $p->id,
                'pendente' => $r('GF-1', ['estado' => 'RECEBIDO']),
                'migrada' => $r('GF-2', ['estado' => 'RECEBIDO', 'validado' => true, 'armazem_id' => $a->id]),
                'validada' => $r('GF-3', ['estado' => 'VALIDADO', 'validado' => true, 'armazem_id' => $b->id]),
                'anulada' => $r('GF-4', ['estado' => 'ANULADO']),
            ];
            $g = GuiaSaida::create(['numero_documento' => 'GS 2026/1', 'data' => '2026-02-03', 'tipo' => 'VENDA', 'terceiro_id' => $c->id, 'armazem_id' => $a->id, 'estado' => 'CONCLUIDO']);
            $m = fn (array $x) => MovimentoInventario::create(['produto_id' => $p->id, 'quantidade' => 1, 'data' => '2026-02-03 10:00:00', 'preco_unitario' => 10, 'valor' => 10] + $x)->id;
            $this->ids += [
                'm_terceiro' => $m(['armazem_id' => $a->id, 'tipo' => 'ENTRADA', 'sentido' => 'E', 'terceiro_id' => $f->id, 'referencia' => 'Recepção teste']),
                'm_guia' => $m(['armazem_id' => $a->id, 'tipo' => 'SAIDA', 'sentido' => 'S', 'documento_tipo' => 'GUIA_SAIDA', 'documento_id' => $g->id, 'referencia' => 'Guia teste']),
                // armazém de outra empresa (dado migrado inconsistente): o nome não pode passar
                'm_alheio' => $m(['armazem_id' => $this->ids['armazem_outra'], 'tipo' => 'AJUSTE', 'sentido' => 'S', 'documento_tipo' => 'MIGRACAO', 'referencia' => 'Saldo inicial de migração']),
            ];
            StockArmazem::create(['produto_id' => $p->id, 'armazem_id' => $a->id, 'quantidade_stock' => 5]);
            StockArmazem::create(['produto_id' => $p->id, 'armazem_id' => $b->id, 'quantidade_stock' => 3]);
        });
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    #[Test]
    public function rececoes_com_encomenda_fornecedor_armazem_e_situacao(): void
    {
        $s = $this->sessao(['armazem_rececoes_view']);
        $todas = collect($this->getJson('/api/logistica/paridade/rececoes', $s)->assertOk()->json('dados'))->keyBy('id');
        $this->assertCount(4, $todas);   // a recepção da outra empresa não aparece
        $mig = $todas[$this->ids['migrada']];
        $this->assertSame(['ORD-4205', 'Fornecedor Exemplo', 'Central Teste', 'VALIDADO'], [$mig['numero_encomenda'], $mig['fornecedor'], $mig['armazem'], $mig['situacao']]);
        $this->assertSame(['PENDENTE', 'VALIDADO', 'ANULADO'], [$todas[$this->ids['pendente']]['situacao'], $todas[$this->ids['validada']]['situacao'], $todas[$this->ids['anulada']]['situacao']]);

        // «Por validar»: só as pendentes (a migrada RECEBIDO + validado já não aparece)
        $pend = $this->getJson('/api/logistica/paridade/rececoes?situacao=PENDENTE', $s)->assertOk()->json('dados');
        $this->assertSame([$this->ids['pendente']], array_column($pend, 'id'));
        $this->assertCount(2, $this->getJson('/api/logistica/paridade/rececoes?situacao=VALIDADO', $s)->json('dados'));
        $this->assertCount(4, $this->getJson('/api/logistica/paridade/rececoes?pesquisa=ORD-4205', $s)->json('dados'));
        $this->assertCount(0, $this->getJson('/api/logistica/paridade/rececoes?pesquisa=Alheio', $s)->json('dados'));
        $this->getJson('/api/logistica/paridade/rececoes?situacao=RECEBIDO', $s)->assertStatus(422);

        $this->getJson('/api/logistica/paridade/rececoes', $this->sessao(['armazem_stock_view']))->assertForbidden();
    }

    #[Test]
    public function movimentos_com_terceiro_e_nome_do_armazem_da_empresa(): void
    {
        $s = $this->sessao(['armazem_movimentos_view']);
        $m = collect($this->getJson('/api/logistica/paridade/movimentos', $s)->assertOk()->json('dados'))->keyBy('id');
        $this->assertCount(3, $m);
        $this->assertSame(['Fornecedor Exemplo', 'Central Teste'], [$m[$this->ids['m_terceiro']]['terceiro'], $m[$this->ids['m_terceiro']]['armazem']]);
        $this->assertSame('Cliente Exemplo', $m[$this->ids['m_guia']]['terceiro']);   // pela guia de saída
        $this->assertNull($m[$this->ids['m_alheio']]['armazem']);                      // armazém de outra empresa: sem nome
        $this->assertSame('MIGRACAO', $m[$this->ids['m_alheio']]['documento_tipo']);
        $this->assertCount(1, $this->getJson('/api/logistica/paridade/movimentos?tipo=SAIDA', $s)->json('dados'));
        $this->assertCount(1, $this->getJson('/api/logistica/paridade/movimentos?referencia=Guia', $s)->json('dados'));

        $this->getJson('/api/logistica/paridade/movimentos', $this->sessao(['armazem_rececoes_view']))->assertForbidden();
    }

    #[Test]
    public function valor_em_stock_por_armazem(): void
    {
        $s = $this->sessao(['armazem_armazens_view']);
        $d = $this->getJson('/api/logistica/paridade/armazens/valor', $s)->assertOk()->json('dados');
        $por = collect($d['por_armazem'])->keyBy('armazem_id');
        $this->assertSame(['50.00', '30.00', '80.00'], [$por[$this->ids['a']]['valor'], $por[$this->ids['b']]['valor'], $d['total']]);

        $this->getJson('/api/logistica/paridade/armazens/valor', $this->sessao(['armazem_rececoes_view']))->assertForbidden();
    }
}
