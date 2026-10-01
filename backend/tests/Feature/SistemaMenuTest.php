<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Menu da aplicação (Fase 5): só os módulos e ecrãs que o utilizador pode ver na empresa activa. */
final class SistemaMenuTest extends TestCase
{
    #[Test]
    public function menu_mostra_so_os_ecras_permitidos(): void
    {
        $empresa = $this->criarEmpresa();
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'vendas_faturacao_view' => true, 'pos_venda' => true])->id]);
        $u->empresas()->attach($empresa->id);
        $s = $this->entrar($u) + ['X-Empresa-Id' => $empresa->id];

        $d = $this->getJson('/api/sistema/menu', $s)->assertOk()->json('dados');
        $modulos = array_column($d['menu'], 'id');
        $this->assertContains('vendas', $modulos);
        $this->assertContains('pos', $modulos);
        $this->assertNotContains('rh', $modulos);
        $this->assertNotContains('config', $modulos);
        $this->assertContains('vendas_faturacao_view', $d['permissoes']);
        $this->getJson('/api/sistema/menu', $this->entrar($u))->assertStatus(422);   // sem empresa activa (fail-closed)
    }
}
