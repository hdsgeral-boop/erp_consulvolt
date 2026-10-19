<?php

namespace Tests\Feature;

use App\Models\Armazem;
use App\Models\Empresa;
use App\Models\MeioPagamento;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\StockArmazem;
use App\Models\Terceiro;
use App\Services\Logistica\ServicoStock;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Paridade de Vendas com o sistema anterior (verificacao_visual/RELATORIO.md, secção 4): cabeçalho «Gestão de Vendas»
 * (pendentes, saldo a receber, mensal), contadores, alertas de stock, análise do SAF-T, «Baixar Stock Autom.», guias de
 * saída pendentes, meios de pagamento, «Forçar limpeza de estado», edição em massa e imagem do produto.
 */
final class ParidadeVendasTest extends TestCase
{
    private Empresa $empresa;

    private array $ids = [];

    private string $hoje;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hoje = now()->toDateString();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000888']);
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['311' => 'Clientes', '3452' => 'IVA liquidado', '611' => 'Vendas', '612' => 'Vendas 2', '2611' => 'Mercadorias', '7111' => 'CMV', '4311' => 'Banco'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            $this->ids['cliente'] = Terceiro::create(['nome' => 'Cliente Teste', 'nif' => '5000000901', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '311'])->id;
            $this->ids['outro'] = Terceiro::create(['nome' => 'Outro Cliente', 'nif' => '5000000902', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '311'])->id;
            $this->ids['p'] = Produto::create(['codigo' => 'P1', 'nome' => 'Produto Um', 'preco_unitario' => 1000, 'taxa_imposto' => 14, 'codigo_conta' => '611',
                'conta_iva_liquidado' => '3452', 'movimenta_stock' => true, 'conta_inventario' => '2611', 'conta_custo' => '7111'])->id;
            $this->ids['p2'] = Produto::create(['codigo' => 'P2', 'nome' => 'Produto Dois', 'preco_unitario' => 500, 'taxa_imposto' => 14, 'codigo_conta' => '611',
                'conta_iva_liquidado' => '3452', 'movimenta_stock' => true, 'conta_inventario' => '2611', 'conta_custo' => '7111'])->id;
            $this->ids['a'] = Armazem::create(['nome' => 'Central', 'predefinido' => true])->id;
            app(ServicoStock::class)->entrada($this->ids['p'], $this->ids['a'], '10', '600', $this->hoje, 'Stock inicial');
            $this->ids['meio'] = MeioPagamento::create(['nome' => 'Banco Teste', 'codigo_conta' => '4311', 'iban' => 'AO06000000000000000000001', 'swift' => 'TESTAOLU', 'ativo' => true])->id;
        });
    }

    private function sessao(array $permissoes, ?Empresa $empresa = null): array
    {
        $empresa ??= $this->empresa;
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $empresa->id];
    }

    private function tudo(): array
    {
        return $this->sessao(['vendas_faturacao_view', 'vendas_fat_emitir', 'vendas_relatorios_view', 'vendas_produtos_view', 'vendas_produtos_gerir']);
    }

    private function documento(string $tipo, string $data, string $bruto, ?string $pendente, ?string $estado, ?string $pago = '0', ?int $empresa = null): int
    {
        static $n = 0;
        $n++;

        return DB::table('vendas')->insertGetId(['empresa_id' => $empresa ?? $this->empresa->id, 'tipo_documento' => $tipo, 'numero_documento' => "{$tipo} PV/{$n}",
            'data_emissao' => "{$data} 10:00:00", 'cliente_id' => $this->ids['cliente'], 'total_liquido' => $bruto, 'total_imposto' => '0', 'total_bruto' => $bruto,
            'valor_pendente' => $pendente, 'valor_pago' => $pago, 'estado' => $estado]);
    }

    private function stock(int $produto): string
    {
        return (string) app(ContextoEmpresa::class)->executarComo($this->empresa->id,
            fn () => StockArmazem::query()->where('armazem_id', $this->ids['a'])->where('produto_id', $produto)->value('quantidade_stock'));
    }

    #[Test]
    public function cabecalho_conta_pendentes_e_saldo_pelo_estado_e_nao_so_pelo_valor_pago(): void
    {
        $this->documento('FT', $this->hoje, '1000.00', '1000.00', 'PENDENTE');
        $this->documento('FT', '2025-01-10', '500.00', '400.00', 'PARCIAL', '100.00');     // recibo parcial: conta o saldo
        $this->documento('FR', $this->hoje, '300.00', null, 'PAGO', '0.00');                 // FR do POS migrada: valor_pago 0, mas PAGA
        $this->documento('FT', $this->hoje, '200.00', '200.00', 'PAGO');                     // estado manda
        $this->documento('FT', $this->hoje, '900.00', '900.00', 'ANULADO');
        $this->documento('NE', $this->hoje, '700.00', '700.00', 'PENDENTE');                 // não fiscal
        $this->documento('FT', $this->hoje, '800.00', '800.00', 'PENDENTE', '0', $this->criarEmpresa()->id);   // outra empresa

        $this->getJson('/api/vendas/cabecalho', $this->tudo())->assertOk()
            ->assertJsonPath('dados.pendentes', 2)
            ->assertJsonPath('dados.a_receber', '1400.00')
            ->assertJsonPath('dados.mensal', '1500.00');   // FT + FR do mês, não anulados (1000 + 300 + 200)
        $this->getJson('/api/vendas/cabecalho', $this->sessao(['pos_view']))->assertForbidden();
    }

    #[Test]
    public function contagens_dos_separadores_e_listagem_com_un_cc_e_progresso(): void
    {
        $this->documento('FT', $this->hoje, '10.00', '10.00', 'PENDENTE');
        $this->documento('NC', $this->hoje, '5.00', '0', null);
        $this->documento('OR', $this->hoje, '5.00', '5.00', null);
        $this->documento('GR', $this->hoje, '0', null, 'CONCLUIDO');
        $this->getJson('/api/vendas/documentos/contagens', $this->tudo())->assertOk()
            ->assertJsonPath('dados.faturas', 2)->assertJsonPath('dados.orcamentos', 1)->assertJsonPath('dados.encomendas', 0)
            ->assertJsonPath('dados.guias', 1)->assertJsonPath('dados.recibos', 0);

        $s = $this->tudo();
        $ne = $this->postJson('/api/vendas/documentos', ['tipo_documento' => 'NE', 'cliente_id' => $this->ids['cliente'], 'data_emissao' => $this->hoje,
            'linhas' => [['produto_id' => $this->ids['p'], 'quantidade' => 4]]], $s)->assertCreated()->json('dados');
        $this->postJson("/api/vendas/documentos/{$ne['id']}/converter", ['tipo_destino' => 'GR'], $s)->assertCreated();
        $linha = collect($this->getJson('/api/vendas/documentos?tipos=NE', $s)->assertOk()->json('dados'))->firstWhere('id', $ne['id']);
        $this->assertSame(['entrega' => 100, 'faturacao' => 0], $linha['progresso']);
        $this->assertArrayHasKey('unidade_negocio', $linha);
        $this->assertArrayHasKey('centro_custo', $linha);
    }

    #[Test]
    public function baixar_stock_desligado_nao_movimenta_e_ligado_movimenta(): void
    {
        $s = $this->tudo();
        $base = ['tipo_documento' => 'FT', 'cliente_id' => $this->ids['cliente'], 'data_emissao' => $this->hoje, 'linhas' => [['produto_id' => $this->ids['p'], 'quantidade' => 2]]];
        $this->postJson('/api/vendas/documentos', $base + ['baixar_stock' => false], $s)->assertCreated();
        $this->assertSame('10.000', $this->stock($this->ids['p']));
        $this->postJson('/api/vendas/documentos', $base + ['baixar_stock' => true], $s)->assertCreated();
        $this->assertSame('8.000', $this->stock($this->ids['p']));
    }

    #[Test]
    public function factura_de_guia_de_saida_marca_a_guia_e_nao_volta_a_baixar_stock(): void
    {
        $guia = DB::table('guias_saida')->insertGetId(['empresa_id' => $this->empresa->id, 'numero_documento' => 'GE T/1', 'data' => $this->hoje, 'tipo' => 'VENDA',
            'terceiro_id' => $this->ids['cliente'], 'armazem_id' => $this->ids['a'], 'estado' => 'CONCLUIDO']);
        DB::table('itens_guia_saida')->insert(['empresa_id' => $this->empresa->id, 'guia_saida_id' => $guia, 'produto_id' => $this->ids['p'], 'quantidade' => 3]);
        $s = $this->tudo();

        $g = $this->getJson('/api/vendas/guias-saida-pendentes', $s)->assertOk()->json('dados');
        $this->assertSame(['GE T/1'], array_column($g, 'numero_documento'));
        $this->assertSame('3.000', $g[0]['linhas'][0]['quantidade']);

        $pedido = ['tipo_documento' => 'FT', 'data_emissao' => $this->hoje, 'guias_saida' => [$guia], 'linhas' => [['produto_id' => $this->ids['p'], 'quantidade' => 3]]];
        $this->postJson('/api/vendas/documentos', $pedido + ['cliente_id' => $this->ids['outro']], $s)->assertStatus(422)->assertJsonPath('codigo', 'GUIA_OUTRO_CLIENTE');
        $ft = $this->postJson('/api/vendas/documentos', $pedido + ['cliente_id' => $this->ids['cliente']], $s)->assertCreated()->json('dados');
        $this->assertSame('10.000', $this->stock($this->ids['p']));   // o stock saiu na guia (aqui inserida à mão), a FT não volta a baixar
        $this->assertSame('FATURADA', DB::table('guias_saida')->where('id', $guia)->value('estado'));
        $this->assertSame($ft['id'], (int) DB::table('guias_saida')->where('id', $guia)->value('venda_relacionada_id'));
        $this->assertSame([], $this->getJson('/api/vendas/guias-saida-pendentes', $s)->json('dados'));
        $this->postJson('/api/vendas/documentos', $pedido + ['cliente_id' => $this->ids['cliente']], $s)->assertStatus(422)->assertJsonPath('codigo', 'GUIA_JA_FATURADA');
    }

    #[Test]
    public function contas_de_pagamento_sao_copiadas_da_tesouraria_e_opcao_de_ocultar_fica_gravada(): void
    {
        $s = $this->tudo();
        $this->getJson('/api/vendas/meios-pagamento', $s)->assertOk()->assertJsonPath('dados.0.nome', 'Banco Teste');
        $ft = $this->postJson('/api/vendas/documentos', ['tipo_documento' => 'FT', 'cliente_id' => $this->ids['cliente'], 'data_emissao' => $this->hoje,
            'linhas' => [['produto_id' => $this->ids['p2'], 'quantidade' => 1]], 'contas_pagamento' => [$this->ids['meio']], 'ocultar_meios_pagamento' => true], $s)
            ->assertCreated()->json('dados');
        $this->assertSame('AO06000000000000000000001', $ft['contas_pagamento'][0]['iban']);
        $this->assertTrue($ft['ocultar_meios_pagamento']);
        // meio de pagamento de outra empresa: recusado na validação
        $outro = app(ContextoEmpresa::class)->executarComo($this->criarEmpresa()->id, fn () => MeioPagamento::create(['nome' => 'Alheio', 'codigo_conta' => '4311', 'ativo' => true])->id);
        $this->postJson('/api/vendas/documentos', ['tipo_documento' => 'FT', 'cliente_id' => $this->ids['cliente'], 'data_emissao' => $this->hoje,
            'linhas' => [['produto_id' => $this->ids['p2'], 'quantidade' => 1]], 'contas_pagamento' => [$outro]], $s)->assertStatus(422);
    }

    #[Test]
    public function forcar_limpeza_de_estado_recalcula_a_factura(): void
    {
        $id = $this->documento('FT', $this->hoje, '1000.00', '0.00', 'PAGO', '0.00');   // estado incoerente: nada pago
        $this->postJson("/api/vendas/documentos/{$id}/recalcular-estado", [], $this->tudo())->assertOk()
            ->assertJsonPath('dados.estado', 'PENDENTE')->assertJsonPath('dados.valor_pendente', '1000.00');
        $fr = $this->documento('FR', $this->hoje, '50.00', '0', 'PAGO');
        $this->postJson("/api/vendas/documentos/{$fr}/recalcular-estado", [], $this->tudo())->assertStatus(422);
        $this->postJson("/api/vendas/documentos/{$id}/recalcular-estado", [], $this->sessao(['vendas_faturacao_view']))->assertForbidden();
    }

    #[Test]
    public function alertas_de_stock_baixo_e_stock_por_produto(): void
    {
        $s = $this->tudo();
        $alertas = $this->getJson('/api/vendas/relatorios/alertas-stock', $s)->assertOk()->json('dados');
        $this->assertSame(['P2'], array_column($alertas, 'codigo'));   // P1 tem 10 un.; P2 nenhuma
        $this->assertSame('10.000', $this->getJson('/api/vendas/produtos/stock', $s)->assertOk()->json('dados.'.$this->ids['p']));
    }

    #[Test]
    public function analise_saft_resume_o_lote_e_detalha_as_linhas(): void
    {
        $s = $this->tudo();
        $this->postJson('/api/vendas/documentos', ['tipo_documento' => 'FT', 'cliente_id' => $this->ids['cliente'], 'data_emissao' => $this->hoje,
            'linhas' => [['produto_id' => $this->ids['p'], 'quantidade' => 2], ['produto_id' => $this->ids['p2'], 'quantidade' => 1]]], $s)->assertCreated();
        $r = $this->getJson("/api/vendas/saft/analise?inicio={$this->hoje}&fim={$this->hoje}", $s)->assertOk()
            ->assertJsonPath('dados.sumario.faturas', 1)->assertJsonPath('dados.sumario.notas_credito', 0)
            ->assertJsonPath('dados.sumario.clientes', 1)->assertJsonPath('dados.sumario.artigos', 2)
            ->assertJsonPath('dados.sumario.total_liquido', '2500.00')->assertJsonPath('dados.sumario.total_bruto', '2850.00');
        $this->assertCount(2, $r->json('dados.linhas'));
        $this->assertSame('1140.00', $r->json('dados.linhas.0.preco_com_iva'));
        $this->getJson("/api/vendas/saft/analise?inicio={$this->hoje}&fim=2000-01-01", $s)->assertStatus(422);
        $this->getJson("/api/vendas/saft/analise?inicio={$this->hoje}&fim={$this->hoje}", $this->sessao(['vendas_faturacao_view']))->assertForbidden();
    }

    #[Test]
    public function edicao_em_massa_de_produtos_aplica_dados_e_as_contas_vao_para_a_categoria(): void
    {
        $s = $this->tudo();
        $categoria = app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => DB::table('categorias_produtos')
            ->insertGetId(['empresa_id' => $this->empresa->id, 'nome' => 'Mercadorias', 'codigo_conta' => '611']));
        $this->postJson('/api/vendas/produtos/editar-em-massa', ['ids' => [$this->ids['p'], $this->ids['p2']],
            'dados' => ['preco' => ['modo' => 'PERCENT', 'valor' => 10], 'bloqueado' => true, 'categoria_produto_id' => $categoria]], $s)
            ->assertOk()->assertJsonPath('dados.alterados', 2);
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () use ($categoria) {
            $p = Produto::query()->find($this->ids['p']);
            $this->assertSame('1100.00', (string) $p->preco_unitario);
            $this->assertSame($categoria, (int) $p->categoria_produto_id);
            $this->assertTrue((bool) $p->bloqueado);
            $this->assertSame('550.00', (string) Produto::query()->find($this->ids['p2'])->preco_unitario);
        });

        // contas no produto: recusadas com mensagem clara (a categoria sobrepõe-se) e nada é alterado
        $this->postJson('/api/vendas/produtos/editar-em-massa', ['ids' => [$this->ids['p']], 'dados' => ['bloqueado' => false], 'contas' => ['codigo_conta' => '612']], $s)
            ->assertStatus(422)->assertJsonPath('codigo', 'CONTAS_NA_CATEGORIA')->assertJsonPath('erros.contas', ['codigo_conta']);
        $this->assertTrue((bool) DB::table('produtos')->where('id', $this->ids['p'])->value('bloqueado'));
        $this->assertSame('611', DB::table('produtos')->where('id', $this->ids['p'])->value('codigo_conta'));

        // contas da categoria: exigem vendas_categorias_contas
        $corpo = ['categoria_contas' => ['categoria_produto_id' => $categoria, 'contas' => ['codigo_conta' => '612', 'conta_custo' => '7111', 'conta_inventario' => '__RETIRAR__']]];
        $this->postJson('/api/vendas/produtos/editar-em-massa', $corpo, $s)->assertForbidden();
        $this->assertSame('611', DB::table('categorias_produtos')->where('id', $categoria)->value('codigo_conta'));
        $comContas = $this->sessao(['vendas_produtos_gerir', 'vendas_categorias_contas']);
        $r = $this->postJson('/api/vendas/produtos/editar-em-massa', $corpo, $comContas)->assertOk()
            ->assertJsonPath('dados.alterados', 0)->assertJsonPath('dados.categoria.produtos', 2);
        $this->assertStringContainsString('aplicam-se aos 2 produto(s) da categoria', $r->json('mensagem'));
        $cat = DB::table('categorias_produtos')->where('id', $categoria)->first();
        $this->assertSame(['612', '7111', null], [$cat->codigo_conta, $cat->conta_custo, $cat->conta_inventario]);
        // conta da classe errada para o campo (proveitos tem de ser 6): validada como na ficha da categoria, nada muda
        $this->postJson('/api/vendas/produtos/editar-em-massa', ['ids' => [$this->ids['p']], 'dados' => ['bloqueado' => false],
            'categoria_contas' => ['categoria_produto_id' => $categoria, 'contas' => ['codigo_conta' => '7111']]], $comContas)
            ->assertStatus(422)->assertJsonPath('codigo', 'CONTA_CLASSE_INVALIDA');
        $this->assertTrue((bool) DB::table('produtos')->where('id', $this->ids['p'])->value('bloqueado'));
        // categoria de outra empresa: inexistente
        $outra = $this->criarEmpresa(['nif' => '5417000889']);
        $alheia = DB::table('categorias_produtos')->insertGetId(['empresa_id' => $outra->id, 'nome' => 'Alheia']);
        $this->postJson('/api/vendas/produtos/editar-em-massa', ['categoria_contas' => ['categoria_produto_id' => $alheia, 'contas' => ['codigo_conta' => '612']]], $comContas)
            ->assertStatus(422);
        $this->postJson('/api/vendas/produtos/editar-em-massa', ['ids' => [$this->ids['p']], 'dados' => ['bloqueado' => false]], $this->sessao(['vendas_produtos_view']))->assertForbidden();
    }

    #[Test]
    public function imagem_do_produto_so_aceita_imagens_e_e_servida_com_autorizacao(): void
    {
        Storage::fake('local');
        $s = $this->tudo();
        $url = "/api/vendas/produtos/{$this->ids['p']}/imagem";

        // não-imagens recusadas (mesmo com extensão de imagem), SVG recusado
        $this->post($url, ['imagem' => UploadedFile::fake()->createWithContent('x.png', '<?php echo 1;')], $s + ['Accept' => 'application/json'])->assertStatus(422);
        $this->post($url, ['imagem' => UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')], $s + ['Accept' => 'application/json'])->assertStatus(422);

        $r = $this->post($url, ['imagem' => UploadedFile::fake()->image('../../foto.png', 40, 30)], $s + ['Accept' => 'application/json'])->assertOk();
        $this->assertStringStartsWith("/vendas/produtos/{$this->ids['p']}/imagem?v=", $r->json('dados.imagem_url'));
        $caminho = DB::table('produtos')->where('id', $this->ids['p'])->value('imagem_caminho');
        $this->assertMatchesRegularExpression('#^empresas/'.$this->empresa->id.'/produtos/[0-9a-f-]{36}\.png$#', $caminho);   // nome gerado no servidor
        Storage::disk('local')->assertExists($caminho);

        $this->get($url, $s)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get($url, $this->sessao(['pos_venda']))->assertOk();                         // o POS também a vê
        $this->get($url, $this->sessao(['rh_colaboradores_view']))->assertForbidden();
        $this->getJson('/api/vendas/produtos/imagens', $s)->assertOk()->assertJsonPath('dados.0.id', $this->ids['p']);
        $this->getJson("/api/logistica/produtos/{$this->ids['p']}", $s)->assertOk()->assertJsonPath('dados.imagem_url', $r->json('dados.imagem_url'));

        // outra empresa não chega ao produto
        $outra = $this->criarEmpresa();
        $this->get($url, $this->sessao(['vendas_produtos_view'], $outra))->assertNotFound();

        // sem permissão de gestão não altera; remover apaga o ficheiro
        $this->delete($url, [], $this->sessao(['vendas_produtos_view']))->assertForbidden();
        $this->delete($url, [], $s)->assertOk();
        Storage::disk('local')->assertMissing($caminho);
        $this->get($url, $s)->assertNotFound();
    }

    #[Test]
    public function cabecalho_e_dashboard_usam_o_mesmo_saldo_a_receber(): void
    {
        $this->documento('FT', $this->hoje, '1000.00', '600.00', 'PARCIAL', '400.00');   // recibo parcial: conta o saldo
        $this->documento('FT', $this->hoje, '100.00', null, 'PENDENTE', null);            // migrada sem pendente gravado: bruto − pago
        $this->documento('FR', $this->hoje, '300.00', '300.00', 'PAGO', '0.00');          // FR do POS migrada: paga pelo estado
        $s = $this->tudo();
        $this->getJson('/api/vendas/cabecalho', $s)->assertOk()->assertJsonPath('dados.a_receber', '700.00')->assertJsonPath('dados.pendentes', 2);
        $r = $this->getJson("/api/vendas/relatorios/resumo?inicio={$this->hoje}&fim={$this->hoje}", $s)->assertOk()
            ->assertJsonPath('dados.a_receber', '700.00')->assertJsonPath('dados.bruto', '1400.00');
        $this->assertEqualsCanonicalizing(['600.00', '100.00'], array_column($r->json('dados.pendentes'), 'valor_pendente'));
    }

    #[Test]
    public function dados_a_rever_antes_de_activar_a_facturacao_electronica(): void
    {
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            Produto::create(['codigo' => null, 'nome' => 'Sem Codigo', 'preco_unitario' => 1, 'taxa_imposto' => 14]);
            Produto::create(['codigo' => 'ISE', 'nome' => 'Isento Sem Motivo', 'preco_unitario' => 1, 'taxa_imposto' => 0]);
            Produto::create(['codigo' => 'T10', 'nome' => 'Taxa Estranha', 'preco_unitario' => 1, 'taxa_imposto' => 10]);
            Terceiro::create(['nome' => 'Cliente Sem NIF', 'tipo' => Terceiro::CLIENTE]);
        });
        $d = $this->getJson('/api/vendas/faturacao-eletronica/diagnostico', $this->tudo())->assertOk()->json('dados');
        $this->assertNull($d['empresa_nif']);
        $this->assertSame(['Sem Codigo'], $d['sem_codigo']['exemplos']);
        $this->assertSame(1, $d['isentos_sem_motivo']['total']);
        $this->assertSame(['Taxa Estranha (10%)'], $d['taxa_fora']['exemplos']);
        $this->assertSame(['Cliente Sem NIF'], $d['clientes_sem_nif']['exemplos']);
        $this->getJson('/api/vendas/faturacao-eletronica/diagnostico', $this->sessao(['pos_view']))->assertForbidden();
    }

    #[Test]
    public function converter_encomenda_em_pedido_de_compra_a_partir_da_lista(): void
    {
        $s = $this->sessao(['vendas_faturacao_view', 'vendas_fat_emitir', 'compras_gerar_pedidos']);
        $ne = $this->postJson('/api/vendas/documentos', ['tipo_documento' => 'NE', 'cliente_id' => $this->ids['cliente'], 'data_emissao' => $this->hoje,
            'linhas' => [['produto_id' => $this->ids['p2'], 'quantidade' => 3]]], $s)->assertCreated()->json('dados');
        $this->postJson("/api/vendas/documentos/{$ne['id']}/pedido-compra", [], $this->sessao(['vendas_faturacao_view']))->assertForbidden();
        $p = $this->postJson("/api/vendas/documentos/{$ne['id']}/pedido-compra", [], $s)->assertCreated()->json('dados');
        $this->assertNotEmpty($p['id']);
        // o pedido mostra o preço de venda da encomenda (Kz, s/ IVA) para o aviso da proposta, só a quem regista propostas ou vê vendas
        $preco = number_format((float) $ne['linhas'][0]['preco_unitario'] * (1 - (float) ($ne['linhas'][0]['percentagem_desconto'] ?? 0) / 100), 2, '.', '');
        $ref = $this->getJson("/api/compras/pedidos/{$p['id']}", $this->sessao(['compras_pedidos_view', 'compras_new_proposal']))->assertOk()->json('dados.referencia_encomendas');
        $this->assertSame(['preco_kz' => $preco, 'documentos' => [$ne['numero_documento']]], $ref[(string) $this->ids['p2']]);
        $this->assertSame([], $this->getJson("/api/compras/pedidos/{$p['id']}", $this->sessao(['compras_pedidos_view']))->assertOk()->json('dados.referencia_encomendas'));
        // a mesma linha não entra em dois pedidos activos
        $this->postJson("/api/vendas/documentos/{$ne['id']}/pedido-compra", [], $s)->assertStatus(422);
        $ft = $this->documento('FT', $this->hoje, '10.00', '10.00', 'PENDENTE');
        $this->postJson("/api/vendas/documentos/{$ft}/pedido-compra", [], $s)->assertStatus(422);
    }
}
