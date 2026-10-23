<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SaaS — fecha uma lacuna crítica deixada pela RLS de `utilizadores` (saas_rls_utilizadores): o Sanctum resolve o
 * utilizador autenticado a partir do token ANTES de ResolverTenantAtivo definir o tenant da sessão (é esse próprio
 * middleware que, ao chamar $request->user('sanctum') pela 1.ª vez, obriga o Sanctum a carregar o Utilizador) —
 * sem contexto ainda definido, a RLS escondia sempre essa linha e TODO pedido autenticado (qualquer um a seguir ao
 * login) falhava com 401, em qualquer ambiente real (a role erp_app). Os testes nunca apanharam isto: correm com a
 * ligação do dono, que ignora a RLS.
 *
 * Função SECURITY DEFINER mínima — só o tenant_id de um portador (nunca nome, email ou credenciais) — para
 * ResolverTenantAtivo poder definir o contexto ANTES de pedir o utilizador ao Sanctum.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION tenant_do_portador(p_id bigint) RETURNS bigint
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
                SELECT tenant_id FROM utilizadores WHERE id = p_id AND eliminado_em IS NULL
            $$
        SQL);

        DB::statement('REVOKE EXECUTE ON FUNCTION tenant_do_portador(bigint) FROM PUBLIC');
        DB::statement("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'erp_app') THEN GRANT EXECUTE ON FUNCTION tenant_do_portador(bigint) TO erp_app; END IF; END \$\$");
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS tenant_do_portador(bigint)');
    }
};
