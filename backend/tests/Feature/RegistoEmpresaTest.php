<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Tenant;
use App\Models\Utilizador;
use Tests\TestCase;

final class RegistoEmpresaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    }

    public function test_registo_de_nova_empresa_cria_tenant_empresa_administrador_e_devolve_token(): void
    {
        $payload = [
            'nome_comercial' => 'Super Loja Luanda, Lda',
            'nif' => '5418291024',
            'telefone' => '923000111',
            'provincia' => 'Luanda',
            'nome_completo' => 'António Manuel',
            'nome_utilizador' => 'antonio.manuel',
            'email' => 'antonio@superloja.co.ao',
            'palavra_passe' => 'Segredo@2026!Forte',
            'plano' => null,
        ];

        $r = $this->postJson('/api/autenticacao/registar-empresa', $payload);

        $r->assertCreated();
        $r->assertJsonPath('sucesso', true);
        $this->assertNotEmpty($r->json('dados.token'));
        $this->assertNotEmpty($r->json('dados.empresa_id'));

        $this->assertDatabaseHas('tenants', [
            'nome' => 'Super Loja Luanda, Lda',
            'estado' => Tenant::ESTADO_ATIVO,
        ]);

        $this->assertDatabaseHas('empresas', [
            'nome' => 'Super Loja Luanda, Lda',
            'nif' => '5418291024',
            'licenca_origem' => Empresa::LICENCA_TESTE,
        ]);

        $this->assertDatabaseHas('utilizadores', [
            'nome_utilizador' => 'antonio.manuel',
            'email' => 'antonio@superloja.co.ao',
            'papel' => Utilizador::PAPEL_ADMINISTRADOR,
        ]);
    }

    public function test_recusa_registo_com_nif_duplicado(): void
    {
        $this->criarEmpresa(['nif' => '5418291024']);

        $payload = [
            'nome_comercial' => 'Outra Empresa, Lda',
            'nif' => '5418291024',
            'telefone' => '923000222',
            'provincia' => 'Benguela',
            'nome_completo' => 'Bernardo Silva',
            'nome_utilizador' => 'bernardo.silva',
            'email' => 'bernardo@outra.co.ao',
            'palavra_passe' => 'Segredo@2026!Forte',
        ];

        $r = $this->postJson('/api/autenticacao/registar-empresa', $payload);

        $r->assertUnprocessable();
        $this->assertArrayHasKey('nif', $r->json('erros') ?? []);
    }
}
