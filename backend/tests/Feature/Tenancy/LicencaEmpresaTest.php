<?php

namespace Tests\Feature\Tenancy;

use App\Models\Empresa;
use App\Models\Tenant;
use App\Services\Plataforma\ServicoLicencaCliente;
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

    public function test_cliente_novo_tem_sete_dias_de_teste_so_com_licencas_ativas_e_as_empresas_herdam(): void
    {
        config(['erp.licencas.ativo' => false]);
        $antes = Tenant::query()->create(['nome' => 'Antes', 'slug' => 'antes-licencas']);
        $this->assertSame(Tenant::LICENCA_SEM_LIMITE, $antes->fresh()->licenca_origem);
        $this->assertNull($antes->fresh()->licenca_fim);

        config(['erp.licencas.ativo' => true]);
        $novo = Tenant::query()->create(['nome' => 'Novo', 'slug' => 'novo-cliente'])->fresh();
        $this->assertSame(Tenant::LICENCA_TESTE, $novo->licenca_origem);
        $this->assertSame(now()->addDays(7)->toDateString(), $novo->licenca_fim->toDateString());
        $this->assertFalse($novo->licencaExpirada());

        // as empresas do cliente herdam a licença dele (uma segunda empresa não ganha outro período de teste)
        $primeira = $this->empresaDoCliente((int) $novo->id, '5000777021');
        app(ServicoLicencaCliente::class)->definir((int) $novo->id, Tenant::LICENCA_TESTE, now()->subDays(5)->startOfDay(), now()->addDays(2)->startOfDay());
        $segunda = $this->empresaDoCliente((int) $novo->id, '5000777022');
        $this->assertSame(now()->addDays(2)->toDateString(), $segunda->licenca_fim->toDateString());
        $this->assertSame(now()->addDays(2)->toDateString(), $primeira->refresh()->licenca_fim->toDateString());
    }

    public function test_alterar_a_licenca_do_cliente_altera_todas_as_suas_empresas_e_so_as_dele(): void
    {
        config(['erp.licencas.ativo' => true]);
        $a = Tenant::query()->create(['nome' => 'Cliente A', 'slug' => 'cliente-a-lic']);
        $b = Tenant::query()->create(['nome' => 'Cliente B', 'slug' => 'cliente-b-lic']);
        $a1 = $this->empresaDoCliente((int) $a->id, '5000777031');
        $a2 = $this->empresaDoCliente((int) $a->id, '5000777032');
        $b1 = $this->empresaDoCliente((int) $b->id, '5000777033');

        // o cliente estava em teste (7 dias): os 30 dias somam-se ao fim actual, sem perder dias
        app(ServicoLicencaCliente::class)->aplicar((int) $a->id, ['origem' => 'PLANO', 'dias_adicionar' => 30]);
        $this->assertSame(now()->addDays(37)->toDateString(), $a1->refresh()->licenca_fim->toDateString());
        $this->assertSame(Tenant::LICENCA_PLANO, $a2->refresh()->licenca_origem);
        $this->assertSame(Tenant::LICENCA_TESTE, $b1->refresh()->licenca_origem);   // o outro cliente não é afectado

        app(ServicoLicencaCliente::class)->aplicar((int) $a->id, ['origem' => 'PLANO', 'revogar' => true]);
        $this->assertTrue($a1->refresh()->licencaExpirada());
        $this->assertTrue($a2->refresh()->licencaExpirada());
        $this->assertTrue($a->refresh()->licencaExpirada());

        app(ServicoLicencaCliente::class)->aplicar((int) $a->id, ['origem' => 'SEM_LIMITE']);
        $this->assertFalse($a2->refresh()->licencaExpirada());
        $this->assertNull($a2->licenca_fim);
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

    public function test_empresa_nova_de_cliente_com_plano_interno_fica_sem_limite(): void
    {
        config(['erp.licencas.ativo' => true]);
        $interno = (int) Tenant::query()->create(['nome' => 'Grupo Interno', 'slug' => 'grupo-interno', 'estado' => 'ATIVO', 'plano' => Empresa::PLANO_INTERNO])->id;
        $outro = (int) Tenant::query()->create(['nome' => 'Cliente Pago', 'slug' => 'cliente-pago', 'estado' => 'ATIVO', 'plano' => 'basico'])->id;

        $semLimite = $this->empresaDoCliente($interno, '5000777001');
        $emTeste = $this->empresaDoCliente($outro, '5000777002');

        $this->assertSame([Empresa::LICENCA_SEM_LIMITE, null], [$semLimite->licenca_origem ?? Empresa::LICENCA_SEM_LIMITE, $semLimite->licenca_fim]);
        $this->assertSame(Empresa::LICENCA_TESTE, $emTeste->licenca_origem);
    }

    public function test_planos_iniciais_criam_os_4_planos_e_o_interno_fica_nos_clientes_internos(): void
    {
        config(['erp.licencas.ativo' => true]);
        $glomeu = (int) Tenant::query()->create(['nome' => 'GRUPO GLOMEU', 'slug' => 'grupoglomeu', 'estado' => 'ATIVO'])->id;
        $outro = (int) Tenant::query()->create(['nome' => 'Outro', 'slug' => 'outro-cliente', 'estado' => 'ATIVO'])->id;
        $empresaGlomeu = $this->empresaDoCliente($glomeu, '5000777011');
        $empresaOutro = $this->empresaDoCliente($outro, '5000777012');
        $this->assertSame(Empresa::LICENCA_TESTE, $empresaGlomeu->licenca_origem);

        $this->artisan('erp:licencas:planos-iniciais')->assertSuccessful();
        $this->artisan('erp:licencas:planos-iniciais')->assertSuccessful();   // repetível

        $this->assertSame(['basico', 'empresarial', 'interno', 'profissional'], DB::table('planos_licenca')->orderBy('codigo')->pluck('codigo')->all());
        $this->assertSame(4, DB::table('planos_licenca_versoes')->where('estado', 'PUBLICADA')->count());
        $interno = DB::table('planos_licenca')->where('codigo', 'interno')->first();
        $this->assertSame([null, null, false], [$interno->max_empresas, $interno->max_utilizadores, (bool) $interno->ativo]);
        $this->assertSame('interno', DB::table('tenants')->where('id', $glomeu)->value('plano'));
        $this->assertNull(DB::table('tenants')->where('id', $outro)->value('plano'));
        $this->assertSame([Empresa::LICENCA_SEM_LIMITE, null], [$empresaGlomeu->refresh()->licenca_origem, $empresaGlomeu->licenca_fim]);
        $this->assertSame([Tenant::LICENCA_SEM_LIMITE, null], [Tenant::query()->find($glomeu)->licenca_origem, Tenant::query()->find($glomeu)->licenca_fim]);
        $this->assertSame(Empresa::LICENCA_TESTE, $empresaOutro->refresh()->licenca_origem);
    }

    private function empresaDoCliente(int $tenant, string $nif): Empresa
    {
        $e = (new Empresa)->forceFill(['nome' => "Empresa {$nif}, Lda", 'nif' => $nif, 'estado' => Empresa::ESTADO_ATIVO, 'tenant_id' => $tenant]);
        $e->save();

        return $e->refresh();
    }
}
