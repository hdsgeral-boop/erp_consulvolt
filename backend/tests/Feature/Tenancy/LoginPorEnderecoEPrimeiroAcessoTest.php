<?php

namespace Tests\Feature\Tenancy;

use App\Models\LogAuditoria;
use App\Models\Tenant;
use App\Models\Utilizador;
use Tests\TestCase;

/** Login por endereço (a conta só entra no host do seu cliente) e primeiro acesso da plataforma (criar o 1.º super-administrador). */
class LoginPorEnderecoEPrimeiroAcessoTest extends TestCase
{
    private const SENHA = 'Palavra#Passe2026';

    private const PLATAFORMA = 'http://gestao-admin.meuerp.test';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'erp.dominio.base' => 'meuerp.test',
            'erp.dominio.prefixo_plataforma' => 'gestao-admin',
            'erp.dominio.plataforma_tenant_id' => 1,
            'erp.dominio.plataforma_setup_token' => '',
        ]);
    }

    private function cliente(string $slug, string $nomeUtilizador): Utilizador
    {
        $tenant = Tenant::query()->create(['nome' => $slug, 'slug' => $slug, 'estado' => Tenant::ESTADO_ATIVO]);
        $u = $this->criarUtilizador(['nome_utilizador' => $nomeUtilizador]);
        $u->forceFill(['tenant_id' => $tenant->id])->save();

        return $u;
    }

    /** @return array<string, string> */
    private function corpo(array $extra = []): array
    {
        return $extra + [
            'nome_utilizador' => 'dono', 'nome_completo' => 'Dono da Plataforma', 'email' => 'dono@meuerp.test',
            'palavra_passe' => 'SenhaForte123', 'palavra_passe_confirmation' => 'SenhaForte123',
        ];
    }

    private function removerSuperAdmins(): void
    {
        Utilizador::query()->where('papel', Utilizador::PAPEL_SUPER_ADMINISTRADOR)->forceDelete();
    }

    public function test_login_recusado_no_host_de_outro_cliente_com_a_mesma_mensagem(): void
    {
        $this->cliente('acme', 'user_acme');
        $this->cliente('beta', 'user_beta');

        $errado = $this->postJson('http://beta.meuerp.test/api/autenticacao/entrar', ['nome_utilizador' => 'user_acme', 'palavra_passe' => self::SENHA]);
        $inexistente = $this->postJson('http://beta.meuerp.test/api/autenticacao/entrar', ['nome_utilizador' => 'nao_existe', 'palavra_passe' => self::SENHA]);

        $errado->assertStatus(401)->assertJsonPath('codigo', 'CREDENCIAIS_INVALIDAS');
        $this->assertSame($inexistente->json('mensagem'), $errado->json('mensagem'));
        $this->assertTrue(app(\App\Support\Tenancy\ContextoEmpresa::class)->semIsolamento(
            fn () => LogAuditoria::query()->where('acao', 'Falha de autenticação')->where('nome_utilizador', 'user_acme')->exists()
        ));
    }

    public function test_login_aceite_no_host_do_proprio_cliente_e_em_host_livre(): void
    {
        $this->cliente('acme', 'user_acme');

        $this->postJson('http://acme.meuerp.test/api/autenticacao/entrar', ['nome_utilizador' => 'user_acme', 'palavra_passe' => self::SENHA])
            ->assertOk()->assertJsonStructure(['dados' => ['token']]);

        // Desenvolvimento (host fora do domínio base): sem restrição.
        $this->postJson('http://localhost/api/autenticacao/entrar', ['nome_utilizador' => 'user_acme', 'palavra_passe' => self::SENHA])->assertOk();
    }

    public function test_login_de_nao_administrador_recusado_no_host_da_plataforma(): void
    {
        $this->criarUtilizador(['nome_utilizador' => 'comum']);

        $this->postJson(self::PLATAFORMA.'/api/autenticacao/entrar', ['nome_utilizador' => 'comum', 'palavra_passe' => self::SENHA])
            ->assertStatus(401)->assertJsonPath('codigo', 'CREDENCIAIS_INVALIDAS');
    }

    public function test_estado_so_precisa_de_admin_no_host_da_plataforma_sem_super_admin(): void
    {
        $this->removerSuperAdmins();

        $this->getJson(self::PLATAFORMA.'/api/plataforma-inicial/estado')->assertOk()->assertJsonPath('dados.precisa_criar_admin', true);
        $this->getJson('http://acme.meuerp.test/api/plataforma-inicial/estado')->assertOk()->assertJsonPath('dados.precisa_criar_admin', false);
        $this->getJson('http://meuerp.test/api/plataforma-inicial/estado')->assertOk()->assertJsonPath('dados.precisa_criar_admin', false);
        $this->getJson('http://localhost/api/plataforma-inicial/estado')->assertOk()->assertJsonPath('dados.precisa_criar_admin', false);

        $this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR]);
        $this->getJson(self::PLATAFORMA.'/api/plataforma-inicial/estado')->assertOk()->assertJsonPath('dados.precisa_criar_admin', false);
    }

    public function test_cria_o_admin_uma_so_vez_e_depois_entra(): void
    {
        $this->removerSuperAdmins();

        $this->postJson(self::PLATAFORMA.'/api/plataforma-inicial/criar-admin', $this->corpo())->assertCreated();

        $admin = Utilizador::query()->where('nome_utilizador', 'dono')->firstOrFail();
        $this->assertSame(Utilizador::PAPEL_SUPER_ADMINISTRADOR, $admin->papel);
        $this->assertSame(1, (int) $admin->tenant_id);
        $this->assertTrue(app(\App\Support\Tenancy\ContextoEmpresa::class)->semIsolamento(
            fn () => LogAuditoria::query()->where('acao', 'Criar administrador inicial')->exists()
        ));

        $this->postJson(self::PLATAFORMA.'/api/autenticacao/entrar', ['nome_utilizador' => 'dono', 'palavra_passe' => 'SenhaForte123'])->assertOk();

        // Segunda criação: bloqueada, e o estado deixa de pedir.
        $this->postJson(self::PLATAFORMA.'/api/plataforma-inicial/criar-admin', $this->corpo(['nome_utilizador' => 'intruso']))
            ->assertStatus(403)->assertJsonPath('codigo', 'PLATAFORMA_INICIAL_INDISPONIVEL');
        $this->assertFalse(Utilizador::query()->where('nome_utilizador', 'intruso')->exists());
        $this->getJson(self::PLATAFORMA.'/api/plataforma-inicial/estado')->assertJsonPath('dados.precisa_criar_admin', false);
    }

    public function test_host_que_nao_e_da_plataforma_nao_cria_admin(): void
    {
        $this->removerSuperAdmins();
        $this->cliente('acme', 'user_acme');

        foreach (['http://acme.meuerp.test', 'http://meuerp.test', 'http://localhost'] as $host) {
            $this->postJson($host.'/api/plataforma-inicial/criar-admin', $this->corpo())->assertStatus(403);
        }
        $this->assertFalse(Utilizador::query()->where('nome_utilizador', 'dono')->exists());
    }

    public function test_valida_palavra_passe_curta_e_confirmacao(): void
    {
        $this->removerSuperAdmins();

        $this->postJson(self::PLATAFORMA.'/api/plataforma-inicial/criar-admin', $this->corpo(['palavra_passe' => 'curta', 'palavra_passe_confirmation' => 'curta']))->assertStatus(422);
        $this->postJson(self::PLATAFORMA.'/api/plataforma-inicial/criar-admin', $this->corpo(['palavra_passe_confirmation' => 'Outra12345']))->assertStatus(422);
        $this->assertFalse(Utilizador::query()->where('nome_utilizador', 'dono')->exists());
    }

    public function test_segredo_de_instalacao_e_exigido_quando_definido(): void
    {
        $this->removerSuperAdmins();
        config(['erp.dominio.plataforma_setup_token' => 'segredo-instalacao']);

        $this->getJson(self::PLATAFORMA.'/api/plataforma-inicial/estado')->assertJsonPath('dados.exige_segredo', true);
        $this->postJson(self::PLATAFORMA.'/api/plataforma-inicial/criar-admin', $this->corpo())->assertStatus(403);
        $this->postJson(self::PLATAFORMA.'/api/plataforma-inicial/criar-admin', $this->corpo(), ['X-Setup-Token' => 'errado'])->assertStatus(403);
        $this->assertFalse(Utilizador::query()->where('nome_utilizador', 'dono')->exists());

        $this->postJson(self::PLATAFORMA.'/api/plataforma-inicial/criar-admin', $this->corpo(), ['X-Setup-Token' => 'segredo-instalacao'])->assertCreated();
    }
}
