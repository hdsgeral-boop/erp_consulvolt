<?php

namespace Tests\Feature\Tenancy;

use App\Mail\LicencaAVencerMail;
use App\Mail\LicencaExpiradaMail;
use App\Models\Empresa;
use App\Models\Utilizador;
use App\Services\Sistema\ServicoPermissoes;
use App\Support\Tenancy\ModulosPlano;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Restos das licenças da v6 (pedido de 2026-10-22): módulos limitados ao plano do cliente, Logs só para o Super
 * Administrador, exportações bloqueadas com a licença expirada e avisos por e-mail de licença a expirar.
 */
final class LicencasPlanoModulosTest extends TestCase
{
    private function plano(string $codigo, array $modulos): void
    {
        $id = DB::table('planos_licenca')->insertGetId(['codigo' => $codigo, 'nome' => "Plano {$codigo}", 'preco_mensal' => 0, 'moeda' => 'AOA', 'ativo' => true]);
        DB::table('planos_licenca_versoes')->insert(['plano_id' => $id, 'numero' => 1, 'estado' => 'PUBLICADA', 'modulos' => json_encode($modulos), 'publicada_em' => now()]);
        ModulosPlano::invalidar();
    }

    /** @return list<string> módulos do menu */
    private function modulosDoMenu(Utilizador $u, int $empresa): array
    {
        return array_column(app(ServicoPermissoes::class)->menu($u, $empresa), 'id');
    }

    public function test_o_plano_do_cliente_limita_os_modulos_mesmo_com_acesso_total(): void
    {
        $empresa = $this->criarEmpresa();
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['all' => true], 'Total')->id, 'acesso_todas_empresas' => true]);
        $permissoes = app(ServicoPermissoes::class);
        $this->assertContains('rh', $this->modulosDoMenu($u, $empresa->id));

        $this->plano('basico', ['vendas', 'pos', 'stock', 'teso']);
        DB::table('tenants')->where('id', $u->tenant_id)->update(['plano' => 'basico']);
        ModulosPlano::invalidar();
        $u = $u->fresh();
        $permissoes = new ServicoPermissoes;   // sem a memória do pedido anterior

        $menu = array_column($permissoes->menu($u, $empresa->id), 'id');
        $this->assertContains('vendas', $menu);
        $this->assertNotContains('rh', $menu);
        $this->assertNotContains('contab', $menu);
        $this->assertContains('config', $menu, 'módulos fora do catálogo de planos ficam sempre');
        $this->assertFalse($permissoes->autoriza($u, 'rh_portal_gestao_view', $empresa->id));
    }

    public function test_logs_so_para_o_super_administrador(): void
    {
        $empresa = $this->criarEmpresa();
        $total = $this->criarPerfil(['all' => true], 'Total');
        $admin = $this->criarUtilizador(['papel' => Utilizador::PAPEL_ADMINISTRADOR, 'perfil_utilizador_id' => $total->id, 'acesso_todas_empresas' => true]);
        $super = $this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]);

        $this->getJson('/api/sistema/logs', $this->entrar($admin) + ['X-Empresa-Id' => $empresa->id])->assertForbidden();
        $this->getJson('/api/sistema/logs', $this->entrar($super) + ['X-Empresa-Id' => $empresa->id])->assertOk();
    }

    public function test_exportacao_bloqueada_com_a_licenca_expirada(): void
    {
        config(['erp.licencas.ativo' => true]);
        $empresa = $this->criarEmpresa();
        DB::table('empresas')->where('id', $empresa->id)->update(['licenca_origem' => Empresa::LICENCA_PLANO, 'licenca_fim' => now()->subDay()->toDateString()]);
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['all' => true], 'Total')->id]);
        $u->empresas()->attach($empresa->id);

        $this->getJson('/api/vendas/saft?inicio=2026-01-01&fim=2026-01-31', $this->entrar($u) + ['X-Empresa-Id' => $empresa->id])
            ->assertStatus(403)->assertJsonPath('codigo', 'LICENCA_EXPIRADA');
    }

    public function test_avisos_de_licenca_nos_dias_certos_aos_administradores_da_empresa(): void
    {
        config(['erp.licencas.ativo' => true]);
        Mail::fake();
        $daqui7 = $this->criarEmpresa(['nome' => 'Sete, Lda']);
        $hoje = $this->criarEmpresa(['nome' => 'Hoje, Lda']);
        $daqui5 = $this->criarEmpresa(['nome' => 'Cinco, Lda']);
        foreach ([[$daqui7, 7], [$hoje, 0], [$daqui5, 5]] as [$e, $d]) {
            DB::table('empresas')->where('id', $e->id)->update(['licenca_origem' => Empresa::LICENCA_PLANO, 'licenca_fim' => now()->addDays($d)->toDateString()]);
        }
        $this->criarUtilizador(['papel' => Utilizador::PAPEL_ADMINISTRADOR, 'email' => 'adm@exemplo.test', 'acesso_todas_empresas' => true]);
        $this->criarUtilizador(['papel' => Utilizador::PAPEL_UTILIZADOR, 'email' => 'comum@exemplo.test', 'acesso_todas_empresas' => true]);

        $this->artisan('erp:licencas:avisar')->assertSuccessful();

        Mail::assertQueued(LicencaAVencerMail::class, 1);
        Mail::assertQueued(LicencaExpiradaMail::class, 1);
        Mail::assertNotQueued(LicencaAVencerMail::class, fn ($m) => $m->hasTo('comum@exemplo.test'));
    }
}
