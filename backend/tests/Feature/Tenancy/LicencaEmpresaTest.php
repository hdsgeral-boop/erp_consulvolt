<?php

namespace Tests\Feature\Tenancy;

use App\Models\Empresa;
use App\Models\Utilizador;
use App\Services\Plataforma\ServicoClientes;
use App\Support\Tenancy\ContextoTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Licença por empresa (ADR-074): expirada = só leitura nessa empresa; super-administradores isentos; teste de 30 dias. */
class LicencaEmpresaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'auth:sanctum', 'empresa'])->match(['get', 'post'], '/api/_licenca_teste', fn () => response()->json(['ok' => true]));
        Route::middleware(['api', 'auth:sanctum', 'empresa', 'licenca_exportacao'])->get('/api/_licenca_exportacao_teste', fn () => response()->json(['ok' => true]));
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

    /**
     * A geração de documentos (SAF-T, recibos em PDF, cópias de segurança) conta como "exportar", que a licença
     * expirada também proíbe — mesmo sendo um pedido GET, que por omissão é tratado como leitura e passaria.
     */
    public function test_licenca_expirada_bloqueia_exportacao_mesmo_sendo_pedido_get(): void
    {
        config(['erp.licencas.ativo' => true]);
        $empresa = $this->criarEmpresaComLicenca(Empresa::LICENCA_PLANO, now()->subDay()->toDateString());
        $s = $this->sessao($empresa);

        $this->getJson('/api/_licenca_teste', $s)->assertOk();
        $this->getJson('/api/_licenca_exportacao_teste', $s)->assertStatus(403)->assertJsonPath('codigo', 'LICENCA_EXPIRADA');
    }

    public function test_licenca_valida_nao_bloqueia_exportacao(): void
    {
        config(['erp.licencas.ativo' => true]);
        $empresa = $this->criarEmpresaComLicenca(Empresa::LICENCA_PLANO, now()->addDays(30)->toDateString());

        $this->getJson('/api/_licenca_exportacao_teste', $this->sessao($empresa))->assertOk();
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

    /**
     * RBAC: o administrador de um cliente criado pela plataforma (ServicoClientes) é ADMINISTRADOR do seu cliente,
     * nunca SUPER_ADMINISTRADOR — esse papel é só de quem controla a plataforma por completo (ExigirPlataforma).
     * Chegou a ser um erro real: o administrador do cliente ficava isento da expiração da licença (como um
     * super-administrador genuíno), e a cobrança nunca se aplicava a nenhum cliente criado pela plataforma.
     */
    public function test_administrador_de_cliente_criado_pela_plataforma_nao_fica_isento_da_licenca(): void
    {
        config(['erp.licencas.ativo' => true]);
        $c = app(ServicoClientes::class)->criar([
            'nome' => 'cliente-rbac', 'slug' => 'cliente-rbac',
            'administrador' => ['nome_utilizador' => 'adm_rbac', 'palavra_passe' => 'SenhaForte123'],
        ]);
        $this->assertSame(Utilizador::PAPEL_ADMINISTRADOR, $c['administrador']->papel);
        $this->assertNotNull($c['administrador']->perfil_utilizador_id);

        $empresa = app(ContextoTenant::class)->executarComo((int) $c['cliente']->id, function () use ($c) {
            $empresa = $this->criarEmpresaComLicenca(Empresa::LICENCA_PLANO, now()->subDay()->toDateString());
            $c['administrador']->empresas()->attach($empresa->id);

            return $empresa;
        });

        $s = $this->entrar($c['administrador'], 'SenhaForte123') + ['X-Empresa-Id' => $empresa->id];
        $this->postJson('/api/_licenca_teste', [], $s)->assertStatus(403)->assertJsonPath('codigo', 'LICENCA_EXPIRADA');
    }

    public function test_com_licencas_desligadas_nada_expira(): void
    {
        config(['erp.licencas.ativo' => false]);
        $empresa = $this->criarEmpresaComLicenca(Empresa::LICENCA_PLANO, now()->subYear()->toDateString());

        $this->postJson('/api/_licenca_teste', [], $this->sessao($empresa))->assertOk();
    }

    public function test_empresa_nova_tem_trinta_dias_de_teste_so_com_licencas_ativas(): void
    {
        config(['erp.licencas.ativo' => false]);
        $antes = $this->criarEmpresa();
        $this->assertSame(Empresa::LICENCA_SEM_LIMITE, $antes->fresh()->licenca_origem);
        $this->assertNull($antes->fresh()->licenca_fim);

        config(['erp.licencas.ativo' => true]);
        $nova = $this->criarEmpresa()->fresh();
        $this->assertSame(Empresa::LICENCA_TESTE, $nova->licenca_origem);
        $this->assertSame(now()->addDays(30)->toDateString(), $nova->licenca_fim->toDateString());
        $this->assertFalse($nova->licencaExpirada());
    }

    public function test_dias_de_teste_padrao_vem_da_definicao_da_plataforma_quando_definida(): void
    {
        config(['erp.licencas.ativo' => true]);
        DB::table('definicoes_plataforma')->updateOrInsert(['chave' => 'dias_teste_padrao'], ['valor' => '10', 'atualizado_em' => now()]);

        $nova = $this->criarEmpresa()->fresh();
        $this->assertSame(now()->addDays(10)->toDateString(), $nova->licenca_fim->toDateString());
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
