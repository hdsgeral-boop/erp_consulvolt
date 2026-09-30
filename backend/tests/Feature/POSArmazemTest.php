<?php

namespace Tests\Feature;

use App\Models\Armazem;
use App\Models\Empresa;
use App\Models\GuiaSaida;
use App\Models\ItemVenda;
use App\Models\LancamentoContabil;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\StockArmazem;
use App\Models\Terceiro;
use App\Services\Logistica\ServicoStock;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * POS parte 3b — POS de armazém (ADR-050): venda ao balcão = guia de saída (sem factura) com stock ao custo médio e CMV
 * no diário GS; picking de encomendas = conversão NE → GR com stock de todas as linhas no armazém escolhido.
 * P1: custo 600, 10 no armazém Central e 1 na Loja; S1: serviço (sem stock).
 */
final class POSArmazemTest extends TestCase
{
    private Empresa $empresa;

    private array $ids = [];

    private array $s;

    private string $ano;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ano = now()->format('Y');
        $this->empresa = $this->criarEmpresa(['nif' => '5417000060']);
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['311' => 'Clientes', '3452' => 'IVA liquidado', '611' => 'Vendas', '621' => 'Serviços', '2611' => 'Mercadorias', '7111' => 'CMV'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            $this->ids['cliente'] = Terceiro::create(['nome' => 'Cliente', 'nif' => '5000000001', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '311'])->id;
            $this->ids['fornecedor'] = Terceiro::create(['nome' => 'Fornecedor', 'nif' => '5000000002', 'tipo' => 'FORNECEDOR', 'codigo_conta' => '321'])->id;
            $this->ids['p'] = Produto::create(['codigo' => 'P1', 'nome' => 'Produto', 'preco_unitario' => 1000, 'taxa_imposto' => 14, 'codigo_conta' => '611',
                'conta_iva_liquidado' => '3452', 'movimenta_stock' => true, 'conta_inventario' => '2611', 'conta_custo' => '7111'])->id;
            $this->ids['serv'] = Produto::create(['codigo' => 'S1', 'nome' => 'Montagem', 'preco_unitario' => 500, 'taxa_imposto' => 14, 'codigo_conta' => '621',
                'conta_iva_liquidado' => '3452', 'movimenta_stock' => false, 'e_servico' => true])->id;
            $this->ids['a'] = Armazem::create(['nome' => 'Central', 'predefinido' => true])->id;
            $this->ids['b'] = Armazem::create(['nome' => 'Loja'])->id;
            app(ServicoStock::class)->entrada($this->ids['p'], $this->ids['a'], '10', '600', now()->toDateString(), 'Stock inicial');
            app(ServicoStock::class)->entrada($this->ids['p'], $this->ids['b'], '1', '600', now()->toDateString(), 'Stock inicial');
            // guia de venda ao balcão migrada do legado: continua a numeração e fica só para consulta
            $this->ids['legado'] = GuiaSaida::create(['numero_documento' => "GE POS {$this->ano}/8", 'data' => now()->toDateString(), 'tipo' => 'VENDA', 'tipo_original' => 'VENDA',
                'armazem_id' => $this->ids['a'], 'estado' => 'CONCLUIDO', 'contabilizado' => true])->id;
        });
        $this->s = $this->sessao(['pos_armazem_view', 'pos_armazem_vender', 'pos_armazem_picking', 'pos_armazem_expedir', 'vendas_faturacao_view', 'vendas_fat_emitir',
            'armazem_guias_view', 'armazem_guias_anular', 'armazem_guias_contab']);
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function stock(string $armazem = 'a'): string
    {
        return (string) app(ContextoEmpresa::class)->executarComo($this->empresa->id,
            fn () => StockArmazem::query()->where('armazem_id', $this->ids[$armazem])->where('produto_id', $this->ids['p'])->value('quantidade_stock'));
    }

    #[Test]
    public function venda_ao_balcao_emite_guia_com_stock_e_cmv_e_anula_por_estorno(): void
    {
        $url = '/api/pos/armazem/vendas';
        $linhas = fn ($q, $p = 'p') => ['armazem_id' => $this->ids['a'], 'linhas' => [['produto_id' => $this->ids[$p], 'quantidade' => $q]]];
        $this->postJson($url, $linhas(3), $this->sessao(['pos_armazem_view']))->assertForbidden();
        $this->postJson($url, $linhas(50), $this->s)->assertStatus(422)->assertJsonPath('codigo', 'STOCK_INSUFICIENTE');   // o legado limitava no ecrã e cortava a zero
        $this->postJson($url, $linhas(1, 'serv'), $this->s)->assertStatus(422)->assertJsonPath('codigo', 'PRODUTO_SEM_STOCK');
        $this->postJson($url, $linhas(1) + ['terceiro_id' => $this->ids['fornecedor']], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'CLIENTE_INVALIDO');

        $g = $this->postJson($url, $linhas(3) + ['terceiro_id' => $this->ids['cliente']], $this->s)->assertCreated()->json('dados');
        $this->assertSame(["GE POS {$this->ano}/0009", 'VENDA_BALCAO', null, 'CONCLUIDO', true, 'Cliente', '1800.00'],
            [$g['numero_documento'], $g['tipo'], $g['tipo_original'], $g['estado'], $g['contabilizado'], $g['area_rececao'], $g['linhas'][0]['valor_kz']]);
        $this->assertSame('7.000', $this->stock());
        $lan = app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => LancamentoContabil::query()->where('numero_lan', $g['numero_lan_contabilizacao'])->get());
        $this->assertSame(['C 2611 1800.00', 'D 7111 1800.00'], $lan->map(fn ($l) => "{$l->tipo_dc} {$l->codigo_conta} {$l->valor}")->sort()->values()->all());
        $this->assertSame([$this->ids['cliente']], $lan->pluck('terceiro_id')->unique()->values()->all());
        $this->assertSame("GE POS {$this->ano}/0010", $this->postJson($url, $linhas(1), $this->s)->assertCreated()->assertJsonPath('dados.area_rececao', 'Cliente de balcão')
            ->json('dados.numero_documento'));
        $this->assertCount(2, $this->getJson('/api/pos/armazem/vendas', $this->s)->assertOk()->json('dados'));   // a migrada não é venda ao balcão

        // anular: contabilizada exige estorno primeiro; depois repõe o stock
        $this->postJson("/api/logistica/guias-saida/{$g['id']}/anular", ['motivo' => 'Devolvido'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'DOCUMENTO_CONTABILIZADO');
        $this->postJson("/api/logistica/guias-saida/{$g['id']}/descontabilizar", ['motivo' => 'Devolvido'], $this->s)->assertOk();
        $this->postJson("/api/logistica/guias-saida/{$g['id']}/anular", ['motivo' => 'Devolvido'], $this->s)->assertOk()->assertJsonPath('dados.estado', 'ANULADA');
        $this->assertSame('9.000', $this->stock());
        $this->postJson("/api/logistica/guias-saida/{$this->ids['legado']}/anular", ['motivo' => 'Engano'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'NAO_ANULAVEL');

        // sem contas de CMV (nem no produto nem na logística): a guia sai e fica por contabilizar, com aviso
        $p2 = app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            $id = Produto::create(['codigo' => 'P2', 'nome' => 'Sem contas', 'preco_unitario' => 100, 'taxa_imposto' => 14, 'movimenta_stock' => true])->id;
            app(ServicoStock::class)->entrada($id, $this->ids['a'], '2', '50', now()->toDateString(), 'Stock inicial');

            return $id;
        });
        $g2 = $this->postJson($url, ['armazem_id' => $this->ids['a'], 'linhas' => [['produto_id' => $p2, 'quantidade' => 1]]], $this->s)->assertCreated()->json('dados');
        $this->assertSame([false, 'CONCLUIDO'], [$g2['contabilizado'], $g2['estado']]);
        $this->assertStringContainsString('não tem conta de custo', $g2['aviso_contabilizacao']);
    }

    #[Test]
    public function picking_expede_a_encomenda_por_guia_de_remessa_com_stock_do_armazem(): void
    {
        $ne = $this->postJson('/api/vendas/documentos', ['tipo_documento' => 'NE', 'cliente_id' => $this->ids['cliente'], 'data_emissao' => now()->toDateString(),
            'linhas' => [['produto_id' => $this->ids['p'], 'quantidade' => 5], ['produto_id' => $this->ids['serv'], 'quantidade' => 1]]], $this->s)->assertCreated()->json('dados');
        $fila = $this->getJson('/api/pos/armazem/picking', $this->s)->assertOk()->json('dados');
        $this->assertSame([[$ne['id'], 'POR_EXPEDIR', 2]], array_map(fn ($x) => [$x['id'], $x['estado_picking'], $x['linhas_por_expedir']], $fila));

        // na Loja só há 1: não expede (o serviço não bloqueia)
        $l = $this->getJson("/api/pos/armazem/picking/{$ne['id']}?armazem_id={$this->ids['b']}", $this->s)->assertOk()->json('dados');
        $this->assertSame([false, [false, true], ['1.000', null]], [$l['pode_expedir'], array_column($l['linhas'], 'disponivel'), array_column($l['linhas'], 'stock_armazem')]);
        $this->postJson("/api/pos/armazem/picking/{$ne['id']}/expedir", ['armazem_id' => $this->ids['b']], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'STOCK_INSUFICIENTE')
            ->assertJsonPath('erros.linhas.0.por_expedir', '5.000');
        $this->postJson("/api/pos/armazem/picking/{$ne['id']}/expedir", ['armazem_id' => $this->ids['a']], $this->sessao(['pos_armazem_picking']))->assertForbidden();

        // no Central: GR ligada à encomenda, stock ao custo médio
        $gr = $this->postJson("/api/pos/armazem/picking/{$ne['id']}/expedir", ['armazem_id' => $this->ids['a']], $this->s)->assertCreated()->json('dados');
        $this->assertSame(['GR', $this->ids['a'], 2], [$gr['tipo_documento'], $gr['armazem_id'], count($gr['itens_venda'])]);
        $this->assertSame(['5.000', '1.000'], [$this->stock(), $this->stock('b')]);
        $entregue = app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => ItemVenda::query()->where('venda_id', $ne['id'])->orderBy('id')->pluck('quantidade_entregue')->all());
        $this->assertSame(['5.000', '1.000'], array_map('strval', $entregue));
        $this->assertSame([], $this->getJson('/api/pos/armazem/picking', $this->s)->json('dados'));
        $this->getJson("/api/vendas/documentos/{$ne['id']}", $this->s)->assertJsonPath('dados.estado', 'PENDENTE');   // concluída só quando facturada (ADR-043)
        $this->postJson("/api/pos/armazem/picking/{$ne['id']}/expedir", ['armazem_id' => $this->ids['a']], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'JA_CONVERTIDO');
    }
}
