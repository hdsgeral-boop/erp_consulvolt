<?php

namespace Tests\Feature\Tenancy;

use App\Models\Empresa;
use App\Models\Tenant;
use App\Services\Plataforma\MaquinaEstadosLicenca;
use App\Support\Tenancy\ContextoTenant;
use App\Support\Tenancy\DefinicoesPlataforma;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fase 3 da extensão SaaS (segurança.txt, secção 9): o comando `erp:licencas:sincronizar` avança a máquina de
 * estados da licença (Fase 1) com o simples passar do tempo, sem depender de nenhuma acção manual.
 */
class SincronizarEstadoLicencasCommandTest extends TestCase
{
    public function test_sem_licencas_ativas_nao_faz_nada(): void
    {
        config(['erp.licencas.ativo' => false]);
        DefinicoesPlataforma::invalidar();

        $this->artisan('erp:licencas:sincronizar')->assertSuccessful();
    }

    public function test_avanca_empresas_expiradas_para_suspensa_e_preserva_suspensoes_manuais(): void
    {
        config(['erp.licencas.ativo' => true]);
        DefinicoesPlataforma::invalidar();
        app(ContextoTenant::class)->definir(1);

        $expirada = $this->criarEmpresa(['nif' => '5000000401', 'licenca_origem' => Empresa::LICENCA_PLANO, 'licenca_fim' => now()->addDays(10)->toDateString()]);
        // simula o passar do tempo sem sincronização (ex.: a data foi gravada directamente, sem passar pelo serviço)
        DB::table('empresas')->where('id', $expirada->id)->update(['licenca_fim' => now()->subDays(5)->toDateString()]);

        $administrativa = $this->criarEmpresa(['nif' => '5000000402', 'licenca_origem' => Empresa::LICENCA_PLANO, 'licenca_fim' => now()->addDays(30)->toDateString()]);
        app(MaquinaEstadosLicenca::class)->transicionar($administrativa, Empresa::LICENCA_SUSPENSA, Empresa::MOTIVO_ADMINISTRATIVA);

        $ativa = $this->criarEmpresa(['nif' => '5000000403', 'licenca_origem' => Empresa::LICENCA_PLANO, 'licenca_fim' => now()->addDays(30)->toDateString()]);

        $this->artisan('erp:licencas:sincronizar')->assertSuccessful();

        $this->assertSame(Empresa::LICENCA_SUSPENSA, $expirada->fresh()->estado_licenca, 'Expirada há 5 dias sem sincronizar: o comando tem de a avançar.');
        $this->assertSame(Empresa::MOTIVO_INCUMPRIMENTO, $expirada->fresh()->motivo_suspensao);
        $this->assertSame(Empresa::LICENCA_SUSPENSA, $administrativa->fresh()->estado_licenca, 'Suspensão administrativa preserva-se mesmo com licença ainda válida.');
        $this->assertSame(Empresa::MOTIVO_ADMINISTRATIVA, $administrativa->fresh()->motivo_suspensao, 'O comando nunca pode substituir um motivo manual.');
        $this->assertSame(Empresa::LICENCA_ACTIVA, $ativa->fresh()->estado_licenca);
    }

    public function test_corre_para_todos_os_tenants(): void
    {
        config(['erp.licencas.ativo' => true]);
        DefinicoesPlataforma::invalidar();

        app(ContextoTenant::class)->definir(1);
        $tenantA = $this->criarEmpresa(['nif' => '5000000404', 'licenca_origem' => Empresa::LICENCA_PLANO, 'licenca_fim' => now()->addDays(5)->toDateString()]);
        DB::table('empresas')->where('id', $tenantA->id)->update(['licenca_fim' => now()->subDays(1)->toDateString()]);

        $outroTenant = Tenant::create(['nome' => 'Outro Cliente', 'slug' => 'outro-cliente', 'estado' => Tenant::ESTADO_ATIVO]);
        app(ContextoTenant::class)->definir($outroTenant->id);
        $tenantB = $this->criarEmpresa(['nif' => '5000000405', 'licenca_origem' => Empresa::LICENCA_PLANO, 'licenca_fim' => now()->addDays(5)->toDateString()]);
        DB::table('empresas')->where('id', $tenantB->id)->update(['licenca_fim' => now()->subDays(1)->toDateString()]);

        $this->artisan('erp:licencas:sincronizar')->assertSuccessful();

        $this->assertSame(Empresa::LICENCA_SUSPENSA, $tenantA->fresh()->estado_licenca);
        $this->assertSame(Empresa::LICENCA_SUSPENSA, $tenantB->fresh()->estado_licenca);
    }
}
