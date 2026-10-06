<?php

namespace Tests\Feature;

use App\Models\Armazem;
use App\Models\Empresa;
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

/** Logística parte 2 (ADR-043): stock e CMV nas vendas (FT/FR/GR baixam, GD e NC de devolução repõem) e guias de consumo. */
final class VendasStockTest extends TestCase
{
    private Empresa $empresa;

    private array $ids = [];

    private array $s;

    private string $hoje;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hoje = now()->toDateString();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000000']);
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['311' => 'Clientes', '3452' => 'IVA liquidado', '611' => 'Vendas', '2611' => 'Mercadorias', '7111' => 'CMV', '7511' => 'Consumos internos'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            $this->ids['cliente'] = Terceiro::create(['nome' => 'Cliente', 'nif' => '5000000001', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '311'])->id;
            $this->ids['p'] = Produto::create(['codigo' => 'P1', 'nome' => 'Produto', 'preco_unitario' => 1000, 'taxa_imposto' => 14, 'codigo_conta' => '611',
                'conta_iva_liquidado' => '3452', 'movimenta_stock' => true, 'conta_inventario' => '2611', 'conta_custo' => '7111'])->id;
            $this->ids['a'] = Armazem::create(['nome' => 'Central', 'predefinido' => true])->id;
            app(ServicoStock::class)->entrada($this->ids['p'], $this->ids['a'], '10', '600', $this->hoje, 'Stock inicial');
        });
        $this->s = $this->sessao(['vendas_faturacao_view', 'vendas_fat_emitir', 'vendas_fat_contabilizar', 'vendas_fat_descontab', 'vendas_fat_del', 'vendas_fat_unpost',
            'armazem_guias_view', 'armazem_guias_emitir', 'armazem_guias_anular', 'armazem_guias_contab', 'armazem_stock_view']);
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function stock(): string
    {
        return (string) app(ContextoEmpresa::class)->executarComo($this->empresa->id,
            fn () => StockArmazem::query()->where('armazem_id', $this->ids['a'])->where('produto_id', $this->ids['p'])->value('quantidade_stock'));
    }

    private function emitir(string $tipo, float $q, array $extra = []): array
    {
        return $this->postJson('/api/vendas/documentos', $extra + ['tipo_documento' => $tipo, 'cliente_id' => $this->ids['cliente'], 'data_emissao' => $this->hoje,
            'linhas' => [['produto_id' => $this->ids['p'], 'quantidade' => $q]]], $this->s)->assertCreated()->json('dados');
    }

    private function converter(int $id, string $destino, array $extra = []): array
    {
        return $this->postJson("/api/vendas/documentos/{$id}/converter", $extra + ['tipo_destino' => $destino], $this->s)->assertCreated()->json('dados');
    }

    private function lancamento(int $venda): array
    {
        $lan = $this->postJson("/api/vendas/documentos/{$venda}/contabilizar", [], $this->s)->assertOk()->json('dados.numero_lan_contabilizacao');

        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => LancamentoContabil::query()->where('numero_lan', $lan)->get()
            ->map(fn ($l) => "{$l->tipo_dc} {$l->codigo_conta} {$l->valor}")->sort()->values()->all());
    }

    #[Test]
    public function factura_guia_devolucao_e_nota_de_credito_movimentam_stock_e_cmv(): void
    {
        // FT directa: baixa 2 ao custo médio 600; CMV no próprio lançamento
        $ft = $this->emitir('FT', 2);
        $this->assertSame('8.000', $this->stock());
        $this->assertSame(['C 2611 1200.00', 'C 3452 280.00', 'C 611 2000.00', 'D 311 2280.00', 'D 7111 1200.00'], $this->lancamento($ft['id']));

        // encomenda → GR (baixa) → FT (não volta a baixar; a encomenda fica facturada)
        $ne = $this->emitir('NE', 5);
        $this->assertSame('8.000', $this->stock());
        $gr = $this->converter($ne['id'], 'GR');
        $this->assertSame('3.000', $this->stock());
        $this->assertSame(['C 2611 3000.00', 'D 7111 3000.00'], $this->lancamento($gr['id']));   // a GR só tem CMV
        $ft2 = $this->converter($gr['id'], 'FT');
        $this->assertSame('3.000', $this->stock());
        $this->assertSame(['C 3452 700.00', 'C 611 5000.00', 'D 311 5700.00'], $this->lancamento($ft2['id']));   // sem CMV: já saiu na GR
        $this->getJson("/api/vendas/documentos/{$ne['id']}", $this->s)->assertJsonPath('dados.estado', 'CONCLUIDO')->assertJsonPath('dados.linhas.0.quantidade_faturada', '5.000');
        $this->postJson("/api/vendas/documentos/{$ne['id']}/converter", ['tipo_destino' => 'FT'], $this->s)->assertStatus(422);   // não se factura duas vezes

        // GR → GD: repõe ao custo da GR
        $gr2 = $this->emitir('GR', 2);
        $this->assertSame('1.000', $this->stock());
        $gd = $this->converter($gr2['id'], 'GD');
        $this->assertSame('3.000', $this->stock());
        $this->assertSame(['C 7111 1200.00', 'D 2611 1200.00'], $this->lancamento($gd['id']));
        $this->getJson("/api/vendas/documentos/{$gr2['id']}", $this->s)->assertJsonPath('dados.estado', 'CONCLUIDO');

        // NC: só repõe quando é devolução de mercadoria
        $this->converter($ft['id'], 'NC', ['motivo_nota_credito' => 'Correcção de preço']);
        $this->assertSame('3.000', $this->stock());
        $ft3 = $this->emitir('FT', 1);
        $this->assertSame('2.000', $this->stock());
        $nc = $this->converter($ft3['id'], 'NC', ['motivo_nota_credito' => 'Devolução', 'devolucao_mercadoria' => true]);
        $this->assertSame('3.000', $this->stock());
        $this->assertSame(['C 311 1140.00', 'C 7111 600.00', 'D 2611 600.00', 'D 3452 140.00', 'D 611 1000.00'], $this->lancamento($nc['id']));

        // anular uma GR: repõe o stock; contabilizada exige estorno primeiro
        $gr3 = $this->emitir('GR', 1);
        $this->assertSame('2.000', $this->stock());
        $this->lancamento($gr3['id']);
        $this->postJson("/api/vendas/documentos/{$gr3['id']}/anular", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'DOCUMENTO_CONTABILIZADO');
        $this->postJson("/api/vendas/documentos/{$gr3['id']}/descontabilizar", ['motivo' => 'Guia emitida por engano'], $this->s)->assertOk();
        $this->postJson("/api/vendas/documentos/{$gr3['id']}/anular", [], $this->s)->assertOk()->assertJsonPath('dados.estado', 'ANULADO');
        $this->assertSame('3.000', $this->stock());

        // a venda não fica bloqueada pelo stock (paridade): o negativo aparece nas validações
        $this->emitir('FT', 10);
        $this->assertSame('-7.000', $this->stock());
        $custos = app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => ItemVenda::query()->where('venda_id', $ft['id'])->first());
        $this->assertSame(['600.000000', '2.000'], [(string) $custos->custo_unitario_kz, (string) $custos->quantidade_stock]);
    }

    #[Test]
    public function devolucao_sobre_stock_negativo_leva_a_diferenca_de_valorizacao_ao_cmv(): void
    {
        // decisão 15 (ADR-068), como na recepção de compra e na regularização de inventário
        $ft = $this->emitir('FT', 2);   // sai ao custo médio 600
        app(ContextoEmpresa::class)->executarComo($this->empresa->id,
            fn () => app(ServicoStock::class)->entrada($this->ids['p'], $this->ids['a'], '2', '1200', $this->hoje, 'Compra mais cara'));   // 8 × 600 + 2 × 1 200 → médio 720
        $this->emitir('FT', 14);   // a descoberto: −4 ao custo 720
        $this->assertSame('-4.000', $this->stock());

        // NC de devolução da 1.ª factura: entram 2 ao custo da factura (600) e cobrem 2 das unidades vendidas a 720 → −240 no CMV
        $nc = $this->converter($ft['id'], 'NC', ['motivo_nota_credito' => 'Devolução', 'devolucao_mercadoria' => true]);
        $this->assertSame('-2.000', $this->stock());
        $item = app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => ItemVenda::query()->where('venda_id', $nc['id'])->first());
        $this->assertSame('-240.00', (string) $item->acerto_cmv_kz);
        $this->assertSame(['C 311 2280.00', 'C 7111 1440.00', 'D 2611 1440.00', 'D 3452 280.00', 'D 611 2000.00'], $this->lancamento($nc['id']));

        // GD sobre stock negativo: a GR saiu a 600; entretanto uma entrada a 1 500 passou o médio para 1 500 → −900 no CMV
        $gr = $this->emitir('GR', 1);
        app(ContextoEmpresa::class)->executarComo($this->empresa->id,
            fn () => app(ServicoStock::class)->entrada($this->ids['p'], $this->ids['a'], '1', '1500', $this->hoje, 'Compra'));
        $this->assertSame('-2.000', $this->stock());
        $gd = $this->converter($gr['id'], 'GD');
        $this->assertSame('-1.000', $this->stock());
        $this->assertSame(['C 7111 1500.00', 'D 2611 1500.00'], $this->lancamento($gd['id']));

        // a devolução sobre stock positivo não tem acerto (ver o teste anterior: NC/GD só com o custo da origem)
        $this->assertNull(app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => ItemVenda::query()->where('venda_id', $ft['id'])->value('acerto_cmv_kz')));
    }

    #[Test]
    public function guia_de_consumo_com_numeracao_contabilizacao_e_anulacao(): void
    {
        $g = $this->postJson('/api/logistica/guias-saida', ['armazem_id' => $this->ids['a'], 'data' => $this->hoje, 'area_rececao' => 'Oficina',
            'linhas' => [['produto_id' => $this->ids['p'], 'quantidade' => 1]]], $this->s)->assertCreated()->assertJsonPath('dados.numero_documento', 'GE '.now()->format('Y').'/0001')->json('dados');
        $this->assertSame('9.000', $this->stock());
        $this->postJson('/api/logistica/guias-saida', ['armazem_id' => $this->ids['a'], 'data' => $this->hoje, 'area_rececao' => 'Oficina',
            'linhas' => [['produto_id' => $this->ids['p'], 'quantidade' => 50]]], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'STOCK_INSUFICIENTE');   // o consumo não fica negativo
        $this->postJson("/api/logistica/guias-saida/{$g['id']}/contabilizar", [], $this->s)->assertOk()->assertJsonPath('dados.contabilizado', true);
        $this->postJson("/api/logistica/guias-saida/{$g['id']}/contabilizar", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'JA_CONTABILIZADO');
        $this->postJson("/api/logistica/guias-saida/{$g['id']}/anular", ['motivo' => 'Emitida por engano'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'DOCUMENTO_CONTABILIZADO');
        $this->postJson("/api/logistica/guias-saida/{$g['id']}/descontabilizar", ['motivo' => 'Emitida por engano'], $this->s)->assertOk();
        $this->postJson("/api/logistica/guias-saida/{$g['id']}/anular", ['motivo' => 'Emitida por engano'], $this->s)->assertOk()->assertJsonPath('dados.estado', 'ANULADA');
        $this->assertSame('10.000', $this->stock());
        $this->getJson("/api/logistica/guias-saida/{$g['id']}", $this->s)->assertJsonCount(1, 'dados.linhas')->assertJsonPath('dados.linhas.0.valor_kz', '600.00');
    }
}
