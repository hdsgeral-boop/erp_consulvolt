<?php

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;
use App\Models\Utilizador;
use Tests\TestCase;

/** O perfil de sessão indica `e_plataforma`: só o super-administrador do tenant da plataforma não precisa de empresa. */
class PerfilSessaoPlataformaTest extends TestCase
{
    public function test_e_plataforma_so_para_super_admin_do_tenant_da_plataforma(): void
    {
        $plataforma = $this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]);
        $this->postJson('/api/autenticacao/entrar', ['nome_utilizador' => $plataforma->nome_utilizador, 'palavra_passe' => 'Palavra#Passe2026'])
            ->assertOk()->assertJsonPath('dados.e_plataforma', true);
        $this->getJson('/api/autenticacao/eu', $this->entrar($plataforma))->assertOk()->assertJsonPath('dados.e_plataforma', true);

        $cliente = Tenant::query()->create(['nome' => 'Outro', 'slug' => 'outro-perfil', 'estado' => Tenant::ESTADO_ATIVO]);
        $superCliente = $this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]);
        $superCliente->forceFill(['tenant_id' => $cliente->id])->save();
        $this->getJson('/api/autenticacao/eu', $this->entrar($superCliente))->assertOk()->assertJsonPath('dados.e_plataforma', false);

        $comum = $this->criarUtilizador();
        $this->getJson('/api/autenticacao/eu', $this->entrar($comum))->assertOk()->assertJsonPath('dados.e_plataforma', false);
    }
}
