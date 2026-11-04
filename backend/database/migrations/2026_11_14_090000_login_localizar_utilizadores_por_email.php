<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Entrar com o e-mail (EMAIL_AMBIGUO). O e-mail não é único entre contas: se houver mais do que uma conta activa com ele,
 * o login pede o nome de utilizador em vez de adivinhar. A pesquisa é entre clientes (ainda não há tenant na sessão) e
 * `utilizadores` tem RLS por tenant, por isso — tal como `login_localizar_utilizador` — é uma função SECURITY DEFINER.
 * Devolve no máximo 2 contas (basta para distinguir «uma» de «várias»), comparação sem distinguir maiúsculas.
 * Aditiva e idempotente (CREATE OR REPLACE).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION login_localizar_utilizadores_por_email(p_email text) RETURNS SETOF utilizadores
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
                SELECT * FROM utilizadores
                WHERE eliminado_em IS NULL AND ativo AND p_email IS NOT NULL AND lower(email) = lower(p_email)
                ORDER BY id
                LIMIT 2
            $$
        SQL);

        DB::statement('REVOKE EXECUTE ON FUNCTION login_localizar_utilizadores_por_email(text) FROM PUBLIC');
        DB::statement("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'erp_app') THEN GRANT EXECUTE ON FUNCTION login_localizar_utilizadores_por_email(text) TO erp_app; END IF; END \$\$");
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS login_localizar_utilizadores_por_email(text)');
    }
};
