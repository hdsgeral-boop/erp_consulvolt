<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SaaS, fase 4 (área da plataforma): funções SECURITY DEFINER com os metadados de empresas de TODOS os clientes
 * (a RLS só deixa ver as do cliente actual). Só devolvem id/NIF/nome/estado; nunca dados de negócio.
 * Só o código da plataforma as chama (ver ExigirPlataforma).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION plataforma_empresas() RETURNS TABLE (
                id bigint, tenant_id bigint, nome varchar, nif varchar, estado varchar, criado_em timestamptz,
                licenca_origem varchar, licenca_fim date
            )
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
                SELECT e.id, e.tenant_id, e.nome, e.nif, e.estado, e.criado_em, e.licenca_origem, e.licenca_fim
                FROM empresas e WHERE e.eliminado_em IS NULL ORDER BY e.id
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION plataforma_empresas_por_tenant() RETURNS TABLE (tenant_id bigint, empresas bigint)
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
                SELECT e.tenant_id, count(*) FROM empresas e WHERE e.eliminado_em IS NULL GROUP BY e.tenant_id
            $$
        SQL);

        foreach (['plataforma_empresas()', 'plataforma_empresas_por_tenant()'] as $f) {
            DB::statement("REVOKE EXECUTE ON FUNCTION {$f} FROM PUBLIC");
            DB::statement("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'erp_app') THEN GRANT EXECUTE ON FUNCTION {$f} TO erp_app; END IF; END \$\$");
        }
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS plataforma_empresas_por_tenant()');
        DB::statement('DROP FUNCTION IF EXISTS plataforma_empresas()');
    }
};