<?php

namespace Tests\Feature\Tenancy;

use App\Models\Empresa;
use App\Models\PlanoLicenca;
use App\Models\SubscricaoEmpresa;
use App\Models\TicketSuporte;
use App\Models\Utilizador;
use Tests\TestCase;

/**
 * Fase 5 da extensão SaaS (segurança.txt, secção 11): dashboard executivo com indicadores financeiros, comerciais
 * e de suporte — ver ServicoDashboardExecutivo para as fórmulas exactas.
 */
class DashboardExecutivoTest extends TestCase
{
    /** @return array{0: Utilizador, 1: array<string, string>} */
    private function superAdmin(): array
    {
        $u = $this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]);

        return [$u, $this->entrar($u)];
    }

    public function test_dashboard_calcula_mrr_a_partir_da_ultima_subscricao_paga_de_cada_empresa_activa(): void
    {
        config(['erp.saas.ativo' => true]);
        app(\App\Support\Tenancy\ContextoTenant::class)->definir(1);
        [, $super] = $this->superAdmin();

        $plano = PlanoLicenca::query()->firstOrCreate(['codigo' => 'teste_dash'], ['nome' => 'Plano Dash', 'preco_mensal' => 15000, 'moeda' => 'AOA', 'ativo' => true]);

        // empresa A: paga 3 meses por 30000 (10000/mês) — activa
        $empresaA = $this->criarEmpresa(['nif' => '5000000501', 'licenca_origem' => Empresa::LICENCA_PLANO, 'licenca_fim' => now()->addDays(60)->toDateString()]);
        SubscricaoEmpresa::query()->create([
            'empresa_id' => $empresaA->id, 'plano_id' => $plano->id, 'meses' => 3, 'valor' => 30000, 'moeda' => 'AOA',
            'estado' => SubscricaoEmpresa::PAGO, 'referencia' => 'REF-A', 'pago_em' => now(),
        ]);

        // empresa B: paga 1 mês por 15000 — activa
        $empresaB = $this->criarEmpresa(['nif' => '5000000502', 'licenca_origem' => Empresa::LICENCA_PLANO, 'licenca_fim' => now()->addDays(20)->toDateString()]);
        SubscricaoEmpresa::query()->create([
            'empresa_id' => $empresaB->id, 'plano_id' => $plano->id, 'meses' => 1, 'valor' => 15000, 'moeda' => 'AOA',
            'estado' => SubscricaoEmpresa::PAGO, 'referencia' => 'REF-B', 'pago_em' => now(),
        ]);

        // empresa C: cancelada — não deve entrar no MRR mesmo tendo uma subscrição paga no passado
        $empresaC = $this->criarEmpresa(['nif' => '5000000503', 'licenca_origem' => Empresa::LICENCA_PLANO]);
        SubscricaoEmpresa::query()->create([
            'empresa_id' => $empresaC->id, 'plano_id' => $plano->id, 'meses' => 1, 'valor' => 99999, 'moeda' => 'AOA',
            'estado' => SubscricaoEmpresa::PAGO, 'referencia' => 'REF-C', 'pago_em' => now(),
        ]);
        app(\App\Services\Plataforma\MaquinaEstadosLicenca::class)->transicionar($empresaC, Empresa::LICENCA_CANCELADA);

        $dados = $this->getJson('/api/plataforma/painel', $super)->assertOk()->json('dados');

        // 30000/3 + 15000/1 = 25000 (a empresa C cancelada não entra)
        $this->assertEquals(25000.0, $dados['indicadores']['mrr']);
        $this->assertEquals(300000.0, $dados['indicadores']['arr']);
        $this->assertGreaterThanOrEqual(45000, $dados['indicadores']['receita_recebida_mes']);
        $this->assertIsFloat($dados['indicadores']['taxa_cancelamento_mes']);
        $this->assertArrayHasKey('disponibilidade', $dados['indicadores']);
    }

    public function test_dashboard_conta_tickets_abertos_criticos_e_vencidos(): void
    {
        config(['erp.saas.ativo' => true]);
        app(\App\Support\Tenancy\ContextoTenant::class)->definir(1);
        [, $super] = $this->superAdmin();

        TicketSuporte::query()->create(['tenant_id' => 1, 'assunto' => 'Normal', 'descricao' => 'x', 'prioridade' => 'NORMAL', 'categoria' => 'GERAL', 'estado' => TicketSuporte::ABERTO]);
        TicketSuporte::query()->create(['tenant_id' => 1, 'assunto' => 'Urgente', 'descricao' => 'x', 'prioridade' => 'URGENTE', 'categoria' => 'GERAL',
            'estado' => TicketSuporte::ABERTO, 'prazo_sla' => now()->subHour()]);

        $dados = $this->getJson('/api/plataforma/painel', $super)->assertOk()->json('dados.indicadores.tickets');
        $this->assertSame(2, $dados['abertos']);
        $this->assertSame(1, $dados['criticos']);
        $this->assertSame(1, $dados['vencidos']);
    }

    public function test_pedido_sem_a_chave_plataforma_painel_ver_e_rejeitado(): void
    {
        config(['erp.saas.ativo' => true]);
        app(\App\Support\Tenancy\ContextoTenant::class)->definir(1);
        $perfil = \App\Models\PerfilUtilizador::create(['nome' => 'Sem painel', 'permissoes' => ['_v2' => true], 'restrito_plataforma' => true]);
        $operador = $this->criarUtilizador(['papel' => Utilizador::PAPEL_OPERADOR_PLATAFORMA, 'perfil_utilizador_id' => $perfil->id]);

        $this->getJson('/api/plataforma/painel', $this->entrar($operador))->assertForbidden();
    }
}
