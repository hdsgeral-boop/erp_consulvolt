<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SaaS — fase 3 (ADR-073): funcoes SECURITY DEFINER minimas para os caminhos SEM sessao (webhook da AGT e feed OData do
 * Power BI). Recebem o hash de um segredo e devolvem so o id da empresa dona; a aplicacao define depois o contexto
 * (empresa e tenant) e le os dados normalmente, ja sujeita a RLS. Nao expoem mais nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_empresa_por_webhook_token(p_hash text) RETURNS bigint
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
                SELECT empresa_id FROM agt_ligacoes_empresa WHERE webhook_token_hash = p_hash AND webhook_ativo LIMIT 1
            $$
        SQL);
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_empresa_por_token_bi(p_hash text) RETURNS bigint
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
                SELECT empresa_id FROM tokens_bi WHERE hash_token = p_hash LIMIT 1
            $$
        SQL);

        foreach (['app_empresa_por_webhook_token(text)', 'app_empresa_por_token_bi(text)'] as $f) {
            DB::statement("REVOKE EXECUTE ON FUNCTION {$f} FROM PUBLIC");
            DB::statement("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'erp_app') THEN GRANT EXECUTE ON FUNCTION {$f} TO erp_app; END IF; END \$\$");
        }
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS app_empresa_por_webhook_token(text)');
        DB::statement('DROP FUNCTION IF EXISTS app_empresa_por_token_bi(text)');
    }
};
