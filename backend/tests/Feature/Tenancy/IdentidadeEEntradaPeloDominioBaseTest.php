<?php

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;
use App\Models\Utilizador;
use Tests\TestCase;

/**
 * Identidade do sistema por cliente (logótipo e nome em <slug>.<base>, sem afectar os outros) e entrada pelo domínio
 * base: credenciais validadas em meuerp.<base>, reencaminhamento para o endereço do cliente com um código de uso único.
 */
class IdentidadeEEntradaPeloDominioBaseTest extends TestCase
{
    private const SENHA = 'Palavra#Passe2026';

    private const PNG = 'data:image/png;base64,UE5HLXRlc3Rl';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'erp.dominio.base' => 'meuerp.test',
            'erp.dominio.prefixo_plataforma' => 'gestao-admin',
            'erp.dominio.plataforma_tenant_id' => 1,
        ]);
    }

    private function cliente(string $slug, string $nomeUtilizador, string $papel = Utilizador::PAPEL_UTILIZADOR): Utilizador
    {
        $tenant = Tenant::query()->create(['nome' => $slug, 'slug' => $slug, 'estado' => Tenant::ESTADO_ATIVO]);
        $u = $this->criarUtilizador(['nome_utilizador' => $nomeUtilizador, 'papel' => $papel]);
        $u->forceFill(['tenant_id' => $tenant->id])->save();

        return $u;
    }

    private function tokenEm(string $host, Utilizador $u): array
    {
        $token = $this->postJson("http://{$host}/api/autenticacao/entrar", ['nome_utilizador' => $u->nome_utilizador, 'palavra_passe' => self::SENHA])
            ->assertOk()->json('dados.token');

        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_identidade_gravada_por_um_cliente_so_aparece_no_endereco_dele(): void
    {
        $acme = $this->cliente('acme', 'dono_acme', Utilizador::PAPEL_SUPER_ADMINISTRADOR);
        $this->cliente('beta', 'dono_beta', Utilizador::PAPEL_SUPER_ADMINISTRADOR);
        $empresa = $this->criarEmpresa();
        $empresa->forceFill(['tenant_id' => $acme->tenant_id])->save();
        $cabecalhos = fn () => $this->tokenEm('acme.meuerp.test', $acme) + ['X-Empresa-Id' => $empresa->id];

        $this->putJson('http://acme.meuerp.test/api/sistema/configuracoes/logotipo-login', ['logotipo' => self::PNG, 'nome_sistema' => 'ACME ERP'], $cabecalhos())
            ->assertOk();

        $this->getJson('http://acme.meuerp.test/api/sistema/logotipo-login')->assertOk()
            ->assertJsonPath('dados.logotipo', self::PNG)->assertJsonPath('dados.nome_sistema', 'ACME ERP');
        $this->getJson('http://beta.meuerp.test/api/sistema/logotipo-login')->assertOk()
            ->assertJsonPath('dados.logotipo', null)->assertJsonPath('dados.nome_sistema', null);
        $this->getJson('http://meuerp.test/api/sistema/logotipo-login')->assertOk()->assertJsonPath('dados.logotipo', null);

        // só o nome: o logótipo mantém-se; null remove
        $this->putJson('http://acme.meuerp.test/api/sistema/configuracoes/logotipo-login', ['logotipo' => null], $cabecalhos())->assertOk();
        $this->getJson('http://acme.meuerp.test/api/sistema/logotipo-login')->assertJsonPath('dados.logotipo', null)->assertJsonPath('dados.nome_sistema', 'ACME ERP');
    }

    public function test_entrada_no_dominio_base_reencaminha_com_codigo_de_uso_unico(): void
    {
        $u = $this->cliente('acme', 'user_acme');

        $r = $this->postJson('http://meuerp.test/api/autenticacao/entrar', ['nome_utilizador' => 'user_acme', 'palavra_passe' => self::SENHA])->assertOk();
        $this->assertNull($r->json('dados.token'));
        $destino = (string) $r->json('dados.redirecionar_para');
        $this->assertMatchesRegularExpression('#^https://acme\.meuerp\.test/entrar\#codigo=[A-Za-z0-9]{48}$#', $destino);
        $codigo = substr($destino, strpos($destino, '=') + 1);
        $this->assertSame(0, $u->tokens()->count());

        // noutro cliente o código não serve (e é consumido)
        $this->cliente('beta', 'user_beta');
        $this->postJson('http://beta.meuerp.test/api/autenticacao/trocar-codigo', ['codigo' => $codigo])->assertStatus(401)->assertJsonPath('codigo', 'CODIGO_ENTRADA_INVALIDO');
        $this->postJson('http://acme.meuerp.test/api/autenticacao/trocar-codigo', ['codigo' => $codigo])->assertStatus(401);
    }

    public function test_codigo_trocado_no_endereco_do_cliente_abre_a_sessao_uma_so_vez(): void
    {
        $this->cliente('acme', 'user_acme');
        $destino = (string) $this->postJson('http://meuerp.test/api/autenticacao/entrar', ['nome_utilizador' => 'user_acme', 'palavra_passe' => self::SENHA])
            ->json('dados.redirecionar_para');
        $codigo = substr($destino, strpos($destino, '=') + 1);

        $sessao = $this->postJson('http://acme.meuerp.test/api/autenticacao/trocar-codigo', ['codigo' => $codigo, 'dispositivo' => 'web'])
            ->assertOk()->assertJsonPath('dados.utilizador.nome_utilizador', 'user_acme');
        $this->getJson('http://acme.meuerp.test/api/autenticacao/eu', ['Authorization' => 'Bearer '.$sessao->json('dados.token')])->assertOk();

        $this->postJson('http://acme.meuerp.test/api/autenticacao/trocar-codigo', ['codigo' => $codigo])->assertStatus(401);
    }

    public function test_credenciais_erradas_no_dominio_base_dao_a_mensagem_de_sempre(): void
    {
        $this->cliente('acme', 'user_acme');

        $this->postJson('http://meuerp.test/api/autenticacao/entrar', ['nome_utilizador' => 'user_acme', 'palavra_passe' => 'errada'])
            ->assertStatus(401)->assertJsonPath('codigo', 'CREDENCIAIS_INVALIDAS');
    }

    public function test_dominio_permitido_so_para_nomes_do_sistema(): void
    {
        $this->cliente('acme', 'user_acme');
        foreach (['meuerp.test', 'www.meuerp.test', 'gestao-admin.meuerp.test', 'acme.meuerp.test'] as $nome) {
            $this->get("/api/sistema/dominio-permitido?domain={$nome}")->assertOk();
        }
        foreach (['inventado.meuerp.test', 'a.b.meuerp.test', 'exemplo.com', ''] as $nome) {
            $this->get("/api/sistema/dominio-permitido?domain={$nome}")->assertNotFound();
        }
    }
}
