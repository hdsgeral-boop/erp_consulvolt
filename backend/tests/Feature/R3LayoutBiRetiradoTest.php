<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Services\Sistema\CatalogoPermissoes;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «BI contabilístico e Gestão documental» eliminado a pedido do utilizador (17/10/2026): fora do catálogo, do menu, dos
 * perfis-tipo e das rotas; a migração retira as chaves do ecrã dos perfis gravados sem tocar no resto.
 */
final class R3LayoutBiRetiradoTest extends TestCase
{
    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa();
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    #[Test]
    public function o_ecra_saiu_do_catalogo_dos_perfis_tipo_e_do_menu(): void
    {
        $this->assertArrayNotHasKey('accounting_bi', CatalogoPermissoes::ecras());
        $this->assertNull(CatalogoPermissoes::ecraDaVista('sgd'));
        $this->assertFalse(CatalogoPermissoes::chaveValida('accounting_bi_view'));
        foreach (CatalogoPermissoes::modelos() as $m) {
            $this->assertArrayNotHasKey('accounting_bi_view', $m['permissoes'], $m['nome']);
        }

        $menu = $this->getJson('/api/sistema/menu', $this->sessao(['all']))->assertOk()->json('dados.menu');
        $ids = collect($menu)->flatMap(fn ($m) => array_column($m['ecras'], 'id'))->all();
        $this->assertContains('dashboard', $ids);
        $this->assertNotContains('accounting_bi', $ids);
    }

    #[Test]
    public function as_rotas_do_bi_deixaram_de_existir(): void
    {
        $s = $this->sessao(['all']);
        $this->getJson('/api/gestao/bi', $s)->assertNotFound();
        $this->postJson('/api/gestao/bi/consultar', ['periodo' => 'todo'], $s)->assertNotFound();
        // o cubo (Análise Dinâmica do Dashboard) continua
        $this->getJson('/api/gestao/cubo/conjuntos', $s)->assertOk();
    }

    #[Test]
    public function a_migracao_retira_as_chaves_do_ecra_dos_perfis_e_deixa_o_resto(): void
    {
        $com = $this->criarPerfil(['_v2' => true, 'dashboard_view' => true, 'accounting_bi_view' => true, 'sgd_view' => true], 'Com BI');
        $so = $this->criarPerfil(['_v2' => true, 'accounting_bi_view' => true], 'Só BI');
        $sem = $this->criarPerfil(['_v2' => true, 'dashboard_view' => true], 'Sem BI');
        $total = $this->criarPerfil(['all' => true], 'Total');

        $migracao = require database_path('migrations/2026_10_17_043100_r3_layout_retirar_bi_contabilistico_dos_perfis.php');
        $migracao->up();
        $migracao->up();   // idempotente

        $ler = fn ($p) => json_decode(DB::table('perfis_utilizador')->where('id', $p->id)->value('permissoes'), true);
        $this->assertEquals(['_v2' => true, 'dashboard_view' => true], $ler($com));
        $this->assertEquals(['_v2' => true], $ler($so));
        $this->assertEquals(['_v2' => true, 'dashboard_view' => true], $ler($sem));
        $this->assertEquals(['all' => true], $ler($total));
    }
}
