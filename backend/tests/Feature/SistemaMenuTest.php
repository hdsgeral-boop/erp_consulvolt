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
        // O centro de notificações é sempre visível (ServicoPermissoes::ecraVisivel) e vive no módulo «config»:
        // o módulo pode aparecer, mas só com esse ecrã.
        $config = collect($d['menu'])->firstWhere('id', 'config');
        $this->assertSame(['notificacoes_centro'], $config ? array_column($config['ecras'], 'id') : ['notificacoes_centro']);
        $this->assertContains('vendas_faturacao_view', $d['permissoes']);
        // cada ecrã traz só as funções (tarefas) que o utilizador pode fazer — índice da pesquisa «Procurar»
        $ecras = collect($d['menu'])->flatMap(fn ($m) => $m['ecras'])->keyBy('id');
        $this->assertSame([], $ecras['vendas_faturacao']['tarefas']);
        $this->assertContains('pos_venda', array_column($ecras->flatMap(fn ($e) => $e['tarefas'])->all(), 'chave'));
        $this->assertNotContains('vendas_fat_emitir', array_column($ecras->flatMap(fn ($e) => $e['tarefas'])->all(), 'chave'));
        $this->getJson('/api/sistema/menu', $this->entrar($u))->assertStatus(422);   // sem empresa activa (fail-closed)
    }

    #[Test]
    public function menu_do_erp_nunca_traz_os_ecras_da_plataforma_saas(): void
    {
        // Super-administrador (vê todo o catálogo): a administração da plataforma vive noutra aplicação e não aparece no menu do ERP.
        $empresa = $this->criarEmpresa();
        $super = $this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]);
        $s = $this->entrar($super) + ['X-Empresa-Id' => $empresa->id];

        $d = $this->getJson('/api/sistema/menu', $s)->assertOk()->json('dados');
        $ids = collect($d['menu'])->flatMap(fn ($m) => array_column($m['ecras'], 'id'))->all();

        $this->assertNotEmpty($ids);
        $this->assertSame([], array_values(array_filter($ids, fn ($id) => str_starts_with($id, 'plataforma_'))));
        // o que é do cliente continua lá
        $this->assertContains('config_licenca', $ids);
        $this->assertContains('minha_conta', $ids);
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
}
