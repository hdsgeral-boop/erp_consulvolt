<?php

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;
use App\Models\Utilizador;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Painel da consola da plataforma: indicadores e séries (receita recorrente pelos planos, 12 meses, 30 dias). */
final class PainelPlataformaTest extends TestCase
{
    public function test_painel_com_indicadores_e_series(): void
    {
        $sa = $this->entrar($this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]));
        DB::table('planos_licenca')->insert(['codigo' => 'basico', 'nome' => 'Plano Básico', 'preco_mensal' => 9500, 'moeda' => 'AOA', 'ativo' => true]);
        Tenant::query()->create(['nome' => 'Pago 1', 'slug' => 'pago-1', 'estado' => Tenant::ESTADO_ATIVO, 'plano' => 'basico']);
        Tenant::query()->create(['nome' => 'Pago 2', 'slug' => 'pago-2', 'estado' => Tenant::ESTADO_SUSPENSO, 'plano' => 'basico']);
        config(['erp.saas.ativo' => true]);

        $r = $this->getJson('/api/plataforma/painel', $sa)->assertOk();
        $this->assertSame(9500.0, (float) $r->json('dados.indicadores.mrr'));   // só o activo conta
        $this->assertCount(12, $r->json('dados.indicadores.meses'));
        $this->assertCount(12, $r->json('dados.indicadores.receita_mes'));
        $this->assertCount(30, $r->json('dados.indicadores.acessos_dia'));
        $this->assertContains('Plano Básico', array_column($r->json('dados.indicadores.por_plano'), 'plano'));
    }

    public function test_a_propria_plataforma_nao_conta_como_cliente(): void
    {
        $sa = $this->entrar($this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]));
        Tenant::query()->create(['nome' => 'Cliente Real', 'slug' => 'cliente-real', 'estado' => Tenant::ESTADO_ATIVO]);
        config(['erp.saas.ativo' => true]);
        $plataforma = (int) config('erp.dominio.plataforma_tenant_id', 1);

        $r = $this->getJson('/api/plataforma/painel', $sa)->assertOk();
        $this->assertSame(1, $r->json('dados.clientes.total'));
        $this->assertSame(['Cliente Real'], array_column($r->json('dados.ultimos_clientes'), 'nome'));
        $this->assertNotContains($plataforma, array_column($this->getJson('/api/plataforma/empresas', $sa)->json('dados'), 'tenant_id'));
        $this->assertNotContains($plataforma, array_column($this->getJson('/api/plataforma/clientes', $sa)->json('dados'), 'id'));
        $this->assertNotContains($plataforma, array_column($this->getJson('/api/plataforma/saas/empresas', $sa)->json('dados.empresas') ?? $this->getJson('/api/plataforma/saas/empresas', $sa)->json('dados') ?? [], 'tenant_id'));
    }
}
