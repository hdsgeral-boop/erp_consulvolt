<?php

namespace Tests\Feature;

use App\Models\Armazem;
use App\Models\CentroCusto;
use App\Models\ContratoFornecedor;
use App\Models\Empresa;
use App\Models\FaturaCompra;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\Terceiro;
use App\Models\UnidadeNegocio;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Paridade das listas de Compras com o legado (RELATORIO.md, secção 5): colunas UNº/CC, Itens/Qtd, Satisfação (E/F),
 * contrato e encomendas associadas, fluxo documental, «Associar» contrato na encomenda e «Editar Fatura».
 */
final class ParidadeComprasTest extends TestCase
{
    private Empresa $empresa;

    private Terceiro $fornecedor;

    private Produto $artigo;

    private array $s;

    private array $aprovador;

    private string $hoje;

    private int $un;

    private int $cc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hoje = now()->toDateString();
        $this->empresa = $this->criarEmpresa();
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['3211' => 'Fornecedores', '3281' => 'Compras em trânsito', '211' => 'Compras', '261' => 'Mercadorias', '3451' => 'IVA dedutível'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            $this->fornecedor = Terceiro::create(['nome' => 'Fornecedor Teste', 'nif' => '5000000011', 'tipo' => Terceiro::FORNECEDOR,
                'codigo_conta' => '3211', 'conta_compra_transitoria' => '3281']);
            $this->artigo = Produto::create(['codigo' => 'A1', 'nome' => 'Cimento', 'preco_unitario' => 150, 'taxa_imposto' => 14, 'movimenta_stock' => true,
                'conta_compra' => '211', 'conta_inventario' => '261', 'conta_iva_dedutivel' => '3451']);
            Armazem::create(['nome' => 'Central', 'predefinido' => true]);
            $this->un = UnidadeNegocio::create(['codigo' => 'UN-T', 'nome' => 'Unidade teste'])->id;
            $this->cc = CentroCusto::create(['codigo' => 'CC-T', 'descricao' => 'Centro teste'])->id;
        });
        $this->s = $this->sessao(['compras_pedidos_view', 'compras_ped_criar', 'compras_prospeccao_view', 'compras_new_proposal', 'compras_evaluate',
            'compras_adjudicate', 'compras_encomendas_view', 'compras_enc_criar', 'compras_rececoes_view', 'compras_rec_registar', 'compras_faturacao_view',
            'compras_fact_registar', 'compras_contratos_view', 'compras_contratos_gerir']);
        $this->aprovador = $this->sessao(['compras_pedidos_view', 'compras_ped_aprovar']);
    }

    private function sessao(array $permissoes): array
    {
        $perfil = $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true));
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function pedido(int $qtd = 10): int
    {
        return $this->postJson('/api/compras/pedidos', ['nome_requerente' => 'Obra', 'data' => $this->hoje, 'unidade_negocio_id' => $this->un, 'centro_custo_id' => $this->cc,
            'linhas' => [['produto_id' => $this->artigo->id, 'quantidade' => $qtd, 'preco_unitario' => 100]]], $this->s)->assertCreated()->json('dados.id');
    }

    /** @return array{0: int, 1: int, 2: int} pedido, proposta, encomenda */
    private function encomenda(): array
    {
        $p = $this->pedido();
        $this->postJson("/api/compras/pedidos/{$p}/decidir", ['decisao' => 'APROVAR'], $this->aprovador)->assertOk();
        $linha = $this->getJson("/api/compras/pedidos/{$p}", $this->s)->json('dados.linhas.0.id');
        $c = $this->postJson('/api/compras/propostas', ['pedido_compra_id' => $p, 'fornecedor_id' => $this->fornecedor->id, 'referencia' => 'PROP-1',
            'data' => $this->hoje, 'linhas' => [['item_pedido_id' => $linha, 'preco_unitario' => 90, 'taxa_imposto' => 14]]], $this->s)->assertCreated()->json('dados.id');
        $this->postJson("/api/compras/propostas/{$c}/propor", [], $this->s)->assertOk();
        $e = $this->postJson("/api/compras/propostas/{$c}/adjudicar", ['data' => $this->hoje], $this->s)->assertCreated()->json('dados.id');

        return [$p, $c, $e];
    }

    #[Test]
    public function listas_trazem_as_colunas_do_legado(): void
    {
        $pendente = $this->pedido(3);
        [$p, $c, $e] = $this->encomenda();
        $this->getJson('/api/compras/pedidos', $this->s)->assertOk()
            ->assertJsonPath('dados.0.id', $p)->assertJsonPath('dados.0.itens_resumo', 'Cimento (10)')->assertJsonPath('dados.0.itens_quantidade', '10')
            ->assertJsonPath('dados.0.unidade_negocio_codigo', 'UN-T')->assertJsonPath('dados.0.centro_custo_codigo', 'CC-T')
            ->assertJsonPath('dados.1.id', $pendente)->assertJsonPath('dados.1.pode_decidir', false);   // quem criou não aprova
        $this->getJson('/api/compras/pedidos', $this->aprovador)->assertJsonPath('dados.1.pode_decidir', true);
        $this->getJson('/api/compras/propostas', $this->s)->assertJsonPath('dados.0.unidade_negocio_codigo', 'UN-T');

        $linhaEnc = $this->getJson("/api/compras/encomendas/{$e}", $this->s)->json('dados.linhas.0.id');
        $this->postJson("/api/compras/encomendas/{$e}/rececoes", ['numero_entrega' => 'GR-1', 'data' => $this->hoje,
            'linhas' => [['item_encomenda_id' => $linhaEnc, 'quantidade' => 4]]], $this->s)->assertCreated();
        $this->postJson("/api/compras/encomendas/{$e}/faturas", ['numero_fatura' => 'FT-1', 'data' => $this->hoje,
            'linhas' => [['item_encomenda_id' => $linhaEnc, 'quantidade' => 2]]], $this->s)->assertCreated();
        $enc = $this->getJson('/api/compras/encomendas', $this->s)->assertOk()->json('dados.0');
        $this->assertEquals(40, $enc['percentagem_entregue']);
        $this->assertEquals(20, $enc['percentagem_faturada']);
        $this->assertNull($enc['contrato']);
        $this->assertSame('CC-T', $enc['centro_custo_codigo']);

        $numero = $enc['numero_encomenda'];
        $this->getJson('/api/compras/rececoes', $this->s)->assertJsonPath('dados.0.numero_encomenda', $numero)->assertJsonPath('dados.0.fornecedor.nome', 'Fornecedor Teste');
        $this->getJson('/api/compras/faturas', $this->s)->assertJsonPath('dados.0.numero_encomenda', $numero);
    }

    #[Test]
    public function associa_a_encomenda_a_um_contrato_a_partir_da_lista(): void
    {
        [, , $e] = $this->encomenda();
        $contrato = app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => ContratoFornecedor::create(['fornecedor_id' => $this->fornecedor->id,
            'referencia' => 'CT-9', 'data_inicio' => $this->hoje, 'valor_total' => 1800, 'estado' => 'ATIVO'])->id);

        $this->postJson("/api/compras/encomendas/{$e}/contrato", ['contrato_fornecedor_id' => $contrato], $this->s)->assertOk()
            ->assertJsonPath('dados.consumo.encomendado', '900.00');
        $this->getJson('/api/compras/encomendas', $this->s)->assertJsonPath('dados.0.contrato.referencia', 'CT-9');
        $this->getJson('/api/compras/contratos', $this->s)->assertOk()->assertJsonPath('dados.0.encomendas.0.id', $e)
            ->assertJsonPath('dados.0.total_encomendado', '900.00')->assertJsonPath('dados.0.percentagem_encomendada', 50);

        $this->postJson("/api/compras/encomendas/{$e}/contrato", ['contrato_fornecedor_id' => null], $this->s)->assertOk();
        $this->getJson('/api/compras/encomendas', $this->s)->assertJsonPath('dados.0.contrato', null);
        // contrato de outra empresa / inexistente: recusado na validação
        $this->postJson("/api/compras/encomendas/{$e}/contrato", ['contrato_fornecedor_id' => 999999], $this->s)->assertStatus(422);
        // sem permissão
        $this->postJson("/api/compras/encomendas/{$e}/contrato", ['contrato_fornecedor_id' => $contrato], $this->aprovador)->assertForbidden();
    }

    #[Test]
    public function fluxo_documental_mostra_a_cadeia_a_partir_de_qualquer_documento(): void
    {
        [$p, $c, $e] = $this->encomenda();
        $linhaEnc = $this->getJson("/api/compras/encomendas/{$e}", $this->s)->json('dados.linhas.0.id');
        $rec = $this->postJson("/api/compras/encomendas/{$e}/rececoes", ['numero_entrega' => 'GR-2', 'data' => $this->hoje,
            'linhas' => [['item_encomenda_id' => $linhaEnc, 'quantidade' => 10]]], $this->s)->assertCreated()->json('dados.id');
        $fat = $this->postJson("/api/compras/encomendas/{$e}/faturas", ['numero_fatura' => 'FT-2', 'data' => $this->hoje,
            'linhas' => [['item_encomenda_id' => $linhaEnc, 'quantidade' => 10]]], $this->s)->assertCreated()->json('dados.id');

        foreach (['pedido' => $p, 'proposta' => $c, 'encomenda' => $e, 'rececao' => $rec, 'fatura' => $fat] as $tipo => $id) {
            $this->getJson("/api/compras/fluxo-documental/{$tipo}/{$id}", $this->s)->assertOk()
                ->assertJsonPath('dados.pedido.id', $p)->assertJsonPath('dados.propostas.0.id', $c)->assertJsonPath('dados.encomenda.id', $e)
                ->assertJsonPath('dados.rececoes.0.id', $rec)->assertJsonPath('dados.faturas.0.id', $fat)
                ->assertJsonPath('dados.encomenda.fornecedor.nome', 'Fornecedor Teste');
        }
        $this->getJson("/api/compras/fluxo-documental/outro/{$p}", $this->s)->assertNotFound();
        $this->getJson('/api/compras/fluxo-documental/pedido/999999', $this->s)->assertNotFound();
        $semCompras = $this->sessao(['vendas_clientes_view']);
        $this->getJson("/api/compras/fluxo-documental/pedido/{$p}", $semCompras)->assertForbidden();
    }

    #[Test]
    public function editar_fatura_so_por_contabilizar_e_com_numero_unico(): void
    {
        [, , $e] = $this->encomenda();
        $linhaEnc = $this->getJson("/api/compras/encomendas/{$e}", $this->s)->json('dados.linhas.0.id');
        $f1 = $this->postJson("/api/compras/encomendas/{$e}/faturas", ['numero_fatura' => 'FT-A', 'data' => $this->hoje,
            'linhas' => [['item_encomenda_id' => $linhaEnc, 'quantidade' => 2]]], $this->s)->assertCreated()->json('dados.id');
        $this->postJson("/api/compras/encomendas/{$e}/faturas", ['numero_fatura' => 'FT-B', 'data' => $this->hoje,
            'linhas' => [['item_encomenda_id' => $linhaEnc, 'quantidade' => 2]]], $this->s)->assertCreated();

        $this->putJson("/api/compras/faturas/{$f1}", ['numero_fatura' => 'FT-A1', 'data' => $this->hoje, 'data_vencimento' => now()->addDays(30)->toDateString()], $this->s)
            ->assertOk()->assertJsonPath('dados.numero_fatura', 'FT-A1');
        $this->putJson("/api/compras/faturas/{$f1}", ['numero_fatura' => 'ft-b', 'data' => $this->hoje], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'FATURA_DUPLICADA');
        $this->putJson("/api/compras/faturas/{$f1}", ['numero_fatura' => 'FT-A2', 'data' => $this->hoje, 'data_vencimento' => now()->subDays(3)->toDateString()], $this->s)->assertStatus(422);

        app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => FaturaCompra::query()->whereKey($f1)->update(['contabilizado' => true]));
        $this->putJson("/api/compras/faturas/{$f1}", ['numero_fatura' => 'FT-A3', 'data' => $this->hoje], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'FATURA_CONTABILIZADA');
        $this->putJson("/api/compras/faturas/{$f1}", ['numero_fatura' => 'FT-A3', 'data' => $this->hoje], $this->aprovador)->assertForbidden();
    }

    #[Test]
    public function colunas_novas_sem_n_mais_1(): void
    {
        foreach (range(1, 3) as $i) {
            $this->pedido($i);
        }
        // Aquecimento: a 1.ª chamada pode preencher caches (permissões, configuração) e distorcer a contagem.
        $this->getJson('/api/compras/pedidos?por_pagina=3', $this->s)->assertOk();
        $this->getJson('/api/compras/pedidos?por_pagina=1', $this->s)->assertOk(); // os dois tamanhos aquecidos: na suite completa uma cache podia encher entre as medições
        DB::enableQueryLog();
        $this->getJson('/api/compras/pedidos?por_pagina=1', $this->s)->assertOk();
        $uma = count(DB::getQueryLog());
        DB::flushQueryLog();
        $this->getJson('/api/compras/pedidos?por_pagina=3', $this->s)->assertOk()->assertJsonCount(3, 'dados');
        $tres = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($uma, $tres);
    }
}
