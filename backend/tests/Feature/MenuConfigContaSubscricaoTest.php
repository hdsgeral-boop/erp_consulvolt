<?php

namespace Tests\Feature;

use App\Models\Utilizador;
use App\Services\Sistema\CatalogoPermissoes;
use App\Support\Tenancy\ContextoTenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Menu: o Assistente IA (regras) passou de Contabilidade para Configurações (as chaves de permissão não mudam) e os ecrãs
 * `plataforma_*` ficam no catálogo (Gate, editor de perfis da plataforma) mas só o menu de quem é da plataforma os devolve:
 * o menu do cliente fica com «Licença e Plano» e «Minha Conta» (a consola /admin gere o resto).
 */
final class MenuConfigContaSubscricaoTest extends TestCase
{
    /** @return array<string, list<string>> módulo → ids dos ecrãs devolvidos por GET /sistema/menu */
    private function menuDe(Utilizador $u, ?int $empresaId = null): array
    {
        $h = $this->entrar($u) + ($empresaId ? ['X-Empresa-Id' => $empresaId] : []);
        $menu = $this->getJson('/api/sistema/menu', $h)->assertOk()->json('dados.menu');

        return collect($menu)->mapWithKeys(fn ($m) => [$m['id'] => array_column($m['ecras'], 'id')])->all();
    }

    #[Test]
    public function catalogo_assistente_ia_vive_em_config_com_as_mesmas_chaves(): void
    {
        $modulos = collect(CatalogoPermissoes::completo()['modulos'])->mapWithKeys(fn ($m) => [$m['id'] => array_column($m['ecras'], 'id')]);
        $this->assertContains('ia_assistente', $modulos['config']);
        $this->assertNotContains('ia_assistente', $modulos['contab']);
        $this->assertSame(1, $modulos->filter(fn ($ecras) => in_array('ia_assistente', $ecras, true))->count());

        $tarefas = CatalogoPermissoes::tarefas();
        $this->assertSame('ia_assistente', $tarefas['ia_regras']['ecra']);
        $this->assertSame('ia_assistente', $tarefas['ia_lancar']['ecra']);
    }

    #[Test]
    public function assistente_ia_so_aparece_em_configuracoes_a_quem_tem_a_permissao(): void
    {
        $e = $this->criarEmpresa();
        $com = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'ia_assistente_view' => true, 'lancamentos_view' => true])->id]);
        $sem = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'lancamentos_view' => true])->id]);
        $com->empresas()->attach($e->id);
        $sem->empresas()->attach($e->id);

        $menuCom = $this->menuDe($com, $e->id);
        $this->assertContains('ia_assistente', $menuCom['config'] ?? []);
        $this->assertNotContains('ia_assistente', $menuCom['contab'] ?? []);

        $menuSem = $this->menuDe($sem, $e->id);
        $this->assertNotContains('ia_assistente', collect($menuSem)->flatten()->all());
        $this->assertArrayHasKey('contab', $menuSem);
    }

    #[Test]
    public function cliente_nao_recebe_ecras_da_plataforma_mesmo_com_perfil_de_plataforma(): void
    {
        $e = $this->criarEmpresa();
        $perfil = $this->criarPerfil(['_v2' => true, 'config_licenca_view' => true, 'minha_conta_view' => true, 'plataforma_clientes_view' => true, 'plataforma_painel_ver' => true, 'plataforma_ia_view' => true]);
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id]);
        $u->empresas()->attach($e->id);

        $ecras = $this->menuDe($u, $e->id)['config'] ?? [];
        $this->assertContains('config_licenca', $ecras);
        $this->assertContains('minha_conta', $ecras);
        $this->assertSame([], array_values(array_filter($ecras, fn ($x) => str_starts_with($x, 'plataforma_'))));
    }

    #[Test]
    public function utilizador_da_plataforma_continua_a_receber_os_ecras_plataforma_no_menu_do_servidor(): void
    {
        app(ContextoTenant::class)->definir(1);
        $u = $this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]);

        $ecras = $this->menuDe($u, $this->criarEmpresa()->id)['config'] ?? [];
        $this->assertContains('plataforma_clientes', $ecras);
        $this->assertContains('plataforma_cupoes', $ecras);
        $this->assertContains('config_licenca', $ecras);
    }
}
