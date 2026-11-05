<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Conta ROOT global (ADR-084): `utilizadores.conta_root` identifica, sem depender do nome, a conta mestra que não
 * pertence a nenhum cliente e atravessa tenant, plano, licença e permissões. Aditiva e idempotente; NÃO marca ninguém
 * (só `php artisan erp:root:definir` o faz). A coluna fica fora do $fillable do modelo e nenhuma API a escreve.
 *
 * As funções de login (`login_localizar_utilizador*`) devolvem `SETOF utilizadores` com `SELECT *`: o corpo SQL é
 * analisado em cada chamada, por isso a coluna nova já é devolvida sem as recriar (verificado em ContaRootTest).
 * Não há RLS especial: a leitura do campo segue a mesma política de `utilizadores` (tenant), tal como as restantes colunas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('utilizadores', 'conta_root')) {
            Schema::table('utilizadores', function (Blueprint $table) {
                $table->boolean('conta_root')->default(false);
            });
        }

        DB::statement('CREATE INDEX IF NOT EXISTS idx_utilizadores_conta_root ON utilizadores (id) WHERE conta_root');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_utilizadores_conta_root');

        if (Schema::hasColumn('utilizadores', 'conta_root')) {
            Schema::table('utilizadores', function (Blueprint $table) {
                $table->dropColumn('conta_root');
            });
        }
    }
};
