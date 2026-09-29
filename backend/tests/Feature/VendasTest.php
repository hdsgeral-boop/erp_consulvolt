<?php

namespace Tests\Feature;

use App\Exceptions\ErroNegocio;
use App\Models\Empresa;
use App\Models\ItemVenda;
use App\Models\LancamentoContabil;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\Terceiro;
use App\Models\Venda;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Módulo Vendas (parte 1): emissão AGT, selagem, NC, conversões, recibos, contabilização e estorno. */
final class VendasTest extends TestCase
{
    private Empresa $empresa;

    private Terceiro $cliente;

    private Produto $produto;

    private Produto $servico;

    private array $cabecalhos;

    private string $hoje;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hoje = now()->toDateString();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000000']);
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach ([['311', 'Clientes'], ['3452', 'IVA liquidado'], ['451', 'Depósitos à ordem'], ['111', 'Capital'], ['611', 'Vendas'], ['621', 'Prestações de serviços']] as [$c, $d]) {
                PlanoConta::create(['codigo' => $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            $this->cliente = Terceiro::create(['nome' => 'Cliente Teste', 'nif' => '5000000001', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '311']);
            $this->produto = Produto::create(['codigo' => 'P1', 'nome' => 'Produto 1', 'preco_unitario' => 1000, 'taxa_imposto' => 14,
                'codigo_conta' => '611', 'conta_iva_liquidado' => '3452', 'movimenta_stock' => true]);
            $this->servico = Produto::create(['codigo' => 'S1', 'nome' => 'Serviço sem contas', 'preco_unitario' => 10.01, 'taxa_imposto' => 14, 'movimenta_stock' => false]);
        });
        $this->cabecalhos = $this->sessao(['vendas_faturacao_view', 'vendas_fat_emitir', 'vendas_fat_del', 'vendas_fat_contabilizar',
            'vendas_fat_descontab', 'vendas_recibos', 'vendas_fat_unpost', 'vendas_config']);
    }

    private function sessao(array $permissoes): array
    {
        $perfil = $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true));
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function emitir(array $dados = [], ?array $cabecalhos = null): TestResponse
    {
        return $this->postJson('/api/vendas/documentos', $dados + [
            'tipo_documento' => 'FT', 'cliente_id' => $this->cliente->id, 'data_emissao' => $this->hoje,
            'linhas' => [['produto_id' => $this->produto->id, 'quantidade' => 2.5]],
        ], $cabecalhos ?? $this->cabecalhos);
    }

    private function linhasLancamento(string $numeroLan): array
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => LancamentoContabil::query()->where('numero_lan', $numeroLan)
            ->orderBy('id')->get()->map(fn ($l) => "{$l->tipo_dc} {$l->codigo_conta} {$l->valor}")->all());
    }

    #[Test]
    public function emite_factura_com_numeracao_agt_calculo_exacto_e_selagem(): void
    {
        $ano = substr($this->hoje, 0, 4);
        $r = $this->emitir()->assertCreated()
            ->assertJsonPath('dados.numero_documento', "FT A{$ano}/1")
            ->assertJsonPath('dados.total_liquido', '2500.00')
            ->assertJsonPath('dados.total_imposto', '350.00')
            ->assertJsonPath('dados.total_bruto', '2850.00')
            ->assertJsonPath('dados.valor_pendente', '2850.00')
            ->assertJsonPath('dados.estado', 'PENDENTE')
            ->assertJsonPath('dados.faturacao_eletronica.estado', 'PRONTO');
        $this->assertNotNull($r->json('dados.faturacao_eletronica.selado_em'));
        $this->emitir()->assertJsonPath('dados.numero_documento', "FT A{$ano}/2");

        // IVA arredondado ao cêntimo por excesso (regra AGT): 10,01 × 14% = 1,4014 → 1,41
        $this->emitir(['linhas' => [['produto_id' => $this->servico->id, 'quantidade' => 1]]])
            ->assertJsonPath('dados.total_imposto', '1.41')->assertJsonPath('dados.total_bruto', '11.42');
    }

    #[Test]
    public function documento_fiscal_selado_e_imutavel_e_nao_se_anula(): void
    {
        $id = $this->emitir()->json('dados.id');
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () use ($id) {
            $v = Venda::query()->findOrFail($id);
            try {
                $v->update(['total_bruto' => 1]);
                $this->fail('Documento selado alterado.');
            } catch (ErroNegocio $e) {
                $this->assertSame('DOCUMENTO_SELADO', $e->codigo);
            }
            $this->expectException(ErroNegocio::class);
            ItemVenda::query()->where('venda_id', $id)->first()->update(['quantidade' => 9]);
        });
        $this->postJson("/api/vendas/documentos/{$id}/anular", [], $this->cabecalhos)->assertStatus(422)->assertJsonPath('codigo', 'DOCUMENTO_SELADO');
    }

    #[Test]
    public function recusa_data_futura_plano_invalido_e_falta_de_permissao(): void
    {
        $this->emitir(['data_emissao' => now()->addDay()->toDateString()])->assertStatus(422)->assertJsonPath('codigo', 'DATA_FUTURA');
        $this->emitir(['modo_pagamento' => 'PRAZO', 'plano_pagamentos' => [['percentagem' => 60, 'data' => $this->hoje], ['percentagem' => 30, 'data' => $this->hoje]]])
            ->assertStatus(422)->assertJsonPath('codigo', 'PLANO_PAGAMENTOS_INVALIDO');
        $this->emitir(['modo_pagamento' => 'PRAZO', 'plano_pagamentos' => [['percentagem' => 60, 'data' => $this->hoje], ['percentagem' => 40, 'data' => $this->hoje]]])
            ->assertCreated()->assertJsonPath('dados.data_vencimento', $this->hoje);
        $this->emitir([], $this->sessao(['vendas_faturacao_view']))->assertForbidden();
    }

    #[Test]
    public function factura_recibo_exige_conta_de_disponibilidade_e_nasce_paga_com_recibo(): void
    {
        $this->emitir(['tipo_documento' => 'FR', 'conta_disponibilidade' => '111'])->assertStatus(422)->assertJsonPath('codigo', 'CONTA_DISPONIBILIDADE_INVALIDA');
        $r = $this->emitir(['tipo_documento' => 'FR', 'conta_disponibilidade' => '451', 'meio_pagamento' => 'TPA'])->assertCreated()
            ->assertJsonPath('dados.estado', 'PAGO')->assertJsonPath('dados.valor_pendente', '0.00');
        $recibos = $this->getJson('/api/vendas/recibos', $this->cabecalhos)->assertOk();
        $this->assertSame($r->json('dados.id'), $recibos->json('dados.0.venda_origem_id'));

        // contabilização da FR: D disponibilidade / C proveitos / C IVA — e descontabilização por estorno
        $c = $this->postJson('/api/vendas/documentos/'.$r->json('dados.id').'/contabilizar', [], $this->cabecalhos)->assertOk();
        $this->assertSame(['D 451 2850.00', 'C 611 2500.00', 'C 3452 350.00'], $this->linhasLancamento($c->json('dados.numero_lan_contabilizacao')));
        $this->postJson('/api/vendas/documentos/'.$r->json('dados.id').'/descontabilizar', ['motivo' => 'Erro de conta'], $this->cabecalhos)
            ->assertOk()->assertJsonPath('dados.contabilizado', false);
    }

    #[Test]
    public function nota_de_credito_abate_a_factura_e_nao_excede_o_saldo(): void
    {
        $ft = $this->emitir()->json('dados.id');
        $nc = fn (float $q) => $this->emitir(['tipo_documento' => 'NC', 'venda_origem_id' => $ft, 'motivo_nota_credito' => 'Devolução parcial',
            'linhas' => [['produto_id' => $this->produto->id, 'quantidade' => $q]]]);

        $nc(3)->assertStatus(422)->assertJsonPath('codigo', 'NC_EXCEDE_SALDO');
        $nc(1)->assertCreated()->assertJsonPath('dados.estado', 'CONCLUIDO')->assertJsonPath('dados.total_bruto', '1140.00');
        $this->getJson("/api/vendas/documentos/{$ft}", $this->cabecalhos)
            ->assertJsonPath('dados.estado', 'PARCIAL')->assertJsonPath('dados.valor_pendente', '1710.00')
            ->assertJsonPath('dados.documentos_relacionados.0.relacao', 'DERIVADO');
        $nc(1.5)->assertCreated();
        $this->getJson("/api/vendas/documentos/{$ft}", $this->cabecalhos)->assertJsonPath('dados.estado', 'PAGO')->assertJsonPath('dados.valor_pendente', '0.00');
        $this->emitir(['tipo_documento' => 'NC', 'venda_origem_id' => $ft, 'linhas' => [['produto_id' => $this->produto->id, 'quantidade' => 1]]])
            ->assertStatus(422);   // sem motivo
    }

    #[Test]
    public function converte_orcamento_em_factura_e_bloqueia_reconversao_e_anulacao(): void
    {
        $or = $this->emitir(['tipo_documento' => 'OR', 'dias_validade' => 30])->assertCreated()->json('dados.id');
        $this->postJson("/api/vendas/documentos/{$or}/converter", ['tipo_destino' => 'NC', 'motivo_nota_credito' => 'x'], $this->cabecalhos)
            ->assertStatus(422)->assertJsonPath('codigo', 'CONVERSAO_INVALIDA');
        $ft = $this->postJson("/api/vendas/documentos/{$or}/converter", ['tipo_destino' => 'FT'], $this->cabecalhos)->assertCreated()
            ->assertJsonPath('dados.tipo_documento', 'FT')->assertJsonPath('dados.total_bruto', '2850.00')
            ->assertJsonPath('dados.documentos_relacionados.0.relacao', 'ORIGEM');
        $this->getJson("/api/vendas/documentos/{$or}", $this->cabecalhos)->assertJsonPath('dados.estado', 'CONCLUIDO')->assertJsonPath('dados.linhas.0.quantidade_faturada', '2.500');
        $this->postJson("/api/vendas/documentos/{$or}/converter", ['tipo_destino' => 'FT'], $this->cabecalhos)->assertStatus(422);
        $this->postJson("/api/vendas/documentos/{$or}/anular", [], $this->cabecalhos)->assertStatus(422)->assertJsonPath('codigo', 'DOCUMENTO_CONVERTIDO');

        $pf = $this->emitir(['tipo_documento' => 'PF'])->json('dados.id');
        $this->postJson("/api/vendas/documentos/{$pf}/anular", [], $this->cabecalhos)->assertOk()->assertJsonPath('dados.estado', 'ANULADO');
        $this->assertNotNull($ft->json('dados.id'));
    }

    #[Test]
    public function contabiliza_com_contas_do_produto_ou_da_configuracao(): void
    {
        $ft = $this->emitir(['linhas' => [['produto_id' => $this->produto->id, 'quantidade' => 2.5], ['produto_id' => $this->servico->id, 'quantidade' => 1]]])->json('dados.id');
        $this->postJson("/api/vendas/documentos/{$ft}/contabilizar", [], $this->cabecalhos)->assertStatus(422)->assertJsonPath('codigo', 'CONFIG_VENDAS_EM_FALTA');

        $this->putJson('/api/vendas/configuracao/contas', ['contas' => ['proveitos_servicos' => '621', 'iva_vendas' => '3452']], $this->cabecalhos)->assertOk();
        $r = $this->postJson("/api/vendas/documentos/{$ft}/contabilizar", [], $this->cabecalhos)->assertOk()->assertJsonPath('dados.contabilizado', true);
        $lan = $r->json('dados.numero_lan_contabilizacao');
        $this->assertStringStartsWith('FC', $lan);
        $this->assertSame(['D 311 2861.42', 'C 611 2500.00', 'C 3452 351.41', 'C 621 10.01'], $this->linhasLancamento($lan));
        $this->postJson("/api/vendas/documentos/{$ft}/contabilizar", [], $this->cabecalhos)->assertStatus(422)->assertJsonPath('codigo', 'JA_CONTABILIZADO');

        // nota de crédito: lançamento inverso
        $nc = $this->emitir(['tipo_documento' => 'NC', 'venda_origem_id' => $ft, 'motivo_nota_credito' => 'Desconto', 'linhas' => [['produto_id' => $this->produto->id, 'quantidade' => 1]]])->json('dados.id');
        $lanNc = $this->postJson("/api/vendas/documentos/{$nc}/contabilizar", [], $this->cabecalhos)->assertOk()->json('dados.numero_lan_contabilizacao');
        $this->assertSame(['C 311 1140.00', 'D 611 1000.00', 'D 3452 140.00'], $this->linhasLancamento($lanNc));

        $or = $this->emitir(['tipo_documento' => 'OR'])->json('dados.id');
        $this->postJson("/api/vendas/documentos/{$or}/contabilizar", [], $this->cabecalhos)->assertStatus(422)->assertJsonPath('codigo', 'NAO_CONTABILIZAVEL');
    }

    #[Test]
    public function recibo_liquida_factura_contabiliza_e_reverte_com_rasto(): void
    {
        $ft = $this->emitir()->json('dados.id');
        $recibo = fn (string $montante) => $this->postJson('/api/vendas/recibos', ['cliente_id' => $this->cliente->id, 'data' => $this->hoje,
            'codigo_conta' => '451', 'meio_pagamento' => 'TRANSFERENCIA', 'alocacoes' => [['venda_id' => $ft, 'montante' => $montante]]], $this->cabecalhos);

        $recibo('100')->assertStatus(422)->assertJsonPath('codigo', 'FACTURA_NAO_CONTABILIZADA');
        $this->postJson("/api/vendas/documentos/{$ft}/contabilizar", [], $this->cabecalhos)->assertOk();
        $recibo('9999')->assertStatus(422)->assertJsonPath('codigo', 'MONTANTE_INVALIDO');

        $r = $recibo('1000')->assertCreated()->assertJsonPath('dados.contabilizado', true)->assertJsonPath('dados.estado', 'EMITIDO');
        $this->assertSame(['D 451 1000.00', 'C 311 1000.00'], $this->linhasLancamento($r->json('dados.numero_lan_contabilizacao')));
        $this->getJson("/api/vendas/documentos/{$ft}", $this->cabecalhos)->assertJsonPath('dados.estado', 'PARCIAL')->assertJsonPath('dados.valor_pendente', '1850.00');

        $id = $r->json('dados.id');
        $this->postJson("/api/vendas/documentos/{$ft}/descontabilizar", ['motivo' => 'Correcção'], $this->cabecalhos)->assertStatus(422)->assertJsonPath('codigo', 'FACTURA_COM_RECIBOS');
        $this->postJson("/api/vendas/recibos/{$id}/anular", ['motivo' => 'Pagamento devolvido'], $this->cabecalhos)->assertStatus(422)->assertJsonPath('codigo', 'RECIBO_CONTABILIZADO');
        $this->postJson("/api/vendas/recibos/{$id}/descontabilizar", ['motivo' => 'Pagamento devolvido'], $this->cabecalhos)->assertOk()->assertJsonPath('dados.contabilizado', false);
        $this->postJson("/api/vendas/recibos/{$id}/anular", ['motivo' => 'Pagamento devolvido'], $this->cabecalhos)->assertOk()->assertJsonPath('dados.estado', 'ANULADO');
        $this->getJson("/api/vendas/documentos/{$ft}", $this->cabecalhos)->assertJsonPath('dados.estado', 'PENDENTE')->assertJsonPath('dados.valor_pendente', '2850.00');

        // descontabilizar a factura = estorno (as linhas originais ficam, marcadas como estornadas)
        $lan = $this->getJson("/api/vendas/documentos/{$ft}", $this->cabecalhos)->json('dados.numero_lan_contabilizacao');
        $this->postJson("/api/vendas/documentos/{$ft}/descontabilizar", ['motivo' => 'Correcção'], $this->cabecalhos)->assertOk()->assertJsonPath('dados.contabilizado', false);
        $estornadas = app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => LancamentoContabil::query()->where('numero_lan', $lan)->whereNotNull('estornado_por_id')->count());
        $this->assertSame(3, $estornadas);
    }

    #[Test]
    public function documentos_de_outra_empresa_nao_sao_visiveis(): void
    {
        $id = $this->emitir()->json('dados.id');
        $outra = $this->criarEmpresa();
        $perfil = $this->criarPerfil(['_v2' => true, 'vendas_faturacao_view' => true]);
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id]);
        $u->empresas()->attach($outra->id);
        $this->getJson("/api/vendas/documentos/{$id}", $this->entrar($u) + ['X-Empresa-Id' => $outra->id])->assertNotFound();
    }
}
