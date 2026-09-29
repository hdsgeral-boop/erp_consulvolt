<?php

namespace Tests\Feature;

use App\Models\DiarioContabil;
use App\Models\Empresa;
use App\Models\PlanoConta;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Terceiros (clientes/fornecedores), produtos e categorias — paridade com saveCustomer/saveSupplier/saveProduct. */
final class TerceirosProdutosTest extends TestCase
{
    private Empresa $empresa;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa();
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            DB::table('moedas')->insert([['codigo' => 'AOA', 'nome' => 'Kwanza'], ['codigo' => 'USD', 'nome' => 'Dólar']]);
            foreach ([['31', 'Clientes', 'T'], ['3111', 'Clientes nacionais', 'M'], ['3211', 'Fornecedores nacionais', 'M'], ['328', 'Compras em trânsito', 'M'],
                ['611', 'Vendas', 'M'], ['711', 'CMVMC', 'M'], ['3453', 'IVA liquidado', 'M'], ['3452', 'IVA dedutível', 'M'], ['111', 'Caixa', 'M']] as [$c, $d, $t]) {
                PlanoConta::create(['codigo' => $c, 'descricao' => $d, 'tipo' => $t]);
            }
        });
        $perfil = $this->criarPerfil(['_v2' => true, 'vendas_clientes_gerir' => true, 'compras_forn_gerir' => true, 'vendas_produtos_gerir' => true,
            'vendas_dados_del' => true, 'lancamentos_post' => true]);
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id]);
        $u->empresas()->attach($this->empresa->id);
        $this->h = $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function cliente(array $dados = []): TestResponse
    {
        return $this->postJson('/api/terceiros', array_merge(['papel' => 'CLIENTE', 'nome' => 'Cliente Lda', 'nif' => '5000000001', 'codigo_conta' => '3111'], $dados), $this->h);
    }

    #[Test]
    public function cria_cliente_com_regras_do_legado(): void
    {
        $this->cliente(['fe_pais' => 'ao'])->assertCreated()
            ->assertJsonPath('dados.tipo', 'CLIENTE')->assertJsonPath('dados.codigo_moeda', 'AOA')->assertJsonPath('dados.fe_pais', 'AO');

        $this->cliente(['nif' => '5000000001', 'nome' => 'Outro'])->assertStatus(422)->assertJsonPath('codigo', 'NIF_DUPLICADO')
            ->assertJsonPath('erros.nome', 'Cliente Lda');
        $this->cliente(['nif' => '9', 'codigo_conta' => null])->assertStatus(422)->assertJsonPath('erros.codigo_conta.0', 'Associe a conta contabilística da entidade antes de gravar.');
        $this->cliente(['nif' => '8', 'codigo_conta' => '31'])->assertStatus(422)->assertJsonPath('codigo', 'CONTA_TOTALIZADORA');
        $this->cliente(['nif' => '7', 'fe_pais' => 'ANG'])->assertStatus(422)->assertJsonPath('codigo', 'VALIDACAO');
        $this->cliente(['nif' => '6', 'codigo_moeda' => 'XYZ'])->assertStatus(422);
    }

    #[Test]
    public function fornecedor_exige_nif_e_um_fornecedor_pode_passar_a_cliente(): void
    {
        $this->postJson('/api/terceiros', ['papel' => 'FORNECEDOR', 'nome' => 'Sem NIF', 'codigo_conta' => '3211'], $this->h)
            ->assertStatus(422)->assertJsonPath('codigo', 'NIF_OBRIGATORIO');

        $id = $this->postJson('/api/terceiros', ['papel' => 'FORNECEDOR', 'nome' => 'Fornecedor SA', 'nif' => '5400000001', 'codigo_conta' => '3211',
            'conta_compra_transitoria' => '328'], $this->h)->assertCreated()->assertJsonPath('dados.tipo', 'FORNECEDOR')->json('dados.id');

        // Paridade: seleccionar o fornecedor existente na ficha de cliente acrescenta o papel (legado: "FORNECEDOR, CLIENTE")
        $this->putJson("/api/terceiros/{$id}", ['papel' => 'CLIENTE', 'codigo_conta' => '3111'], $this->h)->assertOk()
            ->assertJsonPath('dados.tipo', 'CLIENTE_FORNECEDOR')->assertJsonPath('dados.e_cliente', true)->assertJsonPath('dados.e_fornecedor', true);

        $this->getJson('/api/terceiros?papel=FORNECEDOR', $this->h)->assertJsonCount(1, 'dados');
        $this->getJson('/api/terceiros?papel=CLIENTE', $this->h)->assertJsonCount(1, 'dados');
    }

    #[Test]
    public function nao_elimina_terceiros_com_movimentos_e_elimina_logicamente_os_restantes(): void
    {
        $com = $this->cliente()->json('dados.id');
        $sem = $this->cliente(['nif' => '5000000002', 'nome' => 'Sem movimentos'])->json('dados.id');
        $diario = app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => DiarioContabil::create(['codigo' => 'VD', 'descricao' => 'Vendas']));
        $this->postJson('/api/contabilidade/lancamentos', ['diario_id' => $diario->id, 'data_documento' => '2026-05-02', 'linhas' => [
            ['codigo_conta' => '3111', 'tipo_dc' => 'D', 'valor' => 10, 'terceiro_id' => $com], ['codigo_conta' => '611', 'tipo_dc' => 'C', 'valor' => 10]]], $this->h)->assertCreated();

        $this->deleteJson("/api/terceiros/{$com}", [], $this->h)->assertStatus(422)->assertJsonPath('codigo', 'TERCEIRO_EM_USO');
        $this->deleteJson("/api/terceiros/{$sem}", [], $this->h)->assertOk();
        $this->assertNotNull(DB::table('terceiros')->where('id', $sem)->value('eliminado_em'));
        $this->getJson("/api/terceiros/{$sem}", $this->h)->assertNotFound();
        $this->cliente(['nif' => '5000000002', 'nome' => 'Reaproveita NIF'])->assertCreated();   // NIF de uma entidade eliminada fica livre
    }

    #[Test]
    public function produto_valida_contas_trata_isencao_e_sincroniza_o_catalogo_de_compras(): void
    {
        $base = ['codigo' => 'P001', 'nome' => 'Cimento 50kg', 'preco_unitario' => '7500.00', 'taxa_imposto' => 14, 'movimenta_stock' => true,
            'codigo_conta' => '611', 'conta_custo' => '711', 'conta_iva_liquidado' => '3453', 'conta_iva_dedutivel' => '3452', 'codigo_isencao_fe' => 'M02'];
        $r = $this->postJson('/api/logistica/produtos', $base, $this->h)->assertCreated()
            ->assertJsonPath('dados.unidade_fe', 'UN')
            ->assertJsonPath('dados.codigo_isencao_fe', null)          // isenção só com IVA 0% (limpa, como o legado)
            ->assertJsonPath('dados.contas.iva_liquidado', '3453');
        $id = $r->json('dados.id');
        $this->assertSame('3453', DB::table('produtos')->where('id', $id)->value('conta_iva'));   // retrocompatibilidade

        $this->assertSame(['P001', 'Cimento 50kg', '7500.00'], array_values((array) DB::table('catalogo_fornecedores')->where('produto_id', $id)->first(['codigo', 'nome', 'preco_unitario'])));
        $this->putJson("/api/logistica/produtos/{$id}", ['nome' => 'Cimento 50 kg', 'preco_unitario' => '7800.00'], $this->h)->assertOk();
        $this->assertSame('7800.00', DB::table('catalogo_fornecedores')->where('produto_id', $id)->value('preco_unitario'));
        $this->assertSame(1, DB::table('catalogo_fornecedores')->count());

        $this->postJson('/api/logistica/produtos', ['codigo' => 'P002', 'nome' => 'Isento', 'taxa_imposto' => 0, 'codigo_isencao_fe' => 'M02'], $this->h)
            ->assertCreated()->assertJsonPath('dados.codigo_isencao_fe', 'M02');
        $this->postJson('/api/logistica/produtos', $base, $this->h)->assertStatus(422)->assertJsonPath('codigo', 'PRODUTO_DUPLICADO');
        $this->postJson('/api/logistica/produtos', ['codigo' => 'P003', 'nome' => 'X', 'codigo_conta' => '31'], $this->h)
            ->assertStatus(422)->assertJsonPath('codigo', 'CONTA_TOTALIZADORA');
        $this->postJson('/api/logistica/produtos', ['codigo' => 'P004', 'nome' => 'X', 'codigo_isencao_fe' => 'ISENTO'], $this->h)->assertStatus(422);
    }

    #[Test]
    public function a_ficha_nao_altera_stock_e_o_catalogo_de_venda_exclui_bloqueados(): void
    {
        $id = $this->postJson('/api/logistica/produtos', ['codigo' => 'P1', 'nome' => 'Artigo', 'quantidade_stock' => 500], $this->h)->assertCreated()->json('dados.id');
        $this->assertNull(DB::table('produtos')->where('id', $id)->value('quantidade_stock'));   // campo ignorado
        $this->assertSame(0, DB::table('stock_armazem')->count());

        $this->getJson('/api/logistica/produtos/catalogo', $this->h)->assertJsonCount(1, 'dados');
        $this->postJson("/api/logistica/produtos/{$id}/bloquear", [], $this->h)->assertOk()->assertJsonPath('dados.bloqueado', true);
        $this->getJson('/api/logistica/produtos/catalogo', $this->h)->assertJsonCount(0, 'dados');   // cache invalidada
        $this->postJson("/api/logistica/produtos/{$id}/bloquear", [], $this->h)->assertJsonPath('dados.bloqueado', false);
    }

    #[Test]
    public function categorias_unicas_e_nao_eliminaveis_quando_usadas(): void
    {
        $cat = $this->postJson('/api/logistica/categorias-produtos', ['nome' => 'Materiais'], $this->h)->assertCreated()->json('dados.id');
        $this->postJson('/api/logistica/categorias-produtos', ['nome' => 'materiais'], $this->h)->assertStatus(422)->assertJsonPath('codigo', 'CATEGORIA_DUPLICADA');
        $prod = $this->postJson('/api/logistica/produtos', ['codigo' => 'M1', 'nome' => 'Tijolo', 'categoria_produto_id' => $cat], $this->h)->assertCreated()->json('dados.id');

        $this->deleteJson("/api/logistica/categorias-produtos/{$cat}", [], $this->h)->assertStatus(422)->assertJsonPath('codigo', 'REGISTO_EM_USO');
        $this->deleteJson("/api/logistica/produtos/{$prod}", [], $this->h)->assertOk();
        $this->assertSame(0, DB::table('catalogo_fornecedores')->where('produto_id', $prod)->whereNull('eliminado_em')->count());
        $this->deleteJson("/api/logistica/categorias-produtos/{$cat}", [], $this->h)->assertOk();
    }

    #[Test]
    public function permissoes_por_papel_e_isolamento(): void
    {
        $perfil = $this->criarPerfil(['_v2' => true, 'compras_forn_gerir' => true]);
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id]);
        $u->empresas()->attach($this->empresa->id);
        $comprador = $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];

        $this->postJson('/api/terceiros', ['papel' => 'CLIENTE', 'nome' => 'C', 'nif' => '1', 'codigo_conta' => '3111'], $comprador)->assertStatus(403);
        $this->postJson('/api/terceiros', ['papel' => 'FORNECEDOR', 'nome' => 'F', 'nif' => '2', 'codigo_conta' => '3211'], $comprador)->assertCreated();
        $this->getJson('/api/terceiros', $comprador)->assertOk();   // compras_forn_gerir torna o ecrã de fornecedores visível

        $id = $this->cliente()->json('dados.id');
        $outra = $this->criarEmpresa();
        $u->empresas()->attach($outra->id);
        $this->getJson("/api/terceiros/{$id}", $this->entrar($u) + ['X-Empresa-Id' => $outra->id])->assertNotFound();   // cache de acesso invalidada pelo attach
    }
}
