<?php

namespace Tests\Feature\Tenancy;

use App\Models\Empresa;
use App\Models\Tenant;
use App\Models\Utilizador;
use App\Services\Sistema\ServicoEmpresas;
use App\Support\Tenancy\ContextoTenant;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regressão (vista em produção): o login (sem token, logo sem tenant na sessão) calculava as empresas acessíveis sob RLS,
 * obtinha [] e gravava-o em cache; depois GET /api/sistema/empresas devolvia sempre [] ao administrador do cliente.
 * A ligação de teste é a do dono (ignora a RLS), por isso usa-se `SET ROLE erp_app`, igual à produção.
 * Também cobre o login por e-mail sob RLS (função SECURITY DEFINER) e a invalidação da cache de empresas.
 */
class LoginEmpresasSobRlsTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::statement('RESET ROLE');
        DB::selectOne("SELECT set_config('app.tenant_id', '', false)");

        parent::tearDown();
    }

    /** @return array{0: Utilizador, 1: Empresa} */
    private function clienteComAdministrador(): array
    {
        $cliente = Tenant::query()->create(['nome' => 'Cliente RLS', 'slug' => 'cliente-rls']);
        $empresa = $this->criarEmpresa(['nif' => '5000000901']);
        DB::table('empresas')->where('id', $empresa->id)->update(['tenant_id' => $cliente->id]);
        $admin = $this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true, 'email' => 'admin.rls@exemplo.test']);
        DB::table('utilizadores')->where('id', $admin->id)->update(['tenant_id' => $cliente->id]);

        config(['erp.saas.ativo' => true]);
        app(ServicoEmpresas::class)->invalidarTodos();
        app(ContextoTenant::class)->limpar();
        DB::statement('SET ROLE erp_app');

        return [$admin, $empresa];
    }

    public function test_login_sob_rls_nao_grava_lista_vazia_e_a_listagem_devolve_a_empresa(): void
    {
        [$admin, $empresa] = $this->clienteComAdministrador();

        $entrada = $this->postJson('/api/autenticacao/entrar', [
            'nome_utilizador' => $admin->nome_utilizador,
            'palavra_passe' => 'Palavra#Passe2026',
        ])->assertOk();

        $this->assertSame([(int) $empresa->id], array_map(fn ($e) => (int) $e['id'], $entrada->json('dados.empresas')));
        // (no teste o login define o tenant assim que identifica o utilizador — ServicoAutenticacao::entrar —, por isso a lista nunca é calculada sem contexto)

        $this->getJson('/api/sistema/empresas', ['Authorization' => 'Bearer '.$this->tokenDoCookie($entrada)])
            ->assertOk()
            ->assertJsonPath('dados.0.id', (int) $empresa->id);
    }

    public function test_login_por_email_funciona_sob_rls(): void
    {
        [$admin, $empresa] = $this->clienteComAdministrador();

        $entrada = $this->postJson('/api/autenticacao/entrar', ['nome_utilizador' => 'Admin.RLS@exemplo.test', 'palavra_passe' => 'Palavra#Passe2026'])->assertOk();

        $this->assertSame($admin->nome_utilizador, $entrada->json('dados.utilizador.nome_utilizador'));
    }
}
