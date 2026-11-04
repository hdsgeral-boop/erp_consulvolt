<?php

namespace Tests\Feature;

use App\Services\Sistema\ServicoPermissoes;
use Tests\TestCase;

/** Auditoria de autorização (2026-11), parte 1: o ecrã `ia_chat` só transporta permissões, nunca aparece no menu. */
final class AuditoriaMenuIaChatTest extends TestCase
{
    public function test_ia_chat_nao_aparece_no_menu_mas_a_permissao_continua_valida(): void
    {
        $e = $this->criarEmpresa();
        $perfil = $this->criarPerfil(['_v2' => true, 'ia_chat_view' => true, 'ia_chat_regras' => true, 'dashboard_view' => true]);
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id]);
        $u->empresas()->attach($e->id);
        $h = $this->entrar($u) + ['X-Empresa-Id' => $e->id];

        $dados = $this->getJson('/api/sistema/menu', $h)->assertOk()->json('dados');
        $ecras = collect($dados['menu'])->flatMap(fn ($m) => array_column($m['ecras'], 'id'))->all();
        $this->assertNotContains('ia_chat', $ecras);
        $this->assertContains('dashboard', $ecras);
        $this->assertContains('ia_chat_view', $dados['permissoes']);   // a permissão do Gate mantém-se
        $this->assertTrue(app(ServicoPermissoes::class)->tem($u, 'ia_chat_regras', $e->id));
    }

    public function test_assistente_ia_regras_da_contabilidade_continua_no_menu(): void
    {
        $e = $this->criarEmpresa();
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'ia_assistente_view' => true])->id]);
        $u->empresas()->attach($e->id);
        $menu = $this->getJson('/api/sistema/menu', $this->entrar($u) + ['X-Empresa-Id' => $e->id])->assertOk()->json('dados.menu');
        $this->assertContains('ia_assistente', collect($menu)->flatMap(fn ($m) => array_column($m['ecras'], 'id'))->all());
    }
}
