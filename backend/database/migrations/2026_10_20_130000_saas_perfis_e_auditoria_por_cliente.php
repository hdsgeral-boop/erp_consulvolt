<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SaaS: isola por cliente duas tabelas que ficaram partilhadas.
 *
 *  - perfis_utilizador: ganha tenant_id. Os perfis existentes ficam do cliente que os usa (o que tem mais utilizadores com
 *    perfil; sem nenhum, o tenant inicial) e cada um dos outros clientes recebe uma cópia (mesmo nome e permissões); os
 *    utilizadores desses clientes passam para a cópia. O filtro por cliente está no modelo (PerfilUtilizador).
 *  - logs_auditoria: ganha tenant_id (preenchido por trigger: tenant da sessão, senão o da empresa, senão o do
 *    utilizador) e RLS só de leitura e inserção. Continua imutável (ADR-009: sem UPDATE/DELETE para erp_app).
 *
 * Idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('perfis_utilizador') && ! Schema::hasColumn('perfis_utilizador', 'tenant_id')) {
            DB::statement('ALTER TABLE perfis_utilizador ADD COLUMN tenant_id bigint NULL REFERENCES tenants(id)');
            DB::statement('CREATE INDEX IF NOT EXISTS perfis_utilizador_tenant_id_index ON perfis_utilizador (tenant_id)');
            $this->distribuirPerfis();
        }

        if (Schema::hasTable('logs_auditoria') && ! Schema::hasColumn('logs_auditoria', 'tenant_id')) {
            DB::statement('ALTER TABLE logs_auditoria ADD COLUMN tenant_id bigint NULL');
            DB::statement('CREATE INDEX IF NOT EXISTS logs_auditoria_tenant_id_index ON logs_auditoria (tenant_id)');
            DB::statement('UPDATE logs_auditoria l SET tenant_id = COALESCE(
                (SELECT e.tenant_id FROM empresas e WHERE e.id = l.empresa_id),
                (SELECT u.tenant_id FROM utilizadores u WHERE u.id = l.utilizador_id))');
        }

        if (Schema::hasTable('logs_auditoria')) {
            // SECURITY DEFINER: corre como o dono, para ler a empresa mesmo sem tenant na sessão (ex.: falha de login)
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION erp_logs_auditoria_tenant() RETURNS trigger
                LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
                BEGIN
                    IF NEW.tenant_id IS NULL THEN
                        NEW.tenant_id := COALESCE(
                            app_tenant_id(),
                            (SELECT e.tenant_id FROM empresas e WHERE e.id = NEW.empresa_id),
                            (SELECT u.tenant_id FROM utilizadores u WHERE u.id = NEW.utilizador_id));
                    END IF;
                    RETURN NEW;
                END $$
                SQL);
            DB::statement('DROP TRIGGER IF EXISTS logs_auditoria_tenant ON logs_auditoria');
            DB::statement('CREATE TRIGGER logs_auditoria_tenant BEFORE INSERT ON logs_auditoria FOR EACH ROW EXECUTE FUNCTION erp_logs_auditoria_tenant()');

            DB::statement('ALTER TABLE logs_auditoria ENABLE ROW LEVEL SECURITY');
            DB::statement('DROP POLICY IF EXISTS auditoria_ler_tenant ON logs_auditoria');
            DB::statement('DROP POLICY IF EXISTS auditoria_inserir ON logs_auditoria');
            DB::statement('CREATE POLICY auditoria_ler_tenant ON logs_auditoria FOR SELECT USING (tenant_id = app_tenant_id())');
            // inserir é sempre permitido (a auditoria de uma falha de login não tem tenant na sessão)
            DB::statement('CREATE POLICY auditoria_inserir ON logs_auditoria FOR INSERT WITH CHECK (true)');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('logs_auditoria')) {
            DB::statement('DROP POLICY IF EXISTS auditoria_ler_tenant ON logs_auditoria');
            DB::statement('DROP POLICY IF EXISTS auditoria_inserir ON logs_auditoria');
            DB::statement('ALTER TABLE logs_auditoria DISABLE ROW LEVEL SECURITY');
            DB::statement('DROP TRIGGER IF EXISTS logs_auditoria_tenant ON logs_auditoria');
            DB::statement('DROP FUNCTION IF EXISTS erp_logs_auditoria_tenant()');
            if (Schema::hasColumn('logs_auditoria', 'tenant_id')) {
                DB::statement('ALTER TABLE logs_auditoria DROP COLUMN tenant_id');
            }
        }
        if (Schema::hasColumn('perfis_utilizador', 'tenant_id')) {
            DB::statement('ALTER TABLE perfis_utilizador DROP COLUMN tenant_id');
        }
    }

    private function distribuirPerfis(): void
    {
        $tenants = DB::table('tenants')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($tenants === [] || ! DB::table('perfis_utilizador')->exists()) {
            return;
        }
        $dono = (int) (DB::table('utilizadores')->whereNotNull('perfil_utilizador_id')->whereNotNull('tenant_id')
            ->groupBy('tenant_id')->orderByRaw('count(*) desc')->value('tenant_id') ?? $tenants[0]);
        DB::table('perfis_utilizador')->whereNull('tenant_id')->update(['tenant_id' => $dono]);

        $originais = DB::table('perfis_utilizador')->where('tenant_id', $dono)->orderBy('id')->get();
        foreach ($tenants as $t) {
            if ($t === $dono) {
                continue;
            }
            foreach ($originais as $p) {
                $novo = DB::table('perfis_utilizador')->insertGetId([
                    'nome' => $p->nome, 'descricao' => $p->descricao, 'permissoes' => $p->permissoes,
                    'permissoes_originais' => $p->permissoes_originais, 'tenant_id' => $t,
                    'criado_em' => now(), 'atualizado_em' => now(),
                ]);
                DB::table('utilizadores')->where('tenant_id', $t)->where('perfil_utilizador_id', $p->id)->update(['perfil_utilizador_id' => $novo]);
            }
        }
    }
};
