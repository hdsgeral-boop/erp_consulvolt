<?php

namespace Tests\Feature\Sistema;

use App\Models\LogAuditoria;
use App\Services\Sistema\ServicoAuditoria;
use App\Support\Tenancy\ContextoTenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Na produção `logs_auditoria` tem RLS (política de inserção aberta, leitura por tenant) e um trigger que preenche o
 * `tenant_id`. Uma auditoria sem tenant, empresa nem utilizador (tarefas agendadas como os câmbios do BAI) ficava com
 * `tenant_id` nulo e o `INSERT … RETURNING id` falhava com «new row violates row-level security policy». Este teste
 * imita essas políticas (dentro da transação do teste) e prova que a auditoria de sistema passa a gravar.
 */
class AuditoriaSemContextoRlsTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::statement('RESET ROLE');
        DB::selectOne("SELECT set_config('app.tenant_id', '', false)");

        parent::tearDown();
    }

    private function imitarRlsDaProducao(): void
    {
        DB::statement('ALTER TABLE logs_auditoria ADD COLUMN IF NOT EXISTS tenant_id bigint');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION erp_logs_auditoria_tenant() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
            BEGIN
                IF NEW.tenant_id IS NULL THEN
                    NEW.tenant_id := COALESCE(app_tenant_id(),
                        (SELECT e.tenant_id FROM empresas e WHERE e.id = NEW.empresa_id),
                        (SELECT u.tenant_id FROM utilizadores u WHERE u.id = NEW.utilizador_id));
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement('DROP TRIGGER IF EXISTS logs_auditoria_tenant ON logs_auditoria');
        DB::statement('CREATE TRIGGER logs_auditoria_tenant BEFORE INSERT ON logs_auditoria FOR EACH ROW EXECUTE FUNCTION erp_logs_auditoria_tenant()');
        DB::statement('ALTER TABLE logs_auditoria ENABLE ROW LEVEL SECURITY');
        foreach (['auditoria_inserir', 'auditoria_ler_plataforma', 'auditoria_ler_tenant'] as $politica) {
            DB::statement("DROP POLICY IF EXISTS {$politica} ON logs_auditoria");
        }
        DB::statement('CREATE POLICY auditoria_inserir ON logs_auditoria FOR INSERT WITH CHECK (true)');
        DB::statement('CREATE POLICY auditoria_ler_plataforma ON logs_auditoria FOR SELECT USING (app_tenant_id() = 1)');
        DB::statement('CREATE POLICY auditoria_ler_tenant ON logs_auditoria FOR SELECT USING (tenant_id = app_tenant_id())');
        DB::statement('GRANT SELECT, INSERT ON logs_auditoria TO erp_app');
    }

    public function test_sem_o_ajuste_a_auditoria_sem_origem_e_recusada_pela_rls_como_na_producao(): void
    {
        $this->imitarRlsDaProducao();
        config(['erp.saas.ativo' => true]);
        DB::statement('SET ROLE erp_app');

        try {
            // ponto de salvaguarda: a recusa da RLS aborta a transação do teste e o resto do teste precisa dela
            DB::transaction(fn () => LogAuditoria::create(['ocorrido_em' => now(), 'modulo' => 'Sistema/Moedas', 'acao' => 'Câmbios do BAI obtidos', 'nome_utilizador' => 'sistema']));
            $this->fail('devia ser recusada pela RLS');
        } catch (QueryException $e) {
            $this->assertStringContainsString('row-level security', $e->getMessage());
        }
    }

    public function test_a_auditoria_de_sistema_sem_tenant_empresa_nem_utilizador_grava_no_contexto_da_plataforma(): void
    {
        $this->imitarRlsDaProducao();
        config(['erp.saas.ativo' => true, 'erp.dominio.plataforma_tenant_id' => 1]);
        DB::statement('SET ROLE erp_app');
        $tenant = app(ContextoTenant::class);
        $tenant->definir(null);

        $log = app(ServicoAuditoria::class)->registar('Sistema/Moedas', 'Câmbios do BAI obtidos', 'Obtenção agendada', 'execucoes_cambios_bai', 24);

        $this->assertNotNull($log->getKey(), 'o INSERT … RETURNING id já não é recusado');
        DB::statement('RESET ROLE');
        $this->assertSame(1, (int) DB::table('logs_auditoria')->where('id', $log->getKey())->value('tenant_id'), 'fica no tenant da plataforma');
        $this->assertNull($tenant->id(), 'o contexto de tenant volta ao que era (nenhum)');
    }

    public function test_com_tenant_activo_a_auditoria_continua_no_tenant_do_pedido(): void
    {
        $this->imitarRlsDaProducao();
        config(['erp.saas.ativo' => true, 'erp.dominio.plataforma_tenant_id' => 1]);
        DB::statement('SET ROLE erp_app');
        $tenant = app(ContextoTenant::class);
        $tenant->definir(7);

        $log = app(ServicoAuditoria::class)->registar('Sistema', 'Acção de um cliente');

        DB::statement('RESET ROLE');
        $this->assertSame(7, (int) DB::table('logs_auditoria')->where('id', $log->getKey())->value('tenant_id'));
        $this->assertSame(7, $tenant->id(), 'o contexto do pedido não é mexido');
    }
}
