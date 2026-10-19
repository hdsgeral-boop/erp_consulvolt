<?php

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;
use App\Models\Utilizador;
use App\Services\Sistema\ServicoEmpresas;
use App\Support\Tenancy\ContextoTenant;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regressão (produção): o login (sem token, logo sem tenant na sessão) calculava as empresas acessíveis sob RLS, obtinha
 * [] e gravava-o em cache; depois GET /api/sistema/empresas devolvia sempre [] ao administrador do cliente.
 * A ligação de teste é superutilizador (ignora a RLS), por isso usa-se `SET ROLE erp_app`, igual à produção.
 */
class LoginEmpresasSobRlsTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::statement('RESET ROLE');
        DB::selectOne("SELECT set_config('app.tenant_id', '', false)");

        parent::tearDown();
    }

    public function test_login_sob_rls_nao_grava_lista_vazia_e_a_listagem_devolve_a_empresa(): void
    {
        $cliente = Tenant::query()->create(['nome' => 'Cliente RLS', 'slug' => 'cliente-rls']);
        $empresa = $this->criarEmpresa(['nif' => '5000000901']);
        DB::table('empresas')->where('id', $empresa->id)->update(['tenant_id' => $cliente->id]);
        $admin = $this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]);
        DB::table('utilizadores')->where('id', $admin->id)->update(['tenant_id' => $cliente->id]);

        config(['erp.saas.ativo' => true]);
        app(ServicoEmpresas::class)->invalidarTodos();
        app(ContextoTenant::class)->limpar();
        DB::statement('SET ROLE erp_app');

        $entrada = $this->postJson('/api/autenticacao/entrar', [
            'nome_utilizador' => $admin->nome_utilizador,
            'palavra_passe' => 'Palavra#Passe2026',
        ])->assertOk();

        $this->assertSame([(int) $empresa->id], array_map(fn ($e) => (int) $e['id'], $entrada->json('dados.empresas')));
        $this->assertNull(DB::selectOne("SELECT NULLIF(current_setting('app.tenant_id', true), '') AS t")->t, 'o login não deixa tenant na sessão');

        $this->getJson('/api/sistema/empresas', ['Authorization' => 'Bearer '.$entrada->json('dados.token')])
            ->assertOk()
            ->assertJsonPath('dados.0.id', (int) $empresa->id);
    }
}
