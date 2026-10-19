<?php

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;
use App\Support\Tenancy\IsolamentoRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SaaS fase 2 (ADR-073). Contrato: nenhuma tabela com `empresa_id` fica sem política RLS. E prova real do isolamento:
 * a ligação de teste é superuser (ignora a RLS); aqui muda-se para a role `erp_app` (`SET ROLE`), igual à da produção.
 */
class IsolamentoRlsContratoTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::statement('RESET ROLE');
        DB::selectOne("SELECT set_config('app.tenant_id', '', false)");

        parent::tearDown();
    }

    public function test_nenhuma_tabela_com_empresa_id_fica_sem_politica(): void
    {
        $faltam = (new IsolamentoRls(DB::connection()))->tabelasSemPolitica();

        $this->assertSame(
            [],
            $faltam,
            'Tabelas com empresa_id sem RLS: '.implode(', ', $faltam)
            .'. A migração que as criou deve chamar (new IsolamentoRls(DB::connection()))->aplicarATodas() no fim.',
        );
    }

    public function test_role_da_aplicacao_nao_ignora_a_rls_nem_e_superuser(): void
    {
        $r = DB::selectOne("SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = 'erp_app'");

        $this->assertNotNull($r);
        $this->assertFalse($r->rolsuper);
        $this->assertFalse($r->rolbypassrls);
    }

    public function test_cada_tenant_so_ve_e_escreve_os_seus_dados(): void
    {
        $empresaA = $this->criarEmpresa();
        $empresaB = $this->criarEmpresa();
        $outro = Tenant::query()->create(['nome' => 'Cliente B', 'slug' => 'cliente-b']);
        DB::table('empresas')->where('id', $empresaB->id)->update(['tenant_id' => $outro->id]);
        DB::table('armazens')->insert([['empresa_id' => $empresaA->id], ['empresa_id' => $empresaB->id]]);

        DB::statement('SET ROLE erp_app');

        // Sem tenant definido: fail-closed.
        $this->assertSame(0, DB::table('armazens')->count());
        $this->assertSame(0, DB::table('empresas')->count());

        $this->definirTenant(1);
        $this->assertSame([$empresaA->id], DB::table('armazens')->pluck('empresa_id')->all());
        $this->assertSame([1], DB::table('empresas')->pluck('tenant_id')->unique()->values()->all());
        $this->assertNull(DB::table('empresas')->where('id', $empresaB->id)->first());

        $this->definirTenant($outro->id);
        $this->assertSame([$empresaB->id], DB::table('armazens')->pluck('empresa_id')->all());

        // Escrever para uma empresa de outro tenant é recusado (WITH CHECK).
        $this->definirTenant(1);
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('armazens')->insert(['empresa_id' => $empresaB->id]));
    }

    public function test_a_role_da_aplicacao_nao_altera_nem_apaga_a_auditoria(): void
    {
        DB::statement('SET ROLE erp_app');

        foreach (['DELETE FROM logs_auditoria WHERE false', 'UPDATE logs_auditoria SET ocorrido_em = ocorrido_em WHERE false'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                $this->fail("Devia ser recusado: {$sql}");
            } catch (QueryException $e) {
                $this->assertStringContainsString('permission denied', $e->getMessage());
            }
        }
    }

    private function definirTenant(int $id): void
    {
        DB::selectOne("SELECT set_config('app.tenant_id', ?, false)", [(string) $id]);
    }
}
