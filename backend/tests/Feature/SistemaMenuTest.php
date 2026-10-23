<?php

namespace Tests\Feature;

use App\Models\Utilizador;
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
        // cada ecrã traz só as funções (tarefas) que o utilizador pode fazer — índice da pesquisa «Procurar»
        $ecras = collect($d['menu'])->flatMap(fn ($m) => $m['ecras'])->keyBy('id');
        $this->assertSame([], $ecras['vendas_faturacao']['tarefas']);
        $this->assertContains('pos_venda', array_column($ecras->flatMap(fn ($e) => $e['tarefas'])->all(), 'chave'));
        $this->assertNotContains('vendas_fat_emitir', array_column($ecras->flatMap(fn ($e) => $e['tarefas'])->all(), 'chave'));
        $this->getJson('/api/sistema/menu', $this->entrar($u))->assertStatus(422);   // sem empresa activa (fail-closed)
    }

    #[Test]
    public function identidade_da_empresa_activa_para_qualquer_utilizador_da_empresa(): void
    {
        $logo = 'data:image/png;base64,iVBORw0KGgo=';
        $empresa = $this->criarEmpresa(['nome' => 'Empresa Demo', 'nif' => '5999000001', 'endereco' => 'Rua A', 'provincia' => 'Luanda', 'logotipo' => $logo]);
        $outra = $this->criarEmpresa();
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'pos_venda' => true])->id]);
        $u->empresas()->attach($empresa->id);
        $s = $this->entrar($u);

        $this->getJson('/api/sistema/identidade', $s + ['X-Empresa-Id' => $empresa->id])->assertOk()
            ->assertJsonPath('dados.nome', 'Empresa Demo')->assertJsonPath('dados.nif', '5999000001')
            ->assertJsonPath('dados.morada', 'Rua A, Luanda')->assertJsonPath('dados.logotipo', $logo);
        $this->getJson('/api/sistema/identidade', $s + ['X-Empresa-Id' => $outra->id])->assertForbidden();
    }

    /**
     * Logs de Navegação é reservado a Super Administradores: mesmo um administrador de empresa com acesso total
     * («all») não o vê no menu nem o tem em `permissoes`. Manutenção de dados continua no menu de quem tem a
     * permissão (é partilhada com Validações de dados, um ecrã só de leitura) — é só o fluxo destrutivo de
     * pedido/aprovação, dentro do próprio ecrã, que fica reservado a Super Administradores (ver Manutencao.tsx e
     * ManutencaoDadosController).
     */
    #[Test]
    public function logs_de_navegacao_fica_reservado_a_super_administradores_mas_manutencao_de_dados_continua_no_menu(): void
    {
        $empresa = $this->criarEmpresa();
        $admin = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['all' => true])->id]);
        $admin->empresas()->attach($empresa->id);
        $s = $this->entrar($admin) + ['X-Empresa-Id' => $empresa->id];

        $d = $this->getJson('/api/sistema/menu', $s)->assertOk()->json('dados');
        $ecras = collect($d['menu'])->flatMap(fn ($m) => $m['ecras'])->pluck('id')->all();
        $this->assertContains('config_manutencao', $ecras);
        $this->assertNotContains('config_logs', $ecras);
        $this->assertContains('config_perfis', $ecras);

        $superAdmin = $this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR]);
        $sa = $this->entrar($superAdmin) + ['X-Empresa-Id' => $empresa->id];
        $daSuperAdmin = $this->getJson('/api/sistema/menu', $sa)->assertOk()->json('dados');
        $ecrasSuperAdmin = collect($daSuperAdmin['menu'])->flatMap(fn ($m) => $m['ecras'])->pluck('id')->all();
        $this->assertContains('config_manutencao', $ecrasSuperAdmin);
        $this->assertContains('config_logs', $ecrasSuperAdmin);
    }
}
