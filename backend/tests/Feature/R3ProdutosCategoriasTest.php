<?php

namespace Tests\Feature;

use App\Models\Armazem;
use App\Models\CategoriaProduto;
use App\Models\Empresa;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\RegistoConversaoContasProduto;
use App\Services\Logistica\ContasProduto;
use App\Services\Logistica\ServicoConfigLogistica;
use App\Services\Logistica\ServicoNiveisStock;
use App\Services\Logistica\ServicoStock;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ronda 3 — produtos e categorias: contas contabilísticas na categoria (ContasProduto::de), categoria obrigatória, ficha
 * técnica, níveis de stock com regras de notificação e sugestões de reposição, e a conversão das contas dos produtos.
 */
final class R3ProdutosCategoriasTest extends TestCase
{
    private Empresa $empresa;

    private array $h;

    private int $armazem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa();
        $this->como(function () {
            foreach ([['61', 'Vendas', 'T'], ['611', 'Vendas de mercadorias', 'M'], ['621', 'Prestações de serviços', 'M'], ['711', 'CMV', 'M'], ['211', 'Compras', 'M'],
                ['261', 'Mercadorias', 'M'], ['3453', 'IVA liquidado', 'M'], ['3452', 'IVA dedutível', 'M'], ['785', 'Quebras', 'M'], ['685', 'Sobras', 'M'],
                ['111', 'Caixa', 'M']] as [$c, $d, $t]) {
                PlanoConta::create(['codigo' => $c, 'descricao' => $d, 'tipo' => $t]);
            }
            $this->armazem = Armazem::create(['nome' => 'Central', 'predefinido' => true])->id;
        });
        $this->h = $this->sessao(['vendas_produtos_view', 'vendas_produtos_gerir', 'vendas_categorias_contas', 'vendas_dados_del', 'armazem_stock_view',
            'armazem_ajuste', 'vendas_relatorios_view', 'compras_pedidos_view', 'compras_ped_criar']);
    }

    private function como(callable $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, $f);
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function categoria(array $dados = []): int
    {
        return $this->postJson('/api/logistica/categorias-produtos', $dados + ['nome' => 'Materiais', 'codigo_conta' => '611', 'conta_custo' => '711',
            'conta_compra' => '211', 'conta_inventario' => '261', 'conta_iva_liquidado' => '3453', 'conta_iva_dedutivel' => '3452'], $this->h)
            ->assertCreated()->json('dados.id');
    }

    private function ajuste(int $produto, string $sentido, float $q): void
    {
        $this->postJson('/api/logistica/ajustes', ['produto_id' => $produto, 'armazem_id' => $this->armazem, 'sentido' => $sentido, 'quantidade' => $q,
            'custo_unitario' => $sentido === 'E' ? 100 : null, 'data' => now()->toDateString(), 'motivo' => 'Teste de níveis'], $this->h)->assertCreated();
    }

    #[Test]
    public function categoria_define_as_contas_com_classes_e_permissao_propria(): void
    {
        $cat = $this->categoria();
        $this->getJson("/api/logistica/categorias-produtos/{$cat}", $this->h)->assertOk()
            ->assertJsonPath('dados.codigo_conta', '611')->assertJsonPath('dados.conta_custo', '711');
        $this->getJson('/api/logistica/categorias-produtos', $this->h)->assertOk()->assertJsonPath('dados.0.produtos', 0);

        // classe errada, totalizadora e inexistente
        $this->putJson("/api/logistica/categorias-produtos/{$cat}", ['codigo_conta' => '711'], $this->h)->assertStatus(422)->assertJsonPath('codigo', 'CONTA_CLASSE_INVALIDA');
        $this->putJson("/api/logistica/categorias-produtos/{$cat}", ['codigo_conta' => '61'], $this->h)->assertStatus(422)->assertJsonPath('codigo', 'CONTA_TOTALIZADORA');
        $this->putJson("/api/logistica/categorias-produtos/{$cat}", ['conta_quebra' => '999'], $this->h)->assertStatus(422)->assertJsonPath('codigo', 'CONTA_INEXISTENTE');
        $this->putJson("/api/logistica/categorias-produtos/{$cat}", ['conta_quebra' => '785', 'conta_sobra' => '685'], $this->h)->assertOk()
            ->assertJsonPath('dados.conta_quebra', '785');

        // sem vendas_categorias_contas: muda o nome, mas não as contas
        $gestor = $this->sessao(['vendas_produtos_view', 'vendas_produtos_gerir']);
        $this->putJson("/api/logistica/categorias-produtos/{$cat}", ['nome' => 'Materiais de obra', 'codigo_conta' => '611'], $gestor)->assertOk();
        $this->putJson("/api/logistica/categorias-produtos/{$cat}", ['codigo_conta' => '621'], $gestor)->assertForbidden();
        $this->postJson('/api/logistica/categorias-produtos', ['nome' => 'Outra', 'conta_custo' => '711'], $gestor)->assertForbidden();
        $this->postJson('/api/logistica/categorias-produtos', ['nome' => 'Outra'], $gestor)->assertCreated();
        $this->putJson("/api/logistica/categorias-produtos/{$cat}", ['nome' => 'X'], $this->sessao(['vendas_produtos_view']))->assertForbidden();
    }

    #[Test]
    public function produto_exige_categoria_herda_as_contas_e_nao_aceita_contas_proprias(): void
    {
        $cat = $this->categoria();
        $this->postJson('/api/logistica/produtos', ['codigo' => 'P1', 'nome' => 'Cimento'], $this->h)
            ->assertStatus(422)->assertJsonValidationErrors(['categoria_produto_id'], 'erros');
        $this->postJson('/api/logistica/produtos', ['codigo' => 'P1', 'nome' => 'Cimento', 'categoria_produto_id' => $cat, 'codigo_conta' => '611'], $this->h)
            ->assertStatus(422)->assertJsonValidationErrors(['codigo_conta'], 'erros');
        $id = $this->postJson('/api/logistica/produtos', ['codigo' => 'P1', 'nome' => 'Cimento', 'categoria_produto_id' => $cat, 'movimenta_stock' => true], $this->h)
            ->assertCreated()->assertJsonPath('dados.contas.venda', '611')->assertJsonPath('dados.contas_origem.venda', 'categoria')
            ->assertJsonPath('dados.categoria', 'Materiais')->json('dados.id');
        $this->putJson("/api/logistica/produtos/{$id}", ['categoria_produto_id' => null], $this->h)->assertStatus(422);

        // produto antigo sem categoria: a edição exige-a; até lá vale a conta de recurso do produto
        $antigo = $this->como(fn () => Produto::create(['codigo' => 'L1', 'nome' => 'Legado', 'movimenta_stock' => true, 'conta_custo' => '711', 'conta_iva' => '3453'])->id);
        $this->getJson("/api/logistica/produtos/{$antigo}", $this->h)->assertJsonPath('dados.contas.custo', '711')
            ->assertJsonPath('dados.contas_origem.custo', 'produto')->assertJsonPath('dados.contas.iva_liquidado', '3453');
        $this->putJson("/api/logistica/produtos/{$antigo}", ['nome' => 'Legado 2'], $this->h)->assertStatus(422)->assertJsonValidationErrors(['categoria_produto_id'], 'erros');

        // a contabilização lê da categoria (CMV pela configuração da logística) e muda quando a categoria muda
        $this->como(function () use ($id, $cat) {
            $p = Produto::query()->findOrFail($id);
            $this->assertSame('711', app(ServicoConfigLogistica::class)->contaCusto($p));
            CategoriaProduto::query()->whereKey($cat)->first()->forceFill(['conta_custo' => '785'])->save();
            $this->assertSame('785', ContasProduto::de(Produto::query()->findOrFail($id))->custo);
        });
    }

    #[Test]
    public function ficha_tecnica_e_niveis_de_stock_validados(): void
    {
        $cat = $this->categoria();
        $base = ['codigo' => 'P1', 'nome' => 'Bomba', 'categoria_produto_id' => $cat, 'movimenta_stock' => true];
        $this->postJson('/api/logistica/produtos', $base + ['stock_minimo' => 10, 'stock_seguranca' => 5], $this->h)
            ->assertStatus(422)->assertJsonPath('codigo', 'NIVEIS_STOCK_INVALIDOS');
        $this->postJson('/api/logistica/produtos', $base + ['stock_maximo' => -1], $this->h)->assertStatus(422);
        $id = $this->postJson('/api/logistica/produtos', $base + ['stock_minimo' => 2, 'stock_seguranca' => 4, 'ponto_encomenda' => 6, 'stock_maximo' => 20,
            'marca' => ' Grundfos ', 'modelo' => 'CR-5', 'comprimento' => 30, 'unidade_dimensao' => 'cm', 'peso_liquido' => 12.5,
            'caracteristicas' => [['chave' => 'Potência', 'valor' => '1,5 kW'], ['chave' => '', 'valor' => '']], 'observacoes_tecnicas' => 'Uso industrial'], $this->h)
            ->assertCreated()->assertJsonPath('dados.niveis_stock.ponto_encomenda', '6.000')
            ->assertJsonPath('dados.ficha_tecnica.marca', 'Grundfos')->assertJsonPath('dados.ficha_tecnica.caracteristicas', [['chave' => 'Potência', 'valor' => '1,5 kW']])
            ->json('dados.id');
        // a ordem é verificada com os valores já gravados
        $this->putJson("/api/logistica/produtos/{$id}", ['stock_maximo' => 3], $this->h)->assertStatus(422)->assertJsonPath('codigo', 'NIVEIS_STOCK_INVALIDOS');
        $this->putJson("/api/logistica/produtos/{$id}", ['caracteristicas' => [['chave' => '', 'valor' => 'x']]], $this->h)->assertStatus(422)
            ->assertJsonPath('codigo', 'CARACTERISTICA_INVALIDA');
        $this->putJson("/api/logistica/produtos/{$id}", ['unidade_dimensao' => 'pol'], $this->h)->assertStatus(422);
    }

    #[Test]
    public function regras_de_notificacao_dos_niveis(): void
    {
        $n = ['stock_minimo' => '2', 'stock_seguranca' => '4', 'ponto_encomenda' => '6', 'stock_maximo' => '20'];
        $this->assertSame(['CRITICO', 'Abaixo do stock mínimo', '19.000'], array_values(ServicoNiveisStock::classificar('1.000', '0.000', $n)));
        $this->assertSame('Abaixo do stock de segurança', ServicoNiveisStock::classificar('3.000', '0.000', $n)['motivo']);
        $this->assertSame(['REPOSICAO', '14.000'], [ServicoNiveisStock::classificar('6.000', '0.000', $n)['estado'], ServicoNiveisStock::classificar('6.000', '0.000', $n)['quantidade_sugerida']]);
        $this->assertSame('NORMAL', ServicoNiveisStock::classificar('5.000', '10.000', $n)['estado']);   // coberto por encomendas em curso
        $this->assertSame('EXCESSO', ServicoNiveisStock::classificar('25.000', '0.000', $n)['estado']);
        $this->assertSame('NORMAL', ServicoNiveisStock::classificar('10.000', '0.000', $n)['estado']);
        $this->assertSame(['CRITICO', '6.000'], [ServicoNiveisStock::classificar('0.000', '0.000', ['ponto_encomenda' => '6'])['estado'],
            ServicoNiveisStock::classificar('0.000', '0.000', ['ponto_encomenda' => '6'])['quantidade_sugerida']]);   // sem máximo: repõe até ao ponto
    }

    #[Test]
    public function alertas_sugestoes_cache_e_pedido_de_compra(): void
    {
        $cat = $this->categoria();
        $p = $this->postJson('/api/logistica/produtos', ['codigo' => 'P1', 'nome' => 'Bomba', 'categoria_produto_id' => $cat, 'movimenta_stock' => true,
            'stock_minimo' => 2, 'stock_seguranca' => 4, 'ponto_encomenda' => 6, 'stock_maximo' => 20], $this->h)->assertCreated()->json('dados.id');
        $q = $this->postJson('/api/logistica/produtos', ['codigo' => 'Q1', 'nome' => 'Válvula', 'categoria_produto_id' => $cat, 'movimenta_stock' => true,
            'stock_maximo' => 5], $this->h)->assertCreated()->json('dados.id');
        $this->ajuste($p, 'E', 5);
        $this->ajuste($q, 'E', 9);

        $r = $this->getJson('/api/logistica/niveis-stock', $this->h)->assertOk();
        $this->assertSame(['criticos' => 0, 'reposicao' => 1, 'excesso' => 1], array_intersect_key($r->json('dados.resumo'), array_flip(['criticos', 'reposicao', 'excesso'])));
        $this->assertSame(['P1', 'REPOSICAO', '15.000'], [$r->json('dados.linhas.0.codigo'), $r->json('dados.linhas.0.estado'), $r->json('dados.linhas.0.quantidade_sugerida')]);
        $this->assertTrue(Cache::has(ServicoNiveisStock::chaveCache($this->empresa->id)));

        // saída de stock: a cache é invalidada depois do commit e o produto passa a crítico
        $this->ajuste($p, 'S', 2);
        $this->assertFalse(Cache::has(ServicoNiveisStock::chaveCache($this->empresa->id)));
        $this->getJson('/api/logistica/niveis-stock?estado=CRITICO', $this->h)->assertOk()->assertJsonCount(1, 'dados.linhas')
            ->assertJsonPath('dados.linhas.0.motivo', 'Abaixo do stock de segurança');

        // Vendas › Relatórios «Alertas de Stock Baixo» e Início
        $alertas = $this->getJson('/api/vendas/relatorios/alertas-stock', $this->h)->assertOk()->json('dados');
        $this->assertSame([['P1', 'CRITICO']], array_map(fn ($a) => [$a['codigo'], $a['estado']], $alertas));
        $pendentes = collect($this->getJson('/api/gestao/inicio', $this->h)->assertOk()->json('dados.pendentes'))->pluck('quantidade', 'id');
        $this->assertSame(1, $pendentes['stock_critico'] ?? null);

        // gerar o pedido de compra pelo fluxo das Compras: a sugestão passa a contar como «em encomenda»
        $this->postJson('/api/logistica/niveis-stock/pedido-compra', ['linhas' => [['produto_id' => $p, 'quantidade' => 17]]], $this->sessao(['armazem_stock_view']))
            ->assertForbidden();
        $this->postJson('/api/logistica/niveis-stock/pedido-compra', ['linhas' => [['produto_id' => $p, 'quantidade' => 0]]], $this->h)->assertStatus(422);
        $pc = $this->postJson('/api/logistica/niveis-stock/pedido-compra', ['linhas' => [['produto_id' => $p, 'quantidade' => 17]]], $this->h)->assertCreated()->json('dados');
        $this->assertNotEmpty($pc['numero_pedido']);
        $this->assertSame('17.000', (string) DB::table('itens_compra')->where('pedido_compra_id', $pc['id'])->value('quantidade'));
        $linha = $this->getJson('/api/logistica/niveis-stock?estado=CRITICO', $this->h)->json('dados.linhas.0');
        $this->assertSame(['17.000', '0.000'], [$linha['em_encomenda'], $linha['quantidade_sugerida']]);
    }

    #[Test]
    public function conversao_das_contas_para_categorias_simula_converte_e_e_idempotente(): void
    {
        $antes = $this->como(function () {
            $bebidas = CategoriaProduto::create(['nome' => 'Bebidas']);
            $mk = fn (string $c, ?int $cat, array $contas) => Produto::create(['codigo' => $c, 'nome' => "Produto {$c}", 'categoria_produto_id' => $cat] + $contas)->id;
            $ids = [
                'a' => $mk('A', $bebidas->id, ['codigo_conta' => '611', 'conta_custo' => '711']),
                'b' => $mk('B', $bebidas->id, ['codigo_conta' => '611', 'conta_custo' => '711']),
                'c' => $mk('C', $bebidas->id, ['codigo_conta' => '621']),
                'd' => $mk('D', null, ['conta_iva' => '3453']),
                'e' => $mk('E', null, []),
            ];
            Produto::query()->findOrFail($ids['e'])->delete();   // eliminado logicamente: também é convertido

            return ['ids' => $ids, 'contas' => array_map(fn ($id) => ContasProduto::de(Produto::withTrashed()->findOrFail($id))->porColuna(), $ids)];
        });

        $this->artisan('erp:produtos:converter-contas-categorias', ['--empresa' => [$this->empresa->id], '--simular' => true])->assertSuccessful();
        $this->assertSame(0, $this->como(fn () => RegistoConversaoContasProduto::query()->count()));
        $this->assertSame(1, $this->como(fn () => CategoriaProduto::query()->count()));

        $this->artisan('erp:produtos:converter-contas-categorias', ['--empresa' => [$this->empresa->id]])->assertSuccessful();
        $this->como(function () use ($antes) {
            $nomes = CategoriaProduto::query()->orderBy('id')->pluck('nome')->all();
            $this->assertSame(['Bebidas', 'Bebidas (2)', 'Geral', 'Geral (2)'], $nomes);   // sem categoria: uma «Geral» por combinação
            foreach ($antes['ids'] as $k => $id) {
                $p = Produto::withTrashed()->findOrFail($id);
                $this->assertNotNull($p->categoria_produto_id, "produto {$k} sem categoria");
                $this->assertSame($antes['contas'][$k], ContasProduto::de($p)->porColuna(), "a contabilização do produto {$k} mudou");
            }
            $this->assertSame('611', CategoriaProduto::query()->where('nome', 'Bebidas')->value('codigo_conta'));
            $this->assertSame('621', CategoriaProduto::query()->where('nome', 'Bebidas (2)')->value('codigo_conta'));
            $this->assertSame(5, RegistoConversaoContasProduto::query()->count());
            $this->assertSame('611', Produto::query()->findOrFail($antes['ids']['a'])->codigo_conta);   // nada se perde no produto
        });

        // idempotente: nova execução não cria nem move nada
        $this->artisan('erp:produtos:converter-contas-categorias', ['--empresa' => [$this->empresa->id]])->assertSuccessful();
        $this->assertSame(4, $this->como(fn () => CategoriaProduto::query()->count()));
        $this->assertSame(5, $this->como(fn () => RegistoConversaoContasProduto::query()->count()));
    }

    #[Test]
    public function stock_movido_pelo_servico_invalida_a_cache_so_depois_do_commit(): void
    {
        $id = $this->como(fn () => Produto::create(['codigo' => 'Z', 'nome' => 'Z', 'movimenta_stock' => true, 'stock_minimo' => 5])->id);
        $this->getJson('/api/logistica/niveis-stock', $this->h)->assertOk()->assertJsonPath('dados.resumo.criticos', 1);
        $this->como(fn () => DB::transaction(fn () => app(ServicoStock::class)->entrada($id, $this->armazem, '10', '50', now()->toDateString(), 'Teste')));
        $this->getJson('/api/logistica/niveis-stock', $this->h)->assertOk()->assertJsonPath('dados.resumo.criticos', 0);
    }
}
