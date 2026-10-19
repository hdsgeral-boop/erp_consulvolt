<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SaaS — fase 1 (ADR-073): tenant (conta do cliente) acima das empresas. Portado da v5, aditivo e com guardas.
 *
 *  - `tenants` (tenant 1 = CONSULVOLT, o operador actual, que fica com todos os dados existentes).
 *  - `empresas.tenant_id` e `utilizadores.tenant_id`: criadas anuláveis, preenchidas com 1 e só então NOT NULL.
 *  - Funções `app_tenant_id()` e `app_tenant_da_empresa(bigint)` (SECURITY DEFINER).
 *
 * NÃO activa RLS nem muda de role: isso é a fase 2 (comando `erp:saas:aplicar-rls`). Com `erp.saas.ativo=false`
 * (omissão) o sistema comporta-se exactamente como antes desta migração.
 */
return new class extends Migration
{
    private const TENANT_INICIAL = 1;

    public function up(): void
    {
        if (! Schema::hasTable('tenants')) {
            Schema::create('tenants', function (Blueprint $t) {
                $t->id();
                $t->string('nome', 255);
                $t->string('slug', 40)->nullable();
                $t->string('estado', 20)->default('ATIVO');
                $t->string('plano', 50)->nullable();
                $t->date('subscricao_inicio')->nullable();
                $t->date('subscricao_fim')->nullable();
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
            });
        }

        if (! DB::table('tenants')->where('id', self::TENANT_INICIAL)->exists()) {
            DB::table('tenants')->insert([
                'id' => self::TENANT_INICIAL, 'nome' => 'CONSULVOLT', 'slug' => 'consulvolt', 'estado' => 'ATIVO',
                'plano' => 'empresarial', 'subscricao_inicio' => now()->toDateString(),
            ]);
            DB::statement("SELECT setval(pg_get_serial_sequence('tenants', 'id'), (SELECT max(id) FROM tenants))");
        }

        DB::statement('ALTER TABLE tenants ALTER COLUMN slug SET NOT NULL');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_tenants_slug ON tenants (slug)');

        foreach (['empresas', 'utilizadores'] as $tabela) {
            if (! Schema::hasColumn($tabela, 'tenant_id')) {
                Schema::table($tabela, fn (Blueprint $t) => $t->unsignedBigInteger('tenant_id')->nullable());
            }
            DB::table($tabela)->whereNull('tenant_id')->update(['tenant_id' => self::TENANT_INICIAL]);
            DB::statement("ALTER TABLE {$tabela} ALTER COLUMN tenant_id SET NOT NULL");
            // Compatibilidade: inserções directas (seeders, testes, importações) sem tenant ficam no tenant inicial.
            // Os modelos definem sempre o tenant do contexto; o omissão sai quando o SaaS passar a obrigatório (fase 2).
            DB::statement("ALTER TABLE {$tabela} ALTER COLUMN tenant_id SET DEFAULT ".self::TENANT_INICIAL);
            DB::statement("CREATE INDEX IF NOT EXISTS idx_{$tabela}_tenant_id ON {$tabela} (tenant_id)");

            $fk = "fk_{$tabela}_tenant_id";
            $existe = DB::selectOne('SELECT 1 AS x FROM pg_constraint WHERE conname = ?', [$fk]);
            if ($existe === null) {
                DB::statement("ALTER TABLE {$tabela} ADD CONSTRAINT {$fk} FOREIGN KEY (tenant_id) REFERENCES tenants (id)");
            }
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_tenant_id() RETURNS bigint
            LANGUAGE sql STABLE AS $$
                SELECT NULLIF(current_setting('app.tenant_id', true), '')::bigint
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_tenant_da_empresa(p_empresa bigint) RETURNS bigint
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
                SELECT tenant_id FROM empresas WHERE id = p_empresa
            $$
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS app_tenant_da_empresa(bigint)');
        DB::statement('DROP FUNCTION IF EXISTS app_tenant_id()');

        foreach (['empresas', 'utilizadores'] as $tabela) {
            DB::statement("ALTER TABLE {$tabela} DROP CONSTRAINT IF EXISTS fk_{$tabela}_tenant_id");
            DB::statement("DROP INDEX IF EXISTS idx_{$tabela}_tenant_id");
            if (Schema::hasColumn($tabela, 'tenant_id')) {
                Schema::table($tabela, fn (Blueprint $t) => $t->dropColumn('tenant_id'));
            }
        }

        Schema::dropIfExists('tenants');
    }
};
