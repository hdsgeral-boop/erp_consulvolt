<?php

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;
use App\Models\Utilizador;
use Tests\TestCase;

/** Consola da plataforma: editar utilizadores de um cliente e repor a palavra-passe (provisória, com regra de força). */
final class PlataformaUtilizadoresClienteTest extends TestCase
{
    public function test_editar_e_repor_palavra_passe_de_um_utilizador_de_cliente(): void
    {
        $sa = $this->entrar($this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]));
        $cliente = Tenant::query()->create(['nome' => 'Cliente X', 'slug' => 'cliente-x', 'estado' => Tenant::ESTADO_ATIVO]);
        $chefe = $this->criarUtilizador(['nome_utilizador' => 'chefe.x', 'papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR]);
        $chefe->forceFill(['tenant_id' => $cliente->id])->save();
        $u = $this->criarUtilizador(['nome_utilizador' => 'ana.x']);
        $u->forceFill(['tenant_id' => $cliente->id])->save();
        config(['erp.saas.ativo' => true]);

        $this->putJson("/api/plataforma/clientes/{$cliente->id}/utilizadores/{$u->id}", ['nome_completo' => 'Ana X', 'email' => 'ana@x.test', 'papel' => 'ADMINISTRADOR'], $sa)
            ->assertOk()->assertJsonPath('dados.email', 'ana@x.test')->assertJsonPath('dados.papel', 'ADMINISTRADOR');
        $this->putJson("/api/plataforma/clientes/{$cliente->id}/utilizadores/{$chefe->id}", ['ativo' => false], $sa)
            ->assertStatus(422)->assertJsonPath('codigo', 'ULTIMO_SUPER_ADMINISTRADOR');

        $this->postJson("/api/plataforma/clientes/{$cliente->id}/utilizadores/{$u->id}/redefinir", ['palavra_passe' => 'abcdefghijk'], $sa)->assertStatus(422);
        $this->postJson("/api/plataforma/clientes/{$cliente->id}/utilizadores/{$u->id}/redefinir", ['palavra_passe' => 'Provisoria#2026'], $sa)->assertOk();
        $this->assertTrue((bool) $u->fresh()->alterar_palavra_passe);
    }
}
