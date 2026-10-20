<?php

namespace Tests\Feature\Tenancy;

use App\Models\Empresa;
use App\Models\PerfilUtilizador;
use App\Models\Tenant;
use App\Models\Utilizador;
use App\Support\Tenancy\ContextoTenant;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** SaaS activo: a gestão de utilizadores e de perfis de um cliente nunca mostra nem altera contas/perfis de outro. */
class UtilizadoresEPerfisPorClienteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['erp.saas.ativo' => true, 'erp.dominio.base' => '', 'erp.dominio.plataforma_tenant_id' => 1]);
    }

    /** @return array{0: Utilizador, 1: Empresa, 2: PerfilUtilizador} */
    private function cliente(string $slug): array
    {
        $t = Tenant::query()->create(['nome' => $slug, 'slug' => $slug, 'estado' => Tenant::ESTADO_ATIVO]);

        return app(ContextoTenant::class)->executarComo((int) $t->id, function () use ($slug) {
            $perfil = PerfilUtilizador::query()->create(['nome' => "Perfil {$slug}", 'permissoes' => ['all' => true]]);
            $dono = $this->criarUtilizador(['nome_utilizador' => "dono_{$slug}", 'papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR,
                'acesso_todas_empresas' => true, 'perfil_utilizador_id' => $perfil->id]);

            return [$dono, $this->criarEmpresa(['nome' => "Empresa {$slug}"]), $perfil];
        });
    }

    public function test_lista_ficha_e_perfis_so_do_proprio_cliente(): void
    {
        [$a, $empresaA, $perfilA] = $this->cliente('acme');
        [$b, , $perfilB] = $this->cliente('beta');
        $h = $this->entrar($a) + ['X-Empresa-Id' => $empresaA->id];

        $nomes = collect($this->getJson('/api/sistema/utilizadores?por_pagina=100', $h)->assertOk()->json('dados'))->pluck('nome_utilizador');
        $this->assertContains('dono_acme', $nomes);
        $this->assertNotContains('dono_beta', $nomes);

        $this->getJson("/api/sistema/utilizadores/{$b->id}", $h)->assertNotFound();
        $this->putJson("/api/sistema/utilizadores/{$b->id}", ['nome_completo' => 'invadido'], $h)->assertNotFound();
        $this->assertNotSame('invadido', DB::table('utilizadores')->where('id', $b->id)->value('nome_completo'));

        $perfis = collect($this->getJson('/api/sistema/perfis', $h)->assertOk()->json('dados'))->pluck('id');
        $this->assertContains($perfilA->id, $perfis);
        $this->assertNotContains($perfilB->id, $perfis);
    }

    public function test_nome_de_utilizador_de_outro_cliente_continua_ocupado(): void
    {
        [$a, $empresaA, $perfilA] = $this->cliente('acme');
        $this->cliente('beta');

        $this->postJson('/api/sistema/utilizadores', ['nome_utilizador' => 'dono_beta', 'palavra_passe' => 'Palavra#Passe2026', 'perfil_utilizador_id' => $perfilA->id],
            $this->entrar($a) + ['X-Empresa-Id' => $empresaA->id])->assertStatus(422)->assertJsonPath('codigo', 'UTILIZADOR_DUPLICADO');
    }
}
