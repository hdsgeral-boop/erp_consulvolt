<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Utilizador;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Auditoria de autorização 2026-11 — escalada vertical: um administrador SEM acesso total só concede (por perfil ou
 * atribuição de perfil) o que ele próprio tem; não ganha poder editando a própria conta; não dá papéis nem acesso global.
 */
final class AuditoriaEscaladaVerticalTest extends TestCase
{
    private Empresa $a;

    private Utilizador $gestor;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->criarEmpresa(['nif' => '5417006591']);
        $this->gestor = $this->criarUtilizador(['nome_utilizador' => 'gestor_esc', 'perfil_utilizador_id' => $this->criarPerfil(['_v2' => true,
            'config_utilizadores_view' => true, 'config_util_gerir' => true, 'config_perfis_view' => true, 'config_perfis_gerir' => true], 'Gestor limitado')->id]);
        $this->gestor->empresas()->attach($this->a->id);
        $this->h = $this->entrar($this->gestor) + ['X-Empresa-Id' => $this->a->id];
    }

    private function perfilForte(): int
    {
        return $this->criarPerfil(['_v2' => true, 'lancamentos_view' => true, 'lancamentos_post' => true, 'config_empresas_gerir' => true, 'config_manutencao_view' => true], 'Forte')->id;
    }

    #[Test]
    public function nao_se_atribui_a_si_proprio_um_perfil_com_mais_permissoes(): void
    {
        $forte = $this->perfilForte();
        $this->putJson("/api/sistema/utilizadores/{$this->gestor->id}", ['perfil_utilizador_id' => $forte], $this->h)->assertForbidden()
            ->assertJsonPath('codigo', 'SEM_PERMISSAO_ADMINISTRATIVA');
        $this->assertNotSame($forte, (int) $this->gestor->fresh()->perfil_utilizador_id);
    }

    #[Test]
    public function nao_se_cria_nem_se_edita_outrem_com_um_perfil_que_excede_o_proprio(): void
    {
        $forte = $this->perfilForte();
        $this->postJson('/api/sistema/utilizadores', ['nome_utilizador' => 'novo_forte', 'perfil_utilizador_id' => $forte, 'empresas' => [['empresa_id' => $this->a->id]]], $this->h)
            ->assertForbidden()->assertJsonPath('codigo', 'SEM_PERMISSAO_ADMINISTRATIVA');
        $this->assertDatabaseMissing('utilizadores', ['nome_utilizador' => 'novo_forte']);

        $colega = $this->criarUtilizador(['nome_utilizador' => 'colega_fraco', 'perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'config_utilizadores_view' => true], 'Fraco')->id]);
        $colega->empresas()->attach($this->a->id);
        $this->putJson("/api/sistema/utilizadores/{$colega->id}", ['perfil_utilizador_id' => $forte], $this->h)->assertForbidden();
        // atribuir um perfil igual ou menor continua permitido
        $menor = $this->criarPerfil(['_v2' => true, 'config_utilizadores_view' => true], 'Menor')->id;
        $this->putJson("/api/sistema/utilizadores/{$colega->id}", ['perfil_utilizador_id' => $menor], $this->h)->assertOk();
    }

    #[Test]
    public function nao_se_cria_nem_alarga_um_perfil_com_permissoes_que_o_actor_nao_tem(): void
    {
        $this->postJson('/api/sistema/perfis', ['nome' => 'Escalada', 'permissoes' => ['config_utilizadores_view', 'lancamentos_post']], $this->h)
            ->assertForbidden()->assertJsonPath('codigo', 'SEM_PERMISSAO_ADMINISTRATIVA');
        $this->postJson('/api/sistema/perfis', ['nome' => 'Escalada', 'acesso_total' => true], $this->h)->assertForbidden();

        $perfil = $this->criarPerfil(['_v2' => true, 'config_utilizadores_view' => true], 'Alvo');
        $this->putJson("/api/sistema/perfis/{$perfil->id}", ['permissoes' => ['config_utilizadores_view', 'lancamentos_post']], $this->h)->assertForbidden();
        $this->assertArrayNotHasKey('lancamentos_post', $perfil->fresh()->permissoes);
        // só com o que o actor tem: permitido
        $this->postJson('/api/sistema/perfis', ['nome' => 'Dentro do meu âmbito', 'permissoes' => ['config_utilizadores_view']], $this->h)->assertCreated();
    }

    #[Test]
    public function ninguem_sem_acesso_total_define_papel_nem_acesso_a_todas_as_empresas(): void
    {
        $menor = $this->criarPerfil(['_v2' => true, 'config_utilizadores_view' => true], 'Menor')->id;
        foreach ([['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR], ['papel' => Utilizador::PAPEL_ADMINISTRADOR], ['acesso_todas_empresas' => true]] as $extra) {
            $this->putJson("/api/sistema/utilizadores/{$this->gestor->id}", $extra, $this->h)->assertForbidden();
            $this->postJson('/api/sistema/utilizadores', ['nome_utilizador' => 'x'.md5(json_encode($extra)), 'perfil_utilizador_id' => $menor, 'empresas' => [['empresa_id' => $this->a->id]]] + $extra, $this->h)->assertForbidden();
        }
        $this->assertSame(Utilizador::PAPEL_UTILIZADOR, $this->gestor->fresh()->papel);
        $this->assertFalse((bool) $this->gestor->fresh()->acesso_todas_empresas);
    }

    #[Test]
    public function perfil_de_plataforma_atribuido_a_utilizador_de_cliente_nao_da_acesso(): void
    {
        $perfil = $this->criarPerfil(['_v2' => true, 'plataforma_clientes_view' => true, 'plataforma_clientes_gerir' => true, 'dashboard_view' => true], 'Plataforma indevido');
        $u = $this->criarUtilizador(['nome_utilizador' => 'cliente_plat', 'perfil_utilizador_id' => $perfil->id]);
        $u->empresas()->attach($this->a->id);
        $h = $this->entrar($u) + ['X-Empresa-Id' => $this->a->id];
        $this->getJson('/api/plataforma/clientes', $h)->assertForbidden();
        $this->postJson('/api/plataforma/clientes', ['nome' => 'Cliente X'], $h)->assertForbidden();
        $this->getJson('/api/plataforma/administradores', $h)->assertForbidden();
        $this->getJson('/api/suporte/tickets', $h)->assertForbidden();
        $this->assertNotContains('plataforma_clientes_view', $this->getJson('/api/sistema/menu', $h)->json('dados.permissoes'));
    }
}
