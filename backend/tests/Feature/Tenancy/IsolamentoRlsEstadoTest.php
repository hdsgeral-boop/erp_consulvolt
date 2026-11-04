<?php

namespace Tests\Feature\Tenancy;

use App\Support\Tenancy\IsolamentoRls;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Estado REAL do isolamento (IsolamentoRls::estado): o indicador só fica verde com SaaS ligado, ligação sem privilégios,
 * não dona das tabelas, row_security activo e políticas completas. A ligação de teste é do dono (ignora a RLS), por isso
 * o cenário «tudo verde» muda para a role `erp_app` (`SET ROLE`).
 */
class IsolamentoRlsEstadoTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::statement('RESET ROLE');
        DB::selectOne("SELECT set_config('app.tenant_id', '', false)");

        parent::tearDown();
    }

    public function test_indicador_so_fica_verde_com_saas_ligacao_sem_privilegios_e_politicas(): void
    {
        $rls = new IsolamentoRls(DB::connection());

        config(['erp.saas.ativo' => false]);
        $estado = $rls->estado();
        $this->assertFalse($estado['ativo']);
        $this->assertFalse($estado['verificacoes']['saas_ativo']['ok']);
        $this->assertNotEmpty($estado['verificacoes']['saas_ativo']['motivo']);
        $this->assertTrue($estado['verificacoes']['politicas']['ok']);
        foreach (['saas_ativo', 'ligacao_sem_privilegios', 'nao_dona_das_tabelas', 'row_security', 'politicas'] as $chave) {
            $this->assertArrayHasKey($chave, $estado['verificacoes']);
        }

        // Ligação privilegiada (dono/superutilizador): continua a não estar efectivo.
        config(['erp.saas.ativo' => true]);
        $papel = DB::selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        if ($papel->rolsuper || $papel->rolbypassrls) {
            $this->assertFalse($rls->estado()['verificacoes']['ligacao_sem_privilegios']['ok']);
        }

        // Como erp_app, com SaaS ligado: tudo verdadeiro.
        DB::statement('SET ROLE erp_app');
        $estado = $rls->estado();
        $this->assertSame([], $estado['tabelas_sem_rls']);
        $this->assertTrue($estado['ativo'], json_encode($estado['verificacoes']));
        $this->assertSame('erp_app', $estado['utilizador']);
    }

    public function test_preparar_role_nao_deixa_a_role_com_superuser_nem_bypassrls(): void
    {
        (new IsolamentoRls(DB::connection()))->prepararRole();

        $r = DB::selectOne("SELECT rolsuper, rolbypassrls, rolcanlogin FROM pg_roles WHERE rolname = 'erp_app'");
        $this->assertFalse((bool) $r->rolsuper);
        $this->assertFalse((bool) $r->rolbypassrls);
    }
}
