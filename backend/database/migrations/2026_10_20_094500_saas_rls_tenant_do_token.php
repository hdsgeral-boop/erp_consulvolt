<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Substitui tenant_do_portador (saas_rls_tenant_do_portador): não chegava, porque o próprio Guard do Sanctum já lê
 * `$accessToken->tokenable` ANTES de chamar authenticateAccessTokensUsing (vendor/laravel/sanctum/src/Guard.php,
 * isValidAccessToken() -> hasValidProvider()) — nesse ponto a relação fica em cache (null, por falta de contexto) e
 * nenhuma definição de tenant feita depois consegue mudar o resultado. tenant_do_token junta tokens_acesso (sem RLS)
 * a utilizadores (com RLS) num só passo SECURITY DEFINER, para se poder definir o tenant no ponto mais cedo possível:
 * Sanctum::getAccessTokenFromRequestUsing (AppServiceProvider::configurarSanctum), antes de qualquer acesso a tokenable.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS tenant_do_portador(bigint)');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION tenant_do_token(p_token_id bigint) RETURNS bigint
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
                SELECT u.tenant_id
                FROM tokens_acesso t
                JOIN utilizadores u ON u.id = t.portador_id AND t.portador_tipo = 'utilizador'
                WHERE t.id = p_token_id AND u.eliminado_em IS NULL
            $$
        SQL);

        DB::statement('REVOKE EXECUTE ON FUNCTION tenant_do_token(bigint) FROM PUBLIC');
        DB::statement("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'erp_app') THEN GRANT EXECUTE ON FUNCTION tenant_do_token(bigint) TO erp_app; END IF; END \$\$");
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS tenant_do_token(bigint)');
    }
};
