<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\LancamentoContabil;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\TaxaCambio;
use App\Models\Terceiro;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Tesouraria (parte 3): liquidação de documentos em moeda estrangeira com valor histórico e diferenças de câmbio. */
final class TesourariaMultimoedaTest extends TestCase
{
    private Empresa $empresa;

    private Terceiro $cliente;

    private Terceiro $fornecedor;

    private array $s;

    private string $hoje;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hoje = now()->toDateString();
        $this->empresa = $this->criarEmpresa();
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['3111' => 'Clientes', '3211' => 'Fornecedores', '3452' => 'IVA liquidado', '3451' => 'IVA dedutível', '4311' => 'Banco Kz',
                '611' => 'Vendas', '752' => 'Serviços', '6881' => 'Diferenças de câmbio favoráveis', '7881' => 'Diferenças de câmbio desfavoráveis'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            PlanoConta::create(['codigo' => '4312', 'descricao' => 'Banco USD', 'tipo' => 'M', 'codigo_moeda' => 'USD']);
            PlanoConta::create(['codigo' => '4313', 'descricao' => 'Banco EUR', 'tipo' => 'M', 'codigo_moeda' => 'EUR']);
            $this->cliente = Terceiro::create(['nome' => 'Cliente USD', 'nif' => '5000000001', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '3111']);
            $this->fornecedor = Terceiro::create(['nome' => 'Fornecedor USD', 'nif' => '5000000002', 'tipo' => Terceiro::FORNECEDOR, 'codigo_conta' => '3211']);
            Produto::create(['codigo' => 'P1', 'nome' => 'Produto', 'preco_unitario' => 1000, 'taxa_imposto' => 14, 'codigo_conta' => '611', 'conta_iva_liquidado' => '3452']);
            Produto::create(['codigo' => 'S1', 'nome' => 'Serviço', 'taxa_imposto' => 14, 'movimenta_stock' => false, 'conta_custo' => '752', 'conta_iva_dedutivel' => '3451']);
            TaxaCambio::create(['empresa_id' => null, 'codigo_moeda' => 'USD', 'data_taxa' => now()->subDays(3)->toDateString(), 'taxa' => '900.5']);
            TaxaCambio::create(['empresa_id' => null, 'codigo_moeda' => 'EUR', 'data_taxa' => now()->subDays(3)->toDateString(), 'taxa' => '1100']);
        });
        $this->s = $this->sessao(['vendas_faturacao_view', 'vendas_fat_emitir', 'vendas_alterar_preco', 'cambio_manual_fora_tolerancia', 'vendas_fat_contabilizar', 'compras_faturacao_view', 'compras_fact_registar',
            'compras_fact_contabilizar', 'teso_gestao_pagamentos_view', 'teso_doc_emitir', 'teso_doc_eliminar', 'teso_integrar', 'teso_desintegrar']);
        $this->putJson('/api/tesouraria/configuracao/contas', ['contas' => ['diferencas_cambio_favoraveis' => '6881', 'diferencas_cambio_desfavoraveis' => '7881']], $this->s)->assertOk();
    }

    private function sessao(array $permissoes): array
    {
        $perfil = $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true));
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function produto(string $codigo): int
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => Produto::query()->where('codigo', $codigo)->value('id'));
    }

    private function documento(string $tipo, string $conta, array $linha, ?float $taxa = null): TestResponse
    {
        return $this->postJson('/api/tesouraria/documentos', ['tipo' => $tipo, 'data_documento' => $this->hoje, 'conta_financeira' => $conta,
            'descricao' => 'Liquidação em moeda', 'linhas' => [$linha]] + ($taxa ? ['taxa_cambio' => $taxa] : []), $this->s);
    }

    private function lancamento(string $numeroLan): array
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => LancamentoContabil::query()->where('numero_lan', $numeroLan)->orderBy('id')->get()
            ->map(fn ($l) => trim("{$l->tipo_dc} {$l->codigo_conta} {$l->valor} ".($l->codigo_moeda ? "{$l->codigo_moeda} {$l->valor_moeda}" : '')))->all());
    }

    #[Test]
    public function recebimento_em_usd_parcial_e_total_com_diferencas_de_cambio(): void
    {
        // factura de 3 × USD 10 + IVA ao câmbio 900,5 → USD 34,20 = Kz 30 797,10
        $ft = $this->postJson('/api/vendas/documentos', ['tipo_documento' => 'FT', 'cliente_id' => $this->cliente->id, 'data_emissao' => $this->hoje, 'codigo_moeda' => 'USD',
            'linhas' => [['produto_id' => $this->produto('P1'), 'quantidade' => 3, 'preco_unitario' => 10]]], $this->s)->assertCreated()->json('dados.id');
        $this->postJson("/api/vendas/documentos/{$ft}/contabilizar", [], $this->s)->assertOk();
        $this->getJson('/api/tesouraria/pendentes', $this->s)->assertJsonPath('dados.0.codigo_moeda', 'USD')->assertJsonPath('dados.0.saldo_moeda', '34.20')
            ->assertJsonPath('dados.0.saldo', '30797.10');

        // 1) recebe USD 10 na conta em USD ao câmbio 1 000: Kz 10 000 no banco; o cliente sai pelo histórico 9 005; +995 favorável
        $linha = fn (float $v) => ['codigo_conta' => '3111', 'tipo_dc' => 'C', 'valor' => $v, 'terceiro_id' => $this->cliente->id, 'venda_id' => $ft];
        $this->documento('RECEBIMENTO', '4312', $linha(35), 1000)->assertStatus(422)->assertJsonPath('codigo', 'VALOR_SUPERIOR_EM_ABERTO');
        $d1 = $this->documento('RECEBIMENTO', '4312', $linha(10), 1000)->assertCreated()->assertJsonPath('dados.valor_total', '10000.00')
            ->assertJsonPath('dados.valor_total_moeda', '10.00')->assertJsonPath('dados.linhas.0.valor', '9005.00')->json('dados.id');
        $i1 = $this->postJson("/api/tesouraria/documentos/{$d1}/integrar", [], $this->s)->assertOk();
        $this->assertSame(['D 4312 10000.00 USD 10.00', 'C 3111 9005.00 USD 10.00', 'C 6881 995.00'], $this->lancamento($i1->json('dados.numero_lan_contabilizacao')));
        $this->getJson("/api/vendas/documentos/{$ft}", $this->s)->assertJsonPath('dados.valor_pago', '9005.00')->assertJsonPath('dados.estado', 'PARCIAL');
        $this->getJson('/api/tesouraria/pendentes', $this->s)->assertJsonPath('dados.0.saldo_moeda', '24.20')->assertJsonPath('dados.0.saldo', '21792.10');

        // 2) recebe o resto em Kz (24 200 = USD 24,20 ao câmbio 1 000 da tabela): liquida o saldo todo pelo histórico 21 792,10
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => TaxaCambio::create(['empresa_id' => null, 'codigo_moeda' => 'USD', 'data_taxa' => $this->hoje, 'taxa' => '1000']));
        $d2 = $this->documento('RECEBIMENTO', '4311', $linha(24200))->assertCreated()->assertJsonPath('dados.linhas.0.valor_moeda', '24.20')->json('dados.id');
        $i2 = $this->postJson("/api/tesouraria/documentos/{$d2}/integrar", [], $this->s)->assertOk();
        $this->assertSame(['D 4311 24200.00', 'C 3111 21792.10 USD 24.20', 'C 6881 2407.90'], $this->lancamento($i2->json('dados.numero_lan_contabilizacao')));
        $this->getJson("/api/vendas/documentos/{$ft}", $this->s)->assertJsonPath('dados.estado', 'PAGO');
        $this->getJson('/api/tesouraria/pendentes?natureza=A_RECEBER', $this->s)->assertJsonCount(0, 'dados');

        // desintegrado, o documento volta a "por integrar" e continua a reservar o valor; anulado, o saldo em moeda volta
        $this->postJson("/api/tesouraria/documentos/{$d2}/desintegrar", ['motivo' => 'Câmbio errado'], $this->s)->assertOk();
        $this->getJson('/api/tesouraria/pendentes?natureza=A_RECEBER', $this->s)->assertJsonCount(0, 'dados');
        $this->postJson("/api/tesouraria/documentos/{$d2}/anular", ['motivo' => 'Câmbio errado'], $this->s)->assertOk();
        $this->getJson('/api/tesouraria/pendentes', $this->s)->assertJsonPath('dados.0.saldo_moeda', '24.20')->assertJsonPath('dados.0.saldo', '21792.10');
    }

    #[Test]
    public function pagamento_de_factura_em_usd_a_partir_de_conta_em_kz_com_perda_cambial(): void
    {
        $f = $this->postJson('/api/compras/faturas', ['fornecedor_id' => $this->fornecedor->id, 'numero_fatura' => 'INV-USD-1', 'data' => $this->hoje, 'codigo_moeda' => 'USD',
            'taxa_cambio' => 900, 'linhas' => [['produto_id' => $this->produto('S1'), 'quantidade' => 1, 'preco_unitario' => 100, 'taxa_imposto' => 14]]], $this->s)
            ->assertCreated()->assertJsonPath('dados.montante_total', '102600.00')->json('dados.id');
        $this->postJson("/api/compras/faturas/{$f}/contabilizar", [], $this->s)->assertOk();
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => TaxaCambio::create(['empresa_id' => null, 'codigo_moeda' => 'USD', 'data_taxa' => $this->hoje, 'taxa' => '950']));

        $linha = ['codigo_conta' => '3211', 'tipo_dc' => 'D', 'valor' => 108300, 'terceiro_id' => $this->fornecedor->id, 'fatura_compra_id' => $f];
        $d = $this->documento('PAGAMENTO', '4311', $linha)->assertCreated()->json('dados.id');
        $i = $this->postJson("/api/tesouraria/documentos/{$d}/integrar", [], $this->s)->assertOk();
        $this->assertSame(['C 4311 108300.00', 'D 3211 102600.00 USD 114.00', 'D 7881 5700.00'], $this->lancamento($i->json('dados.numero_lan_contabilizacao')));
        $this->getJson("/api/compras/faturas/{$f}", $this->s)->assertJsonPath('dados.estado', 'PAGO');

        // conta em EUR não liquida documento em USD
        $f2 = $this->postJson('/api/compras/faturas', ['fornecedor_id' => $this->fornecedor->id, 'numero_fatura' => 'INV-USD-2', 'data' => $this->hoje, 'codigo_moeda' => 'USD',
            'taxa_cambio' => 900, 'linhas' => [['produto_id' => $this->produto('S1'), 'quantidade' => 1, 'preco_unitario' => 10, 'taxa_imposto' => 0]]], $this->s)->json('dados.id');
        $this->postJson("/api/compras/faturas/{$f2}/contabilizar", [], $this->s)->assertOk();
        $this->documento('PAGAMENTO', '4313', ['codigo_conta' => '3211', 'tipo_dc' => 'D', 'valor' => 5, 'terceiro_id' => $this->fornecedor->id, 'fatura_compra_id' => $f2])
            ->assertStatus(422)->assertJsonPath('codigo', 'MOEDAS_DIFERENTES');
    }
}
