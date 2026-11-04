<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Auditoria de autorização 2026-11 — escalada horizontal: o cabeçalho X-Empresa-Id nunca dá acesso a uma empresa a que
 * o utilizador não pertence (mesmo cliente ou outro cliente/tenant), em leituras e em escritas.
 */
final class AuditoriaEscaladaHorizontalTest extends TestCase
{
    private Empresa $minha;

    private Empresa $alheia;

    private array $sessao;

    protected function setUp(): void
    {
        parent::setUp();
        $this->minha = $this->criarEmpresa(['nif' => '5417006581']);
        $this->alheia = $this->criarEmpresa(['nif' => '5417006582']);
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'dashboard_view' => true, 'config_empresas_view' => true, 'config_empresas_gerir' => true, 'config_util_gerir' => true, 'config_utilizadores_view' => true])->id]);
        $u->empresas()->attach($this->minha->id);
        $this->sessao = $this->entrar($u);
    }

    #[Test]
    public function cabecalho_de_empresa_alheia_e_recusado_em_leituras_e_escritas(): void
    {
        $h = $this->sessao + ['X-Empresa-Id' => $this->alheia->id];
        $this->getJson('/api/sistema/menu', $h)->assertForbidden();
        $this->getJson('/api/sistema/utilizadores', $h)->assertForbidden();
        $this->putJson('/api/sistema/empresa-ativa/horas-extra', ['he_percentagem_1' => 50, 'he_limite_horas' => 2, 'he_percentagem_2' => 75], $h)->assertForbidden();
        // e a própria continua a abrir
        $this->getJson('/api/sistema/menu', $this->sessao + ['X-Empresa-Id' => $this->minha->id])->assertOk();
    }

    #[Test]
    public function cabecalho_malformado_ou_inexistente_nao_abre_nada(): void
    {
        foreach (['0', '-1', '999999', '1 OR 1=1', "{$this->minha->id},{$this->alheia->id}", "{$this->minha->id}.0", '0x1', '[]'] as $valor) {
            $estado = $this->getJson('/api/sistema/menu', $this->sessao + ['X-Empresa-Id' => $valor])->status();
            $this->assertContains($estado, [400, 403, 404, 422], "valor «{$valor}» devolveu {$estado}");
        }
        $this->getJson('/api/sistema/menu', $this->sessao)->assertStatus(422);   // em falta
    }

    #[Test]
    public function empresa_de_outro_cliente_nao_se_alcanca_por_cabecalho_nem_por_id_nas_fichas(): void
    {
        config(['erp.saas.ativo' => true]);
        $outro = Tenant::query()->create(['nome' => 'Outro cliente', 'slug' => 'outro-cliente-aud']);
        DB::table('empresas')->where('id', $this->alheia->id)->update(['tenant_id' => $outro->id]);

        $this->getJson('/api/sistema/menu', $this->sessao + ['X-Empresa-Id' => $this->alheia->id])->assertForbidden();
        $h = $this->sessao + ['X-Empresa-Id' => $this->minha->id];
        $this->getJson("/api/sistema/empresas/{$this->alheia->id}", $h)->assertNotFound();
        $this->getJson("/api/sistema/gestao-empresas/{$this->alheia->id}", $h)->assertStatus(404);
        $this->putJson("/api/sistema/empresas/{$this->alheia->id}", ['nome' => 'Tomada'], $h)->assertStatus(404);
        $this->putJson("/api/sistema/empresas/{$this->alheia->id}/estado", ['estado' => 'INATIVO'], $h)->assertStatus(404);
        $this->assertNotSame('Tomada', DB::table('empresas')->where('id', $this->alheia->id)->value('nome'));
        // lista de empresas acessíveis: só a própria
        $ids = array_column($this->getJson('/api/sistema/empresas', $h)->assertOk()->json('dados'), 'id');
        $this->assertSame([$this->minha->id], array_map('intval', $ids));
    }

    #[Test]
    public function nao_se_liga_um_utilizador_a_uma_empresa_que_o_actor_nao_acede_nem_no_corpo_do_pedido(): void
    {
        $h = $this->sessao + ['X-Empresa-Id' => $this->minha->id];
        $perfil = $this->criarPerfil(['_v2' => true, 'dashboard_view' => true], 'Basico')->id;
        $this->postJson('/api/sistema/utilizadores', ['nome_utilizador' => 'infiltrado', 'perfil_utilizador_id' => $perfil, 'empresas' => [['empresa_id' => $this->alheia->id]]], $h)
            ->assertForbidden()->assertJsonPath('codigo', 'EMPRESA_SEM_ACESSO');
        $this->assertDatabaseMissing('utilizadores', ['nome_utilizador' => 'infiltrado']);
    }
}
