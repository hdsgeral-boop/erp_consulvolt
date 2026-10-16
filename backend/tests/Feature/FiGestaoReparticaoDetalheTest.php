<?php

namespace Tests\Feature;

use App\Models\CentroCusto;
use App\Models\CriterioReparticaoUN;
use App\Models\Empresa;
use App\Models\EncomendaCompra;
use App\Models\FaturaCompra;
use App\Models\ItemCompra;
use App\Models\ItemVenda;
use App\Models\PedidoCompra;
use App\Models\Produto;
use App\Models\ReciboVenda;
use App\Models\Terceiro;
use App\Models\UnidadeNegocio;
use App\Models\Venda;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fecho da Versão Actual (ADR-071) — repartição por unidades de negócio nos ecrãs de detalhe:
 *   - R5: as APIs de detalhe de Vendas e Compras devolvem a UN/CC de cada linha (id + nome) e o bloco só de leitura da
 *     alocação (`alocacao_un`, com percentagens e parcelas); a factura de compra sem alocação mostra a herdada da encomenda;
 *   - R2: a alocação altera-se com PUT /reparticao/alocacoes/{tipo}/{id}, recusada em documentos contabilizados ou anulados.
 * Dados fictícios.
 */
final class FiGestaoReparticaoDetalheTest extends TestCase
{
    private Empresa $empresa;

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417200037']);
        $this->naEmpresa(function () {
            $this->ids['un1'] = UnidadeNegocio::create(['codigo' => 'UN1', 'nome' => 'Comercial', 'estado' => 'ATIVO'])->id;
            $this->ids['un2'] = UnidadeNegocio::create(['codigo' => 'UN2', 'nome' => 'Industrial', 'estado' => 'ATIVO'])->id;
            $this->ids['cc'] = CentroCusto::create(['codigo' => 'CC1', 'descricao' => 'Obra fictícia'])->id;
            $this->ids['crit'] = CriterioReparticaoUN::create(['codigo' => 'PCT', 'nome' => 'Percentagem fixa', 'criterio' => 'PERCENTAGEM', 'ativo' => true])->id;
            $this->ids['prod'] = Produto::create(['codigo' => 'ART-1', 'nome' => 'Artigo fictício'])->id;
            $this->ids['cli'] = Terceiro::create(['nome' => 'Cliente fictício', 'tipo' => 'CLIENTE'])->id;
            $this->ids['forn'] = Terceiro::create(['nome' => 'Fornecedor fictício', 'tipo' => 'FORNECEDOR'])->id;
        });
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

    private function alocacao(int $p1 = 60, int $p2 = 40): array
    {
        return ['criterio_id' => $this->ids['crit'], 'unidades' => [['unidade_negocio_id' => $this->ids['un1'], 'peso' => $p1], ['unidade_negocio_id' => $this->ids['un2'], 'peso' => $p2]]];
    }

    #[Test]
    public function detalhe_de_compras_devolve_uncc_por_linha_alocacao_e_a_herdada_da_encomenda(): void
    {
        $this->naEmpresa(function () {
            $pc = PedidoCompra::create(['nome_requerente' => 'Requerente fictício', 'estado' => 'APROVADO', 'unidades_alocadas' => $this->alocacao()]);
            $this->ids['pc'] = $pc->id;
            ItemCompra::create(['tipo_documento_origem' => 'PEDIDO', 'pedido_compra_id' => $pc->id, 'produto_id' => $this->ids['prod'], 'quantidade' => 1, 'preco_unitario' => 100, 'total' => 100,
                'unidade_negocio_id' => $this->ids['un2'], 'centro_custo_id' => $this->ids['cc']]);
            $enc = EncomendaCompra::create(['pedido_compra_id' => $pc->id, 'fornecedor_id' => $this->ids['forn'], 'estado' => 'EM_PROCESSAMENTO', 'montante_total' => 1000,
                'unidades_alocadas' => $this->alocacao(75, 25)]);
            $this->ids['enc'] = $enc->id;
            ItemCompra::create(['tipo_documento_origem' => 'ENCOMENDA', 'encomenda_compra_id' => $enc->id, 'produto_id' => $this->ids['prod'], 'quantidade' => 10, 'preco_unitario' => 100, 'total' => 1000]);
            $f = FaturaCompra::create(['numero_fatura' => 'FC-1', 'data' => '2026-10-01', 'estado' => 'PENDENTE', 'encomenda_compra_id' => $enc->id, 'fornecedor_id' => $this->ids['forn'],
                'montante_total' => 1000, 'contabilizado' => false]);
            $this->ids['fat'] = $f->id;
            ItemCompra::create(['tipo_documento_origem' => 'FATURA', 'fatura_compra_id' => $f->id, 'produto_id' => $this->ids['prod'], 'quantidade' => 10, 'preco_unitario' => 100, 'total' => 1000,
                'unidade_negocio_id' => $this->ids['un1']]);
        });
        $s = $this->sessao(['compras_pedidos_view', 'compras_encomendas_view', 'compras_faturacao_view', 'compras_fact_registar', 'compras_enc_criar']);

        $pc = $this->getJson("/api/compras/pedidos/{$this->ids['pc']}", $s)->assertOk()->json('dados');
        $this->assertSame('Industrial', $pc['linhas'][0]['unidade_negocio']['nome']);
        $this->assertSame('CC1', $pc['linhas'][0]['centro_custo']['codigo']);
        $this->assertSame('Percentagem fixa', $pc['alocacao_un']['criterio']['nome']);
        $this->assertSame(['60.0000', '40.0000'], array_column($pc['alocacao_un']['linhas'], 'percentagem'));

        $enc = $this->getJson("/api/compras/encomendas/{$this->ids['enc']}", $s)->assertOk()->json('dados');
        $this->assertNull($enc['linhas'][0]['unidade_negocio']);                      // «— cabeçalho —»
        $this->assertSame(['750.00', '250.00'], array_column($enc['alocacao_un']['linhas'], 'valor'));

        // factura sem alocação própria: mostra a da encomenda (é a que a contabilização usa)
        $fat = $this->getJson("/api/compras/faturas/{$this->ids['fat']}", $s)->assertOk()->json('dados');
        $this->assertSame('ENCOMENDA', $fat['alocacao_un_origem']);
        $this->assertSame(['750.00', '250.00'], array_column($fat['alocacao_un']['linhas'], 'valor'));
        $this->assertSame($this->ids['un1'], $fat['linhas'][0]['unidade_negocio']['id']);

        // R2: gravar a alocação na factura; depois de contabilizada é recusado
        $this->putJson("/api/reparticao/alocacoes/fatura_compra/{$this->ids['fat']}", ['unidades_alocadas' => $this->alocacao(50, 50)], $s)->assertOk();
        $fat = $this->getJson("/api/compras/faturas/{$this->ids['fat']}", $s)->json('dados');
        $this->assertNull($fat['alocacao_un_origem']);
        $this->assertSame(['500.00', '500.00'], array_column($fat['alocacao_un']['linhas'], 'valor'));
        $this->naEmpresa(fn () => FaturaCompra::query()->whereKey($this->ids['fat'])->update(['contabilizado' => true]));
        $this->putJson("/api/reparticao/alocacoes/fatura_compra/{$this->ids['fat']}", ['unidades_alocadas' => null], $s)
            ->assertStatus(422)->assertJsonPath('codigo', 'DOCUMENTO_CONTABILIZADO');
        $this->naEmpresa(fn () => EncomendaCompra::query()->whereKey($this->ids['enc'])->update(['estado' => 'ANULADA']));
        $this->putJson("/api/reparticao/alocacoes/encomenda_compra/{$this->ids['enc']}", ['unidades_alocadas' => null], $s)
            ->assertStatus(422)->assertJsonPath('codigo', 'DOCUMENTO_ANULADO');
        // só de leitura não altera
        $this->putJson("/api/reparticao/alocacoes/pedido_compra/{$this->ids['pc']}", ['unidades_alocadas' => null], $this->sessao(['compras_pedidos_view']))->assertForbidden();
    }

    #[Test]
    public function detalhe_de_vendas_e_recibos_devolve_uncc_e_alocacao(): void
    {
        $this->naEmpresa(function () {
            $v = Venda::create(['tipo_documento' => 'FT', 'numero_documento' => 'FT T/1', 'data_emissao' => '2026-10-01 10:00:00', 'cliente_id' => $this->ids['cli'],
                'total_liquido' => 200, 'total_imposto' => 28, 'total_bruto' => 228, 'contabilizado' => false, 'unidades_alocadas' => $this->alocacao(1, 3)]);
            $this->ids['venda'] = $v->id;
            ItemVenda::create(['venda_id' => $v->id, 'produto_id' => $this->ids['prod'], 'quantidade' => 1, 'preco_unitario' => 100, 'total_linha' => 100, 'total' => 114,
                'unidade_negocio_id' => $this->ids['un1'], 'centro_custo_id' => $this->ids['cc']]);
            ItemVenda::create(['venda_id' => $v->id, 'produto_id' => $this->ids['prod'], 'quantidade' => 1, 'preco_unitario' => 100, 'total_linha' => 100, 'total' => 114]);
            $this->ids['recibo'] = ReciboVenda::create(['numero_recibo' => 'RC T/1', 'data' => '2026-10-02', 'cliente_id' => $this->ids['cli'], 'montante_total' => 228,
                'contabilizado' => false])->id;
        });
        $s = $this->sessao(['vendas_faturacao_view', 'vendas_fat_emitir', 'vendas_recibos']);

        $d = $this->getJson("/api/vendas/documentos/{$this->ids['venda']}", $s)->assertOk()->json('dados');
        $this->assertSame(['UN1', null], array_map(fn ($l) => $l['unidade_negocio']['codigo'] ?? null, $d['linhas']));
        $this->assertSame('Obra fictícia', $d['linhas'][0]['centro_custo']['descricao']);
        $this->assertSame($this->ids['crit'], $d['unidades_alocadas']['criterio_id']);
        $this->assertSame(['50.00', '150.00'], array_column($d['alocacao_un']['linhas'], 'valor'));   // 1:3 de 200 (sem IVA)

        $r = $this->getJson("/api/vendas/recibos/{$this->ids['recibo']}", $s)->assertOk()->json('dados');
        $this->assertNull($r['alocacao_un']);
        $this->putJson("/api/reparticao/alocacoes/recibo_venda/{$this->ids['recibo']}", ['unidades_alocadas' => $this->alocacao()], $s)->assertOk();
        $r = $this->getJson("/api/vendas/recibos/{$this->ids['recibo']}", $s)->json('dados');
        $this->assertSame(['136.80', '91.20'], array_column($r['alocacao_un']['linhas'], 'valor'));

        // documento anulado não muda
        $this->naEmpresa(fn () => Venda::query()->whereKey($this->ids['venda'])->update(['estado' => 'ANULADO']));
        $this->putJson("/api/reparticao/alocacoes/venda/{$this->ids['venda']}", ['unidades_alocadas' => null], $s)->assertStatus(422)->assertJsonPath('codigo', 'DOCUMENTO_ANULADO');
    }
}
