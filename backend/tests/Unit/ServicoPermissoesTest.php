<?php

namespace Tests\Unit;

use App\Models\Colaborador;
use App\Models\Utilizador;
use App\Services\Sistema\ServicoPermissoes;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Semântica de js/permissoes.js:591-599. */
final class ServicoPermissoesTest extends TestCase
{
    private ServicoPermissoes $permissoes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->permissoes = new ServicoPermissoes;
    }

    #[Test]
    public function super_administrador_tem_tudo(): void
    {
        $u = $this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR]);

        $this->assertTrue($this->permissoes->tem($u, 'qualquer_coisa'));
        $this->assertSame(['*'], $this->permissoes->efectivas($u));
    }

    #[Test]
    public function perfil_com_all_true_tem_tudo(): void
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['all' => true])->id]);

        $this->assertTrue($this->permissoes->tem($u, 'lancamentos_post'));
    }

    #[Test]
    public function perfil_v2_so_concede_o_que_esta_marcado_e_normaliza_acentos(): void
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil([
            '_v2' => true, 'config_logs_view' => true, 'config_migracao_view' => true, 'vendas_fat_emitir' => false,
        ])->id]);

        $this->assertTrue($this->permissoes->tem($u, 'config_logs_view'));
        $this->assertTrue($this->permissoes->tem($u, 'CONFIG_MIGRAÇÃO_VIEW'));   // norm(): sem acentos, minúsculas
        $this->assertFalse($this->permissoes->tem($u, 'vendas_fat_emitir'));      // só `true` concede
        $this->assertFalse($this->permissoes->tem($u, 'lancamentos_post'));
        $this->assertTrue($this->permissoes->formatoV2($u));
        $this->assertSame(['config_logs_view', 'config_migracao_view'], $this->permissoes->efectivas($u));
    }

    #[Test]
    public function portal_do_colaborador_e_automatico_para_quem_esta_ligado_a_um_colaborador_na_empresa(): void
    {
        $empresaA = $this->criarEmpresa();
        $empresaB = $this->criarEmpresa();
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true])->id]);
        $colaborador = app(ContextoEmpresa::class)->executarComo($empresaA->id,
            fn () => Colaborador::create(['nome_completo' => 'Colaborador de Teste', 'estado' => 'ACTIVO']));
        $u->empresas()->attach($empresaA->id, ['colaborador_id' => $colaborador->id]);
        $u->empresas()->attach($empresaB->id, ['colaborador_id' => null]);

        $this->assertTrue($this->permissoes->tem($u, 'rh_portal_view', $empresaA->id));
        $this->assertTrue($this->permissoes->tem($u, 'rh_portal_usar', $empresaA->id));
        $this->assertFalse($this->permissoes->tem($u, 'rh_portal_view', $empresaB->id));
        $this->assertFalse($this->permissoes->tem($u, 'rh_portal_view'));
        $this->assertFalse($this->permissoes->tem($u, 'colaboradores_view', $empresaA->id));
    }

    #[Test]
    public function utilizador_sem_perfil_nao_tem_permissoes(): void
    {
        $u = $this->criarUtilizador();

        $this->assertFalse($this->permissoes->tem($u, 'dashboard_view'));
        $this->assertSame([], $this->permissoes->efectivas($u));
    }
}
