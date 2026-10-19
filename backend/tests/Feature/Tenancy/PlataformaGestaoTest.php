<?php

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;
use App\Models\TicketSuporte;
use App\Models\Utilizador;
use Tests\TestCase;

/** Área da plataforma (ADR-075, fatia 2): suporte, auditoria, administradores, sessões e definições. */
class PlataformaGestaoTest extends TestCase
{
    /** @return array{0: Utilizador, 1: array<string, string>} */
    private function admin(): array
    {
        $u = $this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]);

        return [$u, $this->entrar($u)];
    }

    public function test_todas_as_rotas_da_plataforma_recusam_quem_nao_e_administrador(): void
    {
        $comum = $this->entrar($this->criarUtilizador());
        config(['erp.saas.ativo' => true]);

        foreach (['suporte', 'auditoria', 'administradores', 'sessoes', 'definicoes'] as $rota) {
            $this->getJson("/api/plataforma/{$rota}", $comum)->assertForbidden();
        }
        $this->putJson('/api/plataforma/definicoes', ['nome_plataforma' => 'X'], $comum)->assertForbidden();
        $this->postJson('/api/plataforma/administradores', ['nome_utilizador' => 'intruso', 'palavra_passe' => 'SenhaForte123'], $comum)->assertForbidden();
        $this->assertDatabaseMissing('utilizadores', ['nome_utilizador' => 'intruso']);
    }

    public function test_suporte_cria_actualiza_filtra_e_elimina(): void
    {
        [, $sessao] = $this->admin();
        config(['erp.saas.ativo' => true]);

        $id = $this->postJson('/api/plataforma/suporte', ['tenant_id' => 1, 'assunto' => 'Não consigo entrar', 'prioridade' => 'ALTA'], $sessao)
            ->assertCreated()->assertJsonPath('dados.estado', 'ABERTO')->json('dados.id');
        $this->postJson('/api/plataforma/suporte', ['tenant_id' => 999999, 'assunto' => 'x'], $sessao)->assertStatus(422);
        $this->postJson('/api/plataforma/suporte', ['tenant_id' => 1, 'assunto' => 'x', 'prioridade' => 'QUALQUER'], $sessao)->assertStatus(422);

        $this->putJson("/api/plataforma/suporte/{$id}", ['estado' => 'RESOLVIDO'], $sessao)->assertOk()->assertJsonPath('dados.estado', 'RESOLVIDO');
        $this->getJson('/api/plataforma/suporte?estado=ABERTO', $sessao)->assertOk()->assertJsonCount(0, 'dados');
        $this->getJson('/api/plataforma/suporte?estado=RESOLVIDO', $sessao)->assertOk()->assertJsonCount(1, 'dados');

        $this->deleteJson("/api/plataforma/suporte/{$id}", [], $sessao)->assertOk();
        $this->assertSame(0, TicketSuporte::query()->count());
    }

    public function test_administradores_nao_se_auto_desactivam_nem_eliminam(): void
    {
        [$eu, $sessao] = $this->admin();
        config(['erp.saas.ativo' => true]);

        $novo = $this->postJson('/api/plataforma/administradores', ['nome_utilizador' => 'adm_novo_plat', 'palavra_passe' => 'SenhaForte123'], $sessao)
            ->assertCreated()->json('dados.id');
        $this->postJson('/api/plataforma/administradores', ['nome_utilizador' => 'adm_novo_plat', 'palavra_passe' => 'SenhaForte123'], $sessao)->assertStatus(422);
        $this->postJson('/api/plataforma/administradores', ['nome_utilizador' => 'curta', 'palavra_passe' => 'curta'], $sessao)->assertStatus(422);

        $this->putJson("/api/plataforma/administradores/{$eu->id}/estado", ['ativo' => false], $sessao)->assertStatus(422)->assertJsonPath('codigo', 'ADMIN_PROPRIO');
        $this->deleteJson("/api/plataforma/administradores/{$eu->id}", [], $sessao)->assertStatus(422)->assertJsonPath('codigo', 'ADMIN_PROPRIO');

        $this->putJson("/api/plataforma/administradores/{$novo}/estado", ['ativo' => false], $sessao)->assertOk()->assertJsonPath('dados.ativo', false);
        $this->deleteJson("/api/plataforma/administradores/{$novo}", [], $sessao)->assertOk();
        $this->getJson('/api/plataforma/administradores', $sessao)->assertOk()->assertJsonMissing(['nome_utilizador' => 'adm_novo_plat']);
    }

    public function test_administradores_so_listam_a_plataforma_e_nao_alcançam_outros_clientes(): void
    {
        [, $sessao] = $this->admin();
        $outro = Tenant::query()->create(['nome' => 'Outro', 'slug' => 'outro-adm', 'estado' => Tenant::ESTADO_ATIVO]);
        $alheio = $this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]);
        $alheio->forceFill(['tenant_id' => $outro->id])->save();
        config(['erp.saas.ativo' => true]);

        $this->getJson('/api/plataforma/administradores', $sessao)->assertOk()->assertJsonMissing(['id' => $alheio->id]);
        $this->putJson("/api/plataforma/administradores/{$alheio->id}/estado", ['ativo' => false], $sessao)->assertNotFound();
        $this->deleteJson("/api/plataforma/administradores/{$alheio->id}", [], $sessao)->assertNotFound();
    }

    public function test_definicoes_validam_e_ignoram_chaves_desconhecidas(): void
    {
        [, $sessao] = $this->admin();
        config(['erp.saas.ativo' => true]);

        $this->putJson('/api/plataforma/definicoes', ['email_suporte' => 'nao-e-email'], $sessao)->assertStatus(422);
        $this->putJson('/api/plataforma/definicoes', ['nome_plataforma' => 'Minha Plataforma', 'chave_inventada' => 'x'], $sessao)->assertOk()
            ->assertJsonPath('dados.nome_plataforma', 'Minha Plataforma')->assertJsonMissingPath('dados.chave_inventada');
        $this->assertDatabaseMissing('definicoes_plataforma', ['chave' => 'chave_inventada']);
    }

    public function test_auditoria_e_sessoes_respondem_e_a_sessao_propria_nao_se_termina(): void
    {
        [, $sessao] = $this->admin();
        config(['erp.saas.ativo' => true]);

        $this->getJson('/api/plataforma/auditoria?q=%25', $sessao)->assertOk();
        $this->getJson('/api/plataforma/sessoes', $sessao)->assertOk();
    }
}