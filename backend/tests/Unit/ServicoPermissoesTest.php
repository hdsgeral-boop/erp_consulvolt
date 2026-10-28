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
        // config_logs_view fica de fora de efectivas() (Logs de Navegação é reservado a Super Administradores,
        // nenhuma empresa tem Super Administradores) — tem() continua true acima: é a computação em bruto do
        // perfil, quem decide o acesso real é autoriza()/efectivas() (tal como a holding e o plano do cliente).
        // config_licenca_view entra sempre (ECRAS_TODOS_OS_UTILIZADORES), mesmo sem estar marcado no perfil.
        $this->assertSame(['config_licenca_view', 'config_migracao_view'], $this->permissoes->efectivas($u));
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
        // config_licenca_view entra sempre (ECRAS_TODOS_OS_UTILIZADORES) — estado da própria licença e suporte.
        $this->assertSame(['config_licenca_view'], $this->permissoes->efectivas($u));
    }

    #[Test]
    public function ecras_de_plataforma_ficam_escondidos_de_quem_nao_e_elegivel_mesmo_com_acesso_total(): void
    {
        // Acesso total (perfil all=true) de um tenant que não é o da plataforma: vê tudo excepto os ecrãs plataforma_*
        // (administração do SaaS) — o mesmo que ExigirPlataforma já impede no servidor para estas rotas.
        $outroTenant = \App\Models\Tenant::create(['nome' => 'Outro Cliente', 'slug' => 'outro-cliente-'.random_int(1000, 99999), 'estado' => \App\Models\Tenant::ESTADO_ATIVO]);
        $cliente = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['all' => true])->id]);
        $cliente->forceFill(['tenant_id' => $outroTenant->id])->save();
        $this->assertTrue($this->permissoes->tem($cliente, 'dashboard_view'));
        $this->assertTrue($this->permissoes->tem($cliente, 'config_licenca_view'));
        $this->assertFalse($this->permissoes->tem($cliente, 'plataforma_clientes_view'));
        $this->assertFalse($this->permissoes->tem($cliente, 'plataforma_saas_view'));
        $this->assertFalse($this->permissoes->elegivelPlataforma($cliente));

        // Super-administrador do tenant da própria plataforma: continua a ver tudo, incluindo plataforma_*.
        $plataforma = $this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR]);
        $plataforma->forceFill(['tenant_id' => (int) config('erp.dominio.plataforma_tenant_id', 1)])->save();
        $this->assertTrue($this->permissoes->tem($plataforma, 'plataforma_clientes_view'));
        $this->assertTrue($this->permissoes->elegivelPlataforma($plataforma));
    }

    #[Test]
    public function ecras_de_suporte_ficam_escondidos_de_uma_empresa_cliente_mesmo_com_acesso_total(): void
    {
        // O perfil "Acesso Total" (all=true) é atribuído por omissão ao administrador de uma empresa cliente —
        // o módulo `suporte` (ADR-077, só para especializar agentes do backoffice) não deve aparecer no menu
        // dessa empresa, mesmo assim (bug relatado: "Backoffice de Suporte" a aparecer no menu de um cliente normal).
        $outroTenant = \App\Models\Tenant::create(['nome' => 'Outro Cliente', 'slug' => 'outro-cliente-'.random_int(1000, 99999), 'estado' => \App\Models\Tenant::ESTADO_ATIVO]);
        $cliente = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['all' => true])->id]);
        $cliente->forceFill(['tenant_id' => $outroTenant->id])->save();
        $this->assertFalse($this->permissoes->tem($cliente, 'suporte_tickets_geral_gerir'));
        $this->assertFalse($this->permissoes->tem($cliente, 'suporte_tickets_geral_view'));
        $this->assertFalse($this->permissoes->elegivelSuporte($cliente));
        $idsModulos = array_column($this->permissoes->menu($cliente), 'id');
        $this->assertNotContains('suporte', $idsModulos);

        // Um utilizador SUPER_ADMINISTRADOR de outra empresa (a invariante é que isso nunca acontece, mas a
        // blindagem não deve depender só disso) também não vê `suporte_*`, apesar de total() ser sempre true.
        $superDeOutroTenant = $this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR]);
        $superDeOutroTenant->forceFill(['tenant_id' => $outroTenant->id])->save();
        $this->assertFalse($this->permissoes->tem($superDeOutroTenant, 'suporte_tickets_geral_gerir'));

        // Agente de suporte da plataforma, com perfil de especialização «Financeiro»: só vê a sua categoria.
        $plataformaId = (int) config('erp.dominio.plataforma_tenant_id', 1);
        $agente = $this->criarUtilizador([
            'papel' => Utilizador::PAPEL_AGENTE_SUPORTE,
            'perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'suporte_tickets_financeiro_view' => true, 'suporte_tickets_financeiro_gerir' => true])->id,
        ]);
        $agente->forceFill(['tenant_id' => $plataformaId])->save();
        $this->assertTrue($this->permissoes->elegivelSuporte($agente));
        $this->assertTrue($this->permissoes->tem($agente, 'suporte_tickets_financeiro_gerir'));
        $this->assertFalse($this->permissoes->tem($agente, 'suporte_tickets_geral_gerir'));
    }

    #[Test]
    public function licenca_e_plano_fica_visivel_a_qualquer_utilizador_mesmo_sem_nenhum_perfil(): void
    {
        // config_licenca_view (ECRAS_TODOS_OS_UTILIZADORES): estado da própria licença e suporte, para qualquer
        // utilizador autenticado da empresa — ao contrário de config_licenca_gerir (pedir renovação, pagamentos).
        $u = $this->criarUtilizador();

        $this->assertTrue($this->permissoes->tem($u, 'config_licenca_view'));
        $this->assertFalse($this->permissoes->tem($u, 'config_licenca_gerir'));
        $this->assertContains('config_licenca_view', $this->permissoes->efectivas($u));
    }
}
