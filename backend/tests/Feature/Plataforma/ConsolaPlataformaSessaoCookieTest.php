<?php

namespace Tests\Feature\Plataforma;

use App\Models\PerfilUtilizador;
use App\Models\PlanoLicenca;
use App\Models\PlanoLicencaVersao;
use App\Models\Tenant;
use App\Models\Utilizador;
use App\Support\Autenticacao\CookiesSessao;
use App\Support\Tenancy\ContextoTenant;
use App\Support\Tenancy\DominioPedido;
use App\Support\Tenancy\ModulosPlano;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Regressão da consola /admin («Não foi possível carregar o painel da plataforma»): o fluxo REAL do SPA (login → cookie
 * HttpOnly, sem Authorization) tem de abrir todos os ecrãs da plataforma e do backoffice de suporte, e a API tem de dizer
 * ao frontend a que áreas a conta pertence — um Super Administrador de um CLIENTE tem o mesmo papel mas leva 403 em tudo
 * o que é /api/plataforma/*, e o frontend decidia só pelo papel.
 */
class ConsolaPlataformaSessaoCookieTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['erp.saas.ativo' => true]);
        app(ContextoTenant::class)->definir(1);
    }

    protected function tearDown(): void
    {
        DB::statement('RESET ROLE');
        DB::selectOne("SELECT set_config('app.tenant_id', '', false)");

        parent::tearDown();
    }

    /** Login real; devolve o token do cookie de sessão tal como o navegador o reapresenta (sem cabeçalho Authorization). */
    private function tokenPorCookie(Utilizador $u): string
    {
        // navegador novo: sem cookies de um login anterior do mesmo teste (com cookie de sessão o servidor exigiria o CSRF)
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $r = $this->withCredentials()->postJson('/api/autenticacao/entrar', ['nome_utilizador' => $u->nome_utilizador, 'palavra_passe' => 'Palavra#Passe2026'])->assertOk();

        return (string) $this->tokenDoCookie($r);
    }

    private function porCookie(string $token): static
    {
        return $this->withCredentials()->withUnencryptedCookie(CookiesSessao::nome('token'), $token);
    }

    /** @return list<string> URIs GET sem parâmetros das rotas cujo nome começa por `$prefixo` (sem CSV). */
    private function ecrasGet(string $prefixo): array
    {
        $uris = [];
        foreach (Route::getRoutes() as $rota) {
            if (! in_array('GET', $rota->methods(), true) || ! str_starts_with((string) $rota->getName(), $prefixo) || str_contains($rota->uri(), '{') || str_ends_with($rota->uri(), '.csv')) {
                continue;
            }
            $uris[] = '/'.$rota->uri();
        }
        sort($uris);

        return $uris;
    }

    public function test_super_administrador_da_plataforma_abre_todos_os_ecras_da_consola_so_com_cookie(): void
    {
        $u = $this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]);
        $token = $this->tokenPorCookie($u);

        $this->porCookie($token)->getJson('/api/plataforma/painel')->assertOk()
            ->assertJsonStructure(['dados' => ['clientes' => ['total'], 'empresas' => ['total'], 'utilizadores', 'ultimos_clientes', 'indicadores']]);

        $uris = $this->ecrasGet('plataforma.');
        $this->assertGreaterThan(10, count($uris), 'a lista de ecrãs da plataforma não pode ficar vazia');
        $falhas = [];
        foreach ($uris as $uri) {
            $estado = $this->porCookie($token)->getJson($uri)->getStatusCode();
            if ($estado !== 200) {
                $falhas[] = "{$uri} => {$estado}";
            }
        }
        $this->assertSame([], $falhas, 'ecrãs da plataforma que não respondem 200 ao super-administrador');
    }

    public function test_backoffice_de_suporte_abre_para_super_administrador_e_agente_so_com_cookie(): void
    {
        $super = $this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]);
        $agente = $this->criarUtilizador(['papel' => Utilizador::PAPEL_AGENTE_SUPORTE]);

        $uris = $this->ecrasGet('suporte_agente.');
        $this->assertNotEmpty($uris);
        foreach ([$super, $agente] as $conta) {
            $token = $this->tokenPorCookie($conta);
            foreach ($uris as $uri) {
                $estado = $this->porCookie($token)->getJson($uri)->getStatusCode();
                // o super-administrador abre tudo; o agente só o que o seu perfil de especialização concede (403 legítimo, nunca 401/419/5xx)
                $aceites = $conta->eSuperAdministrador() ? [200] : [200, 403];
                $this->assertContains($estado, $aceites, "{$conta->papel}: {$uri} => {$estado}");
            }
        }
    }

    public function test_operador_da_plataforma_entra_com_cookie_e_ve_o_painel_com_a_chave_do_perfil(): void
    {
        $perfil = PerfilUtilizador::create(['nome' => 'Operador painel', 'permissoes' => ['_v2' => true, 'plataforma_painel_ver' => true], 'restrito_plataforma' => true]);
        $op = $this->criarUtilizador(['papel' => Utilizador::PAPEL_OPERADOR_PLATAFORMA, 'perfil_utilizador_id' => $perfil->id]);
        $token = $this->tokenPorCookie($op);

        $this->porCookie($token)->getJson('/api/plataforma/painel')->assertOk();
        $this->porCookie($token)->getJson('/api/autenticacao/eu')->assertOk()
            ->assertJsonPath('dados.utilizador.area_plataforma', true)
            ->assertJsonPath('dados.utilizador.area_suporte', false);
    }

    public function test_super_administrador_de_um_cliente_nao_e_da_plataforma_e_a_api_diz_isso_ao_frontend(): void
    {
        $cliente = Tenant::query()->create(['nome' => 'Cliente Super', 'slug' => 'cliente-super', 'estado' => Tenant::ESTADO_ATIVO]);
        $u = $this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]);
        DB::table('utilizadores')->where('id', $u->id)->update(['tenant_id' => $cliente->id]);
        $token = $this->tokenPorCookie($u);

        $this->porCookie($token)->getJson('/api/autenticacao/eu')->assertOk()
            ->assertJsonPath('dados.utilizador.papel', 'SUPER_ADMINISTRADOR')
            ->assertJsonPath('dados.utilizador.area_plataforma', false)
            ->assertJsonPath('dados.utilizador.area_suporte', false);
        $this->porCookie($token)->getJson('/api/plataforma/painel')->assertForbidden()->assertJsonPath('codigo', 'AREA_RESERVADA');
        $this->porCookie($token)->getJson('/api/suporte/metricas')->assertForbidden();
    }

    public function test_eu_do_super_administrador_da_plataforma_marca_as_duas_areas(): void
    {
        $u = $this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]);

        $this->porCookie($this->tokenPorCookie($u))->getJson('/api/autenticacao/eu')->assertOk()
            ->assertJsonPath('dados.utilizador.area_plataforma', true)
            ->assertJsonPath('dados.utilizador.area_suporte', true);
    }

    public function test_tenant_da_plataforma_com_plano_comercial_continua_a_abrir_suporte_e_plataforma(): void
    {
        // Produção: o tenant da plataforma tem o plano «empresarial», cujos módulos nunca incluem `suporte`; o plano limita
        // os módulos de negócio e não pode cortar as chaves suporte_* ao super-administrador (403 em tickets e respostas rápidas).
        $plano = PlanoLicenca::query()->create(['codigo' => 'empresarial_plat', 'nome' => 'Empresarial', 'preco_mensal' => 1, 'moeda' => 'AOA', 'ativo' => true]);
        PlanoLicencaVersao::query()->create(['plano_id' => $plano->id, 'numero' => 1, 'estado' => PlanoLicencaVersao::PUBLICADA, 'modulos' => ['vendas', 'compras'], 'publicada_em' => now()]);
        DB::table('tenants')->where('id', 1)->update(['plano' => 'empresarial_plat']);
        ModulosPlano::invalidar();

        $u = $this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]);
        $token = $this->tokenPorCookie($u);

        foreach (['/api/suporte/respostas-rapidas', '/api/suporte/tickets', '/api/suporte/metricas', '/api/plataforma/suporte', '/api/plataforma/suporte/respostas-rapidas', '/api/plataforma/painel'] as $uri) {
            $this->assertSame(200, $this->porCookie($token)->getJson($uri)->getStatusCode(), $uri);
        }
    }

    public function test_host_da_plataforma_deixa_entrar_o_operador_do_backoffice(): void
    {
        config(['erp.dominio.base' => 'exemplo.test', 'erp.dominio.prefixo_plataforma' => 'admin.erp']);
        $dominio = DominioPedido::de(Request::create('https://admin.erp.exemplo.test/api/plataforma/painel'));
        $this->assertSame(DominioPedido::PLATAFORMA, $dominio->area);

        $operador = $this->criarUtilizador(['papel' => Utilizador::PAPEL_OPERADOR_PLATAFORMA]);
        $this->assertTrue($dominio->permite($operador), 'ExigirPlataforma aceita o operador; o filtro de domínio não pode ser mais estrito');
        $this->assertFalse($dominio->permite($this->criarUtilizador(['papel' => Utilizador::PAPEL_UTILIZADOR])));
    }
}
