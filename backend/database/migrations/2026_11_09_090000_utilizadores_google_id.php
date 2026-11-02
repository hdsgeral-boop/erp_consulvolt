<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Login com o Google: liga uma conta existente (ou já criada) ao ID estável que o Google devolve ('sub' do
 * perfil), para o reconhecer em logins seguintes sem depender só do email (que pode mudar e não é único na BD —
 * ver AutenticacaoGoogleController). A pesquisa por `google_id` ANTES de se saber o tenant tem o mesmo problema
 * que o login por password (RLS esconde `utilizadores` sem `app.tenant_id` definido): por isso, tal como
 * `login_localizar_utilizador` (saas_rls_utilizadores), precisa de uma função SECURITY DEFINER dedicada — não se
 * mexe na função existente, usada pelo login por password e pela recuperação, para não alargar o que já aceita.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('utilizadores', function (Blueprint $table) {
            if (! Schema::hasColumn('utilizadores', 'google_id')) {
                $table->string('google_id', 255)->nullable()->after('email');
            }
        });

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_utilizadores_google_id ON utilizadores (google_id) WHERE google_id IS NOT NULL');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION login_localizar_utilizador_google_id(p_google_id text) RETURNS SETOF utilizadores
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
                SELECT * FROM utilizadores WHERE eliminado_em IS NULL AND google_id = p_google_id LIMIT 1
            $$
        SQL);

        DB::statement('REVOKE EXECUTE ON FUNCTION login_localizar_utilizador_google_id(text) FROM PUBLIC');
        DB::statement("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'erp_app') THEN GRANT EXECUTE ON FUNCTION login_localizar_utilizador_google_id(text) TO erp_app; END IF; END \$\$");
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS login_localizar_utilizador_google_id(text)');
        DB::statement('DROP INDEX IF EXISTS uq_utilizadores_google_id');

        Schema::table('utilizadores', function (Blueprint $table) {
            if (Schema::hasColumn('utilizadores', 'google_id')) {
                $table->dropColumn('google_id');
            }
        });
    }
};
