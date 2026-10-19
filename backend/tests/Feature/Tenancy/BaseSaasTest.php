<?php

namespace Tests\Feature\Tenancy;

use App\Exceptions\ErroContextoEmpresa;
use App\Models\Empresa;
use App\Models\Tenant;
use App\Support\Tenancy\ContextoEmpresa;
use App\Support\Tenancy\ContextoTenant;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** SaaS fase 1 (ADR-073): tenant 1 por omissão, funções SQL e alinhamento empresa↔tenant atrás da flag. */
class BaseSaasTest extends TestCase
{
    public function test_tenant_inicial_existe_e_novas_empresas_ficam_nele(): void
    {
        $this->assertSame('consulvolt', Tenant::query()->findOrFail(1)->slug);

        $id = $this->criarEmpresa(['nif' => '5000000011'])->id;
        $this->assertSame(1, (int) DB::table('empresas')->where('id', $id)->value('tenant_id'));
        $this->assertSame(1, (int) DB::selectOne('SELECT app_tenant_da_empresa(?) AS t', [$id])->t);
    }

    public function test_com_saas_desligado_o_contexto_nao_toca_na_base_de_dados(): void
    {
        config(['erp.saas.ativo' => false]);
        $tenant = app(ContextoTenant::class);
        $tenant->definir(7);

        $this->assertNull(DB::selectOne("SELECT app_tenant_id() AS t")->t);
        $this->assertSame(7, $tenant->id());
        $tenant->limpar();
        $this->assertSame(1, $tenant->paraNovo());
    }

    public function test_com_saas_ativo_a_empresa_define_o_tenant_da_sessao(): void
    {
        $empresa = $this->criarEmpresa(['nif' => '5000000022'])->id;
        config(['erp.saas.ativo' => true]);
        $tenant = app(ContextoTenant::class);
        $tenant->limpar();

        app(ContextoEmpresa::class)->definir($empresa);

        $this->assertSame(1, $tenant->id());
        $this->assertSame(1, (int) DB::selectOne('SELECT app_tenant_id() AS t')->t);
        $tenant->limpar();
    }

    public function test_com_saas_ativo_empresa_de_outro_tenant_e_recusada(): void
    {
        $empresa = $this->criarEmpresa(['nif' => '5000000033'])->id;
        config(['erp.saas.ativo' => true]);
        $outro = Tenant::query()->create(['nome' => 'Cliente teste', 'slug' => 'cliente-teste']);
        DB::table('empresas')->where('id', $empresa)->update(['tenant_id' => $outro->id]);

        $tenant = app(ContextoTenant::class);
        $tenant->definir(1);

        try {
            $this->expectException(ErroContextoEmpresa::class);
            app(ContextoEmpresa::class)->definir($empresa);
        } finally {
            $tenant->limpar();
        }
    }

    public function test_para_cada_tenant_so_percorre_ativos(): void
    {
        config(['erp.saas.ativo' => true]);
        Tenant::query()->create(['nome' => 'Suspenso', 'slug' => 'suspenso', 'estado' => 'SUSPENSO']);

        $vistos = [];
        app(ContextoTenant::class)->paraCadaTenant(function (int $id) use (&$vistos) {
            $vistos[] = $id;
        });

        $this->assertSame([1], $vistos);
        $this->assertNull(app(ContextoTenant::class)->id());
    }
}
