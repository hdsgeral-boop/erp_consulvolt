<?php

use App\Support\Tenancy\IsolamentoRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SaaS — fecha uma lacuna da fase 2 (ADR-073): `utilizadores` tem `tenant_id` mas não `empresa_id`, por isso
 * IsolamentoRls::aplicarATodas() (tabelas com `empresa_id`) nunca a protegia — dependia inteiramente de cada
 * serviço/controlador lembrar-se do `where('tenant_id', …)` (ServicoUtilizadores chegou a listar utilizadores de
 * outro cliente antes de ser corrigido à mão). Idempotente: re-chama aplicarATodas(), que agora também trata
 * `utilizadores` como `empresas` (comparação directa a `tenant_id`, ver IsolamentoRls::TENANT_DIRETAS).
 *
 * A RLS esconde `utilizadores` de outros tenants mesmo da plataforma — por isso a função SECURITY DEFINER, para os
 * dois únicos agregados cross-tenant legítimos (contagem por cliente e total), tal como plataforma_empresas() já
 * fazia para empresas. Só devolve contagens; nunca nomes, emails ou credenciais.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rls = new IsolamentoRls(DB::connection());
        $rls->aplicarATodas();
        $rls->prepararRole((string) env('DB_APP_PASSWORD', ''));

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION plataforma_utilizadores_por_tenant() RETURNS TABLE (tenant_id bigint, total bigint)
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
                SELECT u.tenant_id, count(*) FROM utilizadores u GROUP BY u.tenant_id
            $$
        SQL);

        DB::statement("REVOKE EXECUTE ON FUNCTION plataforma_utilizadores_por_tenant() FROM PUBLIC");
        DB::statement("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'erp_app') THEN GRANT EXECUTE ON FUNCTION plataforma_utilizadores_por_tenant() TO erp_app; END IF; END \$\$");

        // O login procura o utilizador só pelo nome_utilizador (ou, na recuperação de password, por nome_utilizador OU
        // email) ANTES de se saber a que tenant pertence — é inerentemente entre tenants, o único caso legítimo de
        // pesquisar `utilizadores` sem contexto definido. Os dois parâmetros preservam exactamente a semântica anterior
        // de cada chamador (login só por nome; recuperação por nome OU email) sem alargar o que cada um aceita.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION login_localizar_utilizador(p_nome_utilizador text, p_email text DEFAULT NULL) RETURNS SETOF utilizadores
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
                SELECT * FROM utilizadores
                WHERE eliminado_em IS NULL
                  AND ((p_nome_utilizador IS NOT NULL AND nome_utilizador = p_nome_utilizador)
                    OR (p_email IS NOT NULL AND email = p_email))
                LIMIT 1
            $$
        SQL);

        DB::statement('REVOKE EXECUTE ON FUNCTION login_localizar_utilizador(text, text) FROM PUBLIC');
        DB::statement("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'erp_app') THEN GRANT EXECUTE ON FUNCTION login_localizar_utilizador(text, text) TO erp_app; END IF; END \$\$");
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS login_localizar_utilizador(text, text)');
        DB::statement('DROP FUNCTION IF EXISTS plataforma_utilizadores_por_tenant()');

        $tabela = 'utilizadores';
        DB::statement('DROP POLICY IF EXISTS '.IsolamentoRls::POLITICA." ON {$tabela}");
        DB::statement("ALTER TABLE {$tabela} DISABLE ROW LEVEL SECURITY");
    }
};
