<?php

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;
use App\Models\Utilizador;
use Tests\TestCase;

/** Área da plataforma SaaS (ADR-075): clientes e painel, só para o super-administrador do tenant da plataforma. */
class PlataformaClientesTest extends TestCase
{
    public function test_recusa_sem_saas_activo_e_a_quem_nao_e_super_admin_da_plataforma(): void
    {
        $super = $this->entrar($this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]));
        $comum = $this->entrar($this->criarUtilizador());

        $this->getJson('/api/plataforma/clientes', $super)->assertForbidden()->assertJsonPath('codigo', 'SAAS_INATIVO');

        config(['erp.saas.ativo' => true]);
        $this->getJson('/api/plataforma/clientes', $comum)->assertForbidden()->assertJsonPath('codigo', 'AREA_RESERVADA');
        $this->getJson('/api/plataforma/painel', $comum)->assertForbidden();
        $this->getJson('/api/plataforma/clientes')->assertUnauthorized();
    }

    public function test_super_admin_de_outro_cliente_nao_entra(): void
    {
        $outro = Tenant::query()->create(['nome' => 'Outro', 'slug' => 'outro-cliente', 'estado' => Tenant::ESTADO_ATIVO]);
        $u = $this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]);
        $u->forceFill(['tenant_id' => $outro->id])->save();
        $sessao = $this->entrar($u);

        config(['erp.saas.ativo' => true]);
        $this->getJson('/api/plataforma/clientes', $sessao)->assertForbidden();
    }

    public function test_cria_cliente_com_administrador_e_empresa_e_suspende(): void
    {
        $sessao = $this->entrar($this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]));
        config(['erp.saas.ativo' => true]);

        $id = $this->postJson('/api/plataforma/clientes', [
            'nome' => 'Cliente Novo', 'slug' => 'cliente-novo',
            'administrador' => ['nome_utilizador' => 'adm_cliente_novo', 'palavra_passe' => 'SenhaForte123'],
            'empresa' => ['nome' => 'Empresa do Cliente', 'nif' => '5417000999'],
        ], $sessao)->assertCreated()->assertJsonPath('dados.slug', 'cliente-novo')->json('dados.id');

        $adm = Utilizador::query()->where('nome_utilizador', 'adm_cliente_novo')->firstOrFail();
        $this->assertSame((int) $id, (int) $adm->tenant_id);

        $this->getJson("/api/plataforma/clientes/{$id}", $sessao)->assertOk()->assertJsonPath('dados.utilizadores', 1)
            ->assertJsonCount(1, 'dados.empresas');
        $this->getJson('/api/plataforma/painel', $sessao)->assertOk()->assertJsonPath('dados.clientes.total', 2);

        $this->putJson("/api/plataforma/clientes/{$id}/estado", ['estado' => 'SUSPENSO'], $sessao)->assertOk()->assertJsonPath('dados.estado', 'SUSPENSO');
        // O cliente que opera a plataforma nunca se suspende.
        $this->putJson('/api/plataforma/clientes/1/estado', ['estado' => 'SUSPENSO'], $sessao)->assertStatus(422)->assertJsonPath('codigo', 'PLATAFORMA_NAO_SUSPENSIVEL');
        // Endereços reservados e repetidos são recusados.
        $this->postJson('/api/plataforma/clientes', ['nome' => 'X', 'slug' => 'admin', 'administrador' => ['nome_utilizador' => 'zz1', 'palavra_passe' => 'SenhaForte123']], $sessao)->assertStatus(422);
        $this->postJson('/api/plataforma/clientes', ['nome' => 'X', 'slug' => 'cliente-novo', 'administrador' => ['nome_utilizador' => 'zz2', 'palavra_passe' => 'SenhaForte123']], $sessao)->assertStatus(422);
    }

    public function test_relatorios_csv_neutralizam_formulas(): void
    {
        $sessao = $this->entrar($this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]));
        Tenant::query()->create(['nome' => '=HYPERLINK("x")', 'slug' => 'csv-teste', 'estado' => Tenant::ESTADO_ATIVO]);
        config(['erp.saas.ativo' => true]);

        $r = $this->get('/api/plataforma/relatorios/clientes.csv', $sessao)->assertOk();
        $this->assertStringContainsString("'=HYPERLINK", $r->getContent());
    }
}