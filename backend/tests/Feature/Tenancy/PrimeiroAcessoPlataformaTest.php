<?php

namespace Tests\Feature\Tenancy;

use App\Mail\NovoUtilizadorMail;
use App\Models\LogAuditoria;
use App\Models\Tenant;
use App\Models\Utilizador;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Primeiro acesso da plataforma (criar o 1.º super-administrador): só no host da plataforma, uma só vez, com segredo opcional.
 * Política do teste: a palavra-passe é gerada pelo servidor e enviada por e-mail, com troca obrigatória no primeiro acesso.
 */
class PrimeiroAcessoPlataformaTest extends TestCase
{
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
        Mail::fake();
    }

    /** @return array<string, string> */
    private function corpo(array $extra = []): array
    {
        return $extra + ['nome_utilizador' => 'dono', 'nome_completo' => 'Dono da Plataforma', 'email' => 'Dono@meuerp.test'];
    }

    private function removerSuperAdmins(): void
    {
        Utilizador::query()->where('papel', Utilizador::PAPEL_SUPER_ADMINISTRADOR)->forceDelete();
    }

    public function test_estado_so_precisa_de_admin_no_host_da_plataforma_sem_super_admin(): void
    {
        $this->removerSuperAdmins();
        Tenant::query()->create(['nome' => 'acme', 'slug' => 'acme', 'estado' => Tenant::ESTADO_ATIVO]);

        $this->getJson(self::PLATAFORMA.'/api/plataforma-inicial/estado')->assertOk()->assertJsonPath('dados.precisa_criar_admin', true);
        $this->getJson('http://acme.meuerp.test/api/plataforma-inicial/estado')->assertOk()->assertJsonPath('dados.precisa_criar_admin', false);
        $this->getJson('http://meuerp.test/api/plataforma-inicial/estado')->assertOk()->assertJsonPath('dados.precisa_criar_admin', false);
        $this->getJson('http://localhost/api/plataforma-inicial/estado')->assertOk()->assertJsonPath('dados.precisa_criar_admin', false);

        $this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR]);
        $this->getJson(self::PLATAFORMA.'/api/plataforma-inicial/estado')->assertOk()->assertJsonPath('dados.precisa_criar_admin', false);
    }

    public function test_cria_o_admin_uma_so_vez_com_palavra_passe_gerada_enviada_por_email_e_troca_obrigatoria(): void
    {
        $this->removerSuperAdmins();

        $resposta = $this->postJson(self::PLATAFORMA.'/api/plataforma-inicial/criar-admin', $this->corpo())->assertCreated();
        $this->assertStringNotContainsString('palavra_passe', (string) $resposta->getContent());

        $admin = Utilizador::query()->where('nome_utilizador', 'dono')->firstOrFail();
        $this->assertSame(Utilizador::PAPEL_SUPER_ADMINISTRADOR, $admin->papel);
        $this->assertSame(1, (int) $admin->tenant_id);
        $this->assertSame('dono@meuerp.test', $admin->email);
        $this->assertTrue((bool) $admin->deve_alterar_password, 'troca obrigatória no primeiro acesso');
        $this->assertTrue(app(ContextoEmpresa::class)->semIsolamento(
            fn () => LogAuditoria::query()->where('acao', 'Criar administrador inicial')->exists()
        ));

        $senha = null;
        Mail::assertQueued(NovoUtilizadorMail::class, function (NovoUtilizadorMail $m) use (&$senha) {
            $senha = $this->palavraPasseDoEmail($m);

            return true;
        });
        $this->postJson(self::PLATAFORMA.'/api/autenticacao/entrar', ['nome_utilizador' => 'dono', 'palavra_passe' => $senha])->assertOk();

        // Segunda criação: bloqueada, e o estado deixa de pedir.
        $this->postJson(self::PLATAFORMA.'/api/plataforma-inicial/criar-admin', $this->corpo(['nome_utilizador' => 'intruso']))
            ->assertStatus(403)->assertJsonPath('codigo', 'PLATAFORMA_INICIAL_INDISPONIVEL');
        $this->assertFalse(Utilizador::query()->where('nome_utilizador', 'intruso')->exists());
        $this->getJson(self::PLATAFORMA.'/api/plataforma-inicial/estado')->assertJsonPath('dados.precisa_criar_admin', false);
    }

    public function test_host_que_nao_e_da_plataforma_nao_cria_admin(): void
    {
        $this->removerSuperAdmins();
        Tenant::query()->create(['nome' => 'acme', 'slug' => 'acme', 'estado' => Tenant::ESTADO_ATIVO]);

        foreach (['http://acme.meuerp.test', 'http://meuerp.test', 'http://localhost'] as $host) {
            $this->postJson($host.'/api/plataforma-inicial/criar-admin', $this->corpo())->assertStatus(403);
        }
        $this->assertFalse(Utilizador::query()->where('nome_utilizador', 'dono')->exists());
    }

    public function test_valida_os_campos_obrigatorios(): void
    {
        $this->removerSuperAdmins();

        $this->postJson(self::PLATAFORMA.'/api/plataforma-inicial/criar-admin', $this->corpo(['email' => 'nao-e-email']))->assertStatus(422);
        $this->postJson(self::PLATAFORMA.'/api/plataforma-inicial/criar-admin', ['nome_utilizador' => 'dono'])->assertStatus(422);
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
