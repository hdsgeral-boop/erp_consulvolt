<?php

namespace Tests\Feature\Plataforma;

use App\Models\Empresa;
use App\Models\PlanoConta;
use App\Models\PlanoLicenca;
use App\Models\Tenant;
use App\Models\Utilizador;
use App\Services\Contabilidade\ServicoPlanoContas;
use App\Services\Logistica\ServicoProdutos;
use App\Services\Plataforma\ServicoFaturacaoSaas;
use App\Services\Sistema\ServicoGestaoEmpresas;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Facturação AGT automática da subscrição SaaS (motor de precificação, Módulo D).
 *
 * Nota de teste: Venda/SubscricaoEmpresa/Terceiro usam o trait PertenceEmpresa (âmbito automático por empresa
 * activa) — fora do ciclo de um pedido HTTP esse contexto já não existe (ver ResolverEmpresaAtiva), por isso todas
 * as leituras directas depois de um postJson usam DB::table, nunca o modelo Eloquent.
 */
class FaturacaoSaasTest extends TestCase
{
    private const CONTA_CLIENTE = '31.9.9';

    protected function setUp(): void
    {
        parent::setUp();
        config(['erp.licencas.ativo' => true]);
    }

    public function test_confirmar_pagamento_emite_a_factura_e_reconcilia_com_o_valor_da_subscricao(): void
    {
        $this->prepararFaturacaoSaas();
        $empresa = $this->empresaComLicenca();
        $plano = $this->plano(10000);
        $gestor = $this->sessaoGestor($empresa);

        $id = $this->postJson('/api/licenca/renovacao', ['plano_id' => $plano->id, 'meses' => 1], $gestor)->assertCreated()->json('dados.id');
        $r = $this->postJson("/api/licenca/subscricoes/{$id}/confirmar-pagamento", [], $this->sessaoSuperAdmin($empresa))->assertOk();

        $vendaId = $r->json('dados.venda_id');
        $this->assertNotNull($vendaId, 'a subscrição paga tem de ficar ligada a uma Venda (FT)');

        $venda = DB::table('vendas')->where('id', $vendaId)->first();
        $this->assertSame('FT', $venda->tipo_documento);
        // O IVA arredonda sempre por excesso (regra AGT): para alguns valores não há preço de 2 casas que reconcilie
        // em cêntimo exacto — a divergência residual nunca excede 1 cêntimo (ver ServicoFaturacaoSaas::precoSemImposto).
        $this->assertEqualsWithDelta(10000.0, (float) $venda->total_bruto, 0.011, 'o total da factura tem de reconciliar com o valor cobrado da subscrição, a menos de 1 cêntimo (arredondamento AGT)');
    }

    public function test_emitir_para_subscricao_ja_facturada_nao_duplica_a_venda(): void
    {
        $this->prepararFaturacaoSaas();
        $empresa = $this->empresaComLicenca();
        $plano = $this->plano(5000);
        $gestor = $this->sessaoGestor($empresa);

        $id = $this->postJson('/api/licenca/renovacao', ['plano_id' => $plano->id, 'meses' => 1], $gestor)->assertCreated()->json('dados.id');
        $this->postJson("/api/licenca/subscricoes/{$id}/confirmar-pagamento", [], $this->sessaoSuperAdmin($empresa))->assertOk();
        $this->assertSame(1, DB::table('vendas')->count());

        // Reprocessar a mesma subscrição já facturada (ex.: um reprocessamento manual) nunca duplica a factura.
        // ContextoEmpresa::executarComo() porque a subscrição (PertenceEmpresa) só se lê com o contexto activo.
        app(ContextoEmpresa::class)->executarComo($empresa->id, function () use ($id) {
            app(ServicoFaturacaoSaas::class)->emitirParaSubscricaoPaga(\App\Models\SubscricaoEmpresa::query()->findOrFail($id));
        });
        $this->assertSame(1, DB::table('vendas')->count());
    }

    public function test_segunda_subscricao_da_mesma_empresa_reaproveita_o_terceiro(): void
    {
        $this->prepararFaturacaoSaas();
        $empresa = $this->empresaComLicenca();
        $plano = $this->plano(3000);
        $gestor = $this->sessaoGestor($empresa);
        $superAdmin = $this->sessaoSuperAdmin($empresa);

        $id1 = $this->postJson('/api/licenca/renovacao', ['plano_id' => $plano->id, 'meses' => 1], $gestor)->assertCreated()->json('dados.id');
        $this->postJson("/api/licenca/subscricoes/{$id1}/confirmar-pagamento", [], $superAdmin)->assertOk();

        $id2 = $this->postJson('/api/licenca/renovacao', ['plano_id' => $plano->id, 'meses' => 1], $gestor)->assertCreated()->json('dados.id');
        $this->postJson("/api/licenca/subscricoes/{$id2}/confirmar-pagamento", [], $superAdmin)->assertOk();

        $this->assertSame(2, DB::table('vendas')->count());
        $this->assertSame(1, DB::table('terceiros')->where('nif', $empresa->nif)->count(), 'o mesmo cliente NIF nunca deve ser duplicado entre facturas');
    }

    /**
     * O cliente que paga e a empresa de facturação da plataforma pertencem a tenants DIFERENTES (ver
     * ContextoEmpresa::executarComo/alinharTenant) — com o SaaS activo, confirma que o duplo
     * ContextoTenant::executarComo + ContextoEmpresa::executarComo não lança «empresa de outro tenant».
     */
    public function test_emissao_funciona_entre_tenants_diferentes_com_o_saas_ativo(): void
    {
        // Tudo criado com o SaaS ainda desligado (tenant inicial por omissão, sem exigir contexto activo) — só
        // depois se reatribuiem os tenants e se liga o SaaS, para exercer a verificação cross-tenant a sério.
        $this->prepararFaturacaoSaas();   // a empresa de facturação fica no tenant inicial (plataforma, id 1)

        $clienteTenant = Tenant::query()->create(['nome' => 'Cliente Facturação', 'slug' => 'cliente-faturacao']);
        $empresa = $this->empresaComLicenca();
        DB::table('empresas')->where('id', $empresa->id)->update(['tenant_id' => $clienteTenant->id]);
        $plano = $this->plano(7000);

        $u1 = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'config_licenca_gerir' => true, 'config_licenca_view' => true])->id]);
        $u1->empresas()->attach($empresa->id);
        $u1->forceFill(['tenant_id' => $clienteTenant->id])->save();
        $u2 = $this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]);
        $u2->forceFill(['tenant_id' => $clienteTenant->id])->save();

        config(['erp.saas.ativo' => true]);
        $gestor = $this->entrar($u1) + ['X-Empresa-Id' => $empresa->id];
        $superAdmin = $this->entrar($u2) + ['X-Empresa-Id' => $empresa->id];

        $id = $this->postJson('/api/licenca/renovacao', ['plano_id' => $plano->id, 'meses' => 1], $gestor)->assertCreated()->json('dados.id');
        $r = $this->postJson("/api/licenca/subscricoes/{$id}/confirmar-pagamento", [], $superAdmin)->assertOk();

        $vendaId = $r->json('dados.venda_id');
        $this->assertNotNull($vendaId);
        $this->assertEqualsWithDelta(7000.0, (float) DB::table('vendas')->where('id', $vendaId)->value('total_bruto'), 0.011);
    }

    public function test_sem_empresa_de_faturacao_configurada_a_subscricao_fica_sem_venda_e_nao_bloqueia_o_pagamento(): void
    {
        config(['erp.faturacao_saas.empresa_id' => 0]);   // desligado (omissão)
        $empresa = $this->empresaComLicenca();
        $plano = $this->plano(2000);
        $gestor = $this->sessaoGestor($empresa);

        $id = $this->postJson('/api/licenca/renovacao', ['plano_id' => $plano->id, 'meses' => 1], $gestor)->assertCreated()->json('dados.id');
        $r = $this->postJson("/api/licenca/subscricoes/{$id}/confirmar-pagamento", [], $this->sessaoSuperAdmin($empresa))
            ->assertOk()->assertJsonPath('dados.estado', 'PAGO');

        $this->assertNull($r->json('dados.venda_id'));
        $this->assertSame(0, DB::table('vendas')->count());
    }

    /** Cria a empresa de facturação da plataforma (conta 31 + produto) e aponta a config erp.faturacao_saas para ela. */
    private function prepararFaturacaoSaas(): void
    {
        $actor = $this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]);
        $empresaFaturacao = app(ServicoGestaoEmpresas::class)->criar(['nome' => 'Consulvolt Teste', 'nif' => '5999999999'], $actor);

        $produtoId = app(ContextoEmpresa::class)->executarComo($empresaFaturacao->id, function () {
            app(ServicoPlanoContas::class)->criar(['codigo' => self::CONTA_CLIENTE, 'descricao' => 'Clientes SaaS', 'tipo' => PlanoConta::TIPO_MOVIMENTO]);

            return app(ServicoProdutos::class)->guardar([
                'codigo' => 'SAAS-SUB-TESTE', 'nome' => 'Subscrição SaaS', 'preco_unitario' => 0, 'taxa_imposto' => 14, 'e_servico' => true,
            ])->id;
        });

        config([
            'erp.faturacao_saas.empresa_id' => $empresaFaturacao->id,
            'erp.faturacao_saas.produto_id' => $produtoId,
            'erp.faturacao_saas.conta_cliente' => self::CONTA_CLIENTE,
        ]);
    }

    private function empresaComLicenca(): Empresa
    {
        $e = $this->criarEmpresa();
        DB::table('empresas')->where('id', $e->id)->update([
            'licenca_origem' => Empresa::LICENCA_PLANO, 'licenca_fim' => now()->subDay()->toDateString(), 'licenca_inicio' => now()->subYear()->toDateString(),
        ]);

        return $e->fresh();
    }

    /** @param  array<string, mixed>  $extra */
    private function plano(float $precoMensal, array $extra = []): PlanoLicenca
    {
        return PlanoLicenca::query()->create($extra + ['codigo' => 'plano'.random_int(100, 99999), 'nome' => 'Plano', 'preco_mensal' => $precoMensal, 'moeda' => 'AOA', 'ativo' => true]);
    }

    /** @return array<string, mixed> */
    private function sessaoGestor(Empresa $empresa): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'config_licenca_gerir' => true, 'config_licenca_view' => true])->id]);
        $u->empresas()->attach($empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $empresa->id];
    }

    /** @return array<string, mixed> */
    private function sessaoSuperAdmin(Empresa $empresa): array
    {
        $u = $this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]);

        return $this->entrar($u) + ['X-Empresa-Id' => $empresa->id];
    }
}
