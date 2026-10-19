<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SaaS, área da plataforma: função SECURITY DEFINER com a licença de TODAS as empresas (a RLS só deixa ver as do
 * cliente actual). Serve a gestão de licenças da plataforma (PlataformaSaasController) quando a aplicação liga como
 * `erp_app`. Só devolve metadados e datas de licença; nunca dados de negócio. Função nova: não mexe em plataforma_empresas().
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION plataforma_empresas_licencas() RETURNS TABLE (
                id bigint, tenant_id bigint, nome varchar, nif varchar, estado varchar,
                licenca_origem varchar, licenca_inicio date, licenca_fim date
            )
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
                SELECT e.id, e.tenant_id, e.nome, e.nif, e.estado, e.licenca_origem, e.licenca_inicio, e.licenca_fim
                FROM empresas e WHERE e.eliminado_em IS NULL ORDER BY e.id
            $$
        SQL);

        DB::statement('REVOKE EXECUTE ON FUNCTION plataforma_empresas_licencas() FROM PUBLIC');
        DB::statement("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'erp_app') THEN GRANT EXECUTE ON FUNCTION plataforma_empresas_licencas() TO erp_app; END IF; END \$\$");
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS plataforma_empresas_licencas()');
    }
};
