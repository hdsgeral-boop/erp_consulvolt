<?php

namespace Tests\Feature\Tenancy;

use App\Models\Empresa;
use App\Models\Utilizador;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Licença por empresa (ADR-074): expirada = só leitura nessa empresa; super-administradores isentos; teste de 7 dias. */
class LicencaEmpresaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'auth:sanctum', 'empresa'])->match(['get', 'post'], '/api/_licenca_teste', fn () => response()->json(['ok' => true]));
    }

    public function test_empresa_sem_limite_nunca_expira(): void
    {
        config(['erp.licencas.ativo' => true]);
        $empresa = $this->criarEmpresaComLicenca(Empresa::LICENCA_SEM_LIMITE, null);

        $this->postJson('/api/_licenca_teste', [], $this->sessao($empresa))->assertOk();
    }

    public function test_licenca_expirada_fica_so_de_leitura(): void
    {
        config(['erp.licencas.ativo' => true]);
        $empresa = $this->criarEmpresaComLicenca(Empresa::LICENCA_PLANO, now()->subDay()->toDateString());
        $s = $this->sessao($empresa);

        $this->getJson('/api/_licenca_teste', $s)->assertOk();
        $this->postJson('/api/_licenca_teste', [], $s)->assertStatus(403)->assertJsonPath('codigo', 'LICENCA_EXPIRADA');
    }

    public function test_o_ultimo_dia_da_licenca_ainda_e_valido_sem_tolerancia_depois(): void
    {
        config(['erp.licencas.ativo' => true]);
        $hoje = $this->criarEmpresaComLicenca(Empresa::LICENCA_PLANO, now()->toDateString());
        $ontem = $this->criarEmpresaComLicenca(Empresa::LICENCA_PLANO, now()->subDay()->toDateString());

        $this->postJson('/api/_licenca_teste', [], $this->sessao($hoje))->assertOk();
        $this->postJson('/api/_licenca_teste', [], $this->sessao($ontem))->assertStatus(403);
    }

    public function test_so_a_empresa_expirada_fica_so_de_leitura(): void
    {
        config(['erp.licencas.ativo' => true]);
        $expirada = $this->criarEmpresaComLicenca(Empresa::LICENCA_PLANO, now()->subDays(3)->toDateString());
        $valida = $this->criarEmpresaComLicenca(Empresa::LICENCA_PLANO, now()->addDays(30)->toDateString());
        $u = $this->criarUtilizador();
        $u->empresas()->attach([$expirada->id, $valida->id]);
        $auth = $this->entrar($u);

        $this->postJson('/api/_licenca_teste', [], $auth + ['X-Empresa-Id' => $expirada->id])->assertStatus(403);
        $this->postJson('/api/_licenca_teste', [], $auth + ['X-Empresa-Id' => $valida->id])->assertOk();
    }

    public function test_super_administrador_tem_acesso_vitalicio(): void
    {
        config(['erp.licencas.ativo' => true]);
        $empresa = $this->criarEmpresaComLicenca(Empresa::LICENCA_PLANO, now()->subYear()->toDateString());
        $u = $this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]);

        $this->postJson('/api/_licenca_teste', [], $this->entrar($u) + ['X-Empresa-Id' => $empresa->id])->assertOk();
    }

    public function test_com_licencas_desligadas_nada_expira(): void
    {
        config(['erp.licencas.ativo' => false]);
        $empresa = $this->criarEmpresaComLicenca(Empresa::LICENCA_PLANO, now()->subYear()->toDateString());

        $this->postJson('/api/_licenca_teste', [], $this->sessao($empresa))->assertOk();
    }

    public function test_empresa_nova_tem_sete_dias_de_teste_so_com_licencas_ativas(): void
    {
        config(['erp.licencas.ativo' => false]);
        $antes = $this->criarEmpresa();
        $this->assertSame(Empresa::LICENCA_SEM_LIMITE, $antes->fresh()->licenca_origem);
        $this->assertNull($antes->fresh()->licenca_fim);

        config(['erp.licencas.ativo' => true]);
        $nova = $this->criarEmpresa()->fresh();
        $this->assertSame(Empresa::LICENCA_TESTE, $nova->licenca_origem);
        $this->assertSame(now()->addDays(7)->toDateString(), $nova->licenca_fim->toDateString());
        $this->assertFalse($nova->licencaExpirada());
    }

    private function criarEmpresaComLicenca(string $origem, ?string $fim): Empresa
    {
        $empresa = $this->criarEmpresa();
        DB::table('empresas')->where('id', $empresa->id)->update(['licenca_origem' => $origem, 'licenca_fim' => $fim]);

        return $empresa->fresh();
    }

    /** @return array<string, mixed> */
    private function sessao(Empresa $empresa): array
    {
        $u = $this->criarUtilizador();
        $u->empresas()->attach($empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $empresa->id];
    }
}
