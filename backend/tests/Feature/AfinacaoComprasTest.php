<?php

namespace Tests\Feature;

use App\Models\Armazem;
use App\Models\ContratoFornecedor;
use App\Models\Empresa;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\Terceiro;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Afinação da Fase 5 (ADR-064) — Compras: paginação uniforme, nomes nas respostas e filtros novos. */
final class AfinacaoComprasTest extends TestCase
{
    private Empresa $empresa;

    private Terceiro $fornecedor;

    private Produto $artigo;

    private array $s;

    private array $aprovador;

    private string $hoje;

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
        });
        $this->s = $this->sessao(['compras_pedidos_view', 'compras_ped_criar', 'compras_prospeccao_view', 'compras_new_proposal', 'compras_evaluate',
            'compras_adjudicate', 'compras_encomendas_view', 'compras_rececoes_view', 'compras_rec_registar', 'compras_faturacao_view', 'compras_fact_registar',
            'compras_contratos_view']);
        $this->aprovador = $this->sessao(['compras_pedidos_view', 'compras_ped_aprovar']);
    }

    private function sessao(array $permissoes): array
    {
        $perfil = $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true));
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function encomenda(): int
    {
        $p = $this->postJson('/api/compras/pedidos', ['nome_requerente' => 'Obra', 'data' => $this->hoje,
            'linhas' => [['produto_id' => $this->artigo->id, 'quantidade' => 10, 'preco_unitario' => 100]]], $this->s)->assertCreated()->json('dados.id');
        $this->postJson("/api/compras/pedidos/{$p}/decidir", ['decisao' => 'APROVAR'], $this->aprovador)->assertOk();
        $linha = $this->getJson("/api/compras/pedidos/{$p}", $this->s)->json('dados.linhas.0.id');
        $c = $this->postJson('/api/compras/propostas', ['pedido_compra_id' => $p, 'fornecedor_id' => $this->fornecedor->id, 'referencia' => 'PROP-1',
            'data' => $this->hoje, 'linhas' => [['item_pedido_id' => $linha, 'preco_unitario' => 90, 'taxa_imposto' => 14]]], $this->s)->assertCreated()->json('dados.id');
        $this->postJson("/api/compras/propostas/{$c}/propor", [], $this->s)->assertOk();

        return $this->postJson("/api/compras/propostas/{$c}/adjudicar", ['data' => $this->hoje], $this->s)->assertCreated()->json('dados.id');
    }

    #[Test]
    public function listagens_paginadas_com_nomes_e_filtros(): void
    {
        $enc = $this->encomenda();
        $linhaEnc = $this->getJson("/api/compras/encomendas/{$enc}", $this->s)->assertOk()
            ->assertJsonPath('dados.fornecedor.nome', 'Fornecedor Teste')->assertJsonPath('dados.fornecedor.nif', '5000000011')
            ->assertJsonPath('dados.linhas.0.produto.codigo', 'A1')->assertJsonPath('dados.linhas.0.produto.nome', 'Cimento')
            ->json('dados.linhas.0.id');
        $rec = $this->postJson("/api/compras/encomendas/{$enc}/rececoes", ['numero_entrega' => 'GR-77', 'data' => $this->hoje,
            'linhas' => [['item_encomenda_id' => $linhaEnc, 'quantidade' => 4]]], $this->s)->assertCreated()->json('dados.id');
        $this->getJson("/api/compras/rececoes/{$rec}", $this->s)->assertOk()->assertJsonPath('dados.linhas.0.produto.nome', 'Cimento');
        $fat = $this->postJson("/api/compras/encomendas/{$enc}/faturas", ['numero_fatura' => 'FT-2026/123', 'data' => $this->hoje,
            'linhas' => [['item_encomenda_id' => $linhaEnc, 'quantidade' => 4]]], $this->s)->assertCreated()->json('dados.id');
        $this->getJson("/api/compras/faturas/{$fat}", $this->s)->assertJsonPath('dados.fornecedor.id', $this->fornecedor->id)
            ->assertJsonPath('dados.linhas.0.produto.id', $this->artigo->id);

        // paginação uniforme: dados = lista; metadados.paginacao
        foreach (['pedidos', 'propostas', 'encomendas', 'rececoes', 'faturas'] as $r) {
            $this->getJson("/api/compras/{$r}?por_pagina=1", $this->s)->assertOk()->assertJsonCount(1, 'dados')
                ->assertJsonPath('metadados.paginacao.pagina_atual', 1)->assertJsonPath('metadados.paginacao.por_pagina', 1)
                ->assertJsonPath('metadados.paginacao.total', 1);
        }
        $this->getJson('/api/compras/propostas', $this->s)->assertJsonPath('dados.0.fornecedor.nome', 'Fornecedor Teste');
        $this->getJson('/api/compras/encomendas', $this->s)->assertJsonPath('dados.0.fornecedor.nif', '5000000011');

        // filtros novos
        $this->getJson("/api/compras/faturas?encomenda_compra_id={$enc}", $this->s)->assertJsonCount(1, 'dados')->assertJsonPath('dados.0.fornecedor.nome', 'Fornecedor Teste');
        $this->getJson('/api/compras/faturas?encomenda_compra_id=999999', $this->s)->assertJsonCount(0, 'dados');
        $this->getJson('/api/compras/faturas?pesquisa=2026/12', $this->s)->assertJsonCount(1, 'dados');
        $this->getJson('/api/compras/faturas?pesquisa=%25', $this->s)->assertJsonCount(0, 'dados');   // o % do utilizador é literal
        $this->getJson('/api/compras/rececoes?pesquisa=gr-77', $this->s)->assertJsonCount(1, 'dados');
        $this->getJson('/api/compras/rececoes?pesquisa=XYZ', $this->s)->assertJsonCount(0, 'dados');
    }

    #[Test]
    public function contratos_paginados_com_fornecedor_e_sem_n_mais_1(): void
    {
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (range(1, 3) as $i) {
                $f = Terceiro::create(['nome' => "Fornecedor {$i}", 'nif' => "50000001{$i}0", 'tipo' => Terceiro::FORNECEDOR, 'codigo_conta' => '3211']);
                ContratoFornecedor::create(['fornecedor_id' => $f->id, 'referencia' => "CT-{$i}", 'data_inicio' => $this->hoje, 'valor_total' => 1000, 'estado' => 'ATIVO']);
            }
        });
        $this->getJson('/api/compras/contratos?por_pagina=2', $this->s)->assertOk()->assertJsonCount(2, 'dados')
            ->assertJsonPath('metadados.paginacao.total', 3)->assertJsonPath('metadados.paginacao.ultima_pagina', 2)
            ->assertJsonStructure(['dados' => [['fornecedor' => ['id', 'nome', 'nif']]]]);
        $this->getJson('/api/compras/contratos?pesquisa=CT-2', $this->s)->assertJsonCount(1, 'dados')->assertJsonPath('dados.0.fornecedor.nome', 'Fornecedor 2');

        // eager loading: o número de consultas não cresce com o número de linhas
        DB::enableQueryLog();
        $this->getJson('/api/compras/contratos?por_pagina=1', $this->s)->assertOk();
        $umaLinha = count(DB::getQueryLog());
        DB::flushQueryLog();
        $this->getJson('/api/compras/contratos?por_pagina=3', $this->s)->assertOk();
        $tresLinhas = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($umaLinha, $tresLinhas);
    }
}
