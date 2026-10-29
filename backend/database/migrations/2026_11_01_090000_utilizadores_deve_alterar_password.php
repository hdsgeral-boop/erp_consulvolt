<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Troca de password obrigatória: as passwords passam a ser sempre geradas pelo sistema (nunca escolhidas pelo
 * utilizador) e enviadas por email — no primeiro acesso com essa password (criação da conta, reposição pelo admin ou
 * recuperação "esqueci-me") o utilizador é obrigado a trocá-la antes de usar o resto do sistema (ExigirTrocaPassword).
 * Default `false` para não bloquear retroactivamente quem já tem sessão activa com a password que já escolheu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('utilizadores', function (Blueprint $table) {
            $table->boolean('deve_alterar_password')->default(false)->after('palavra_passe_alterada_em');
        });
    }

    public function down(): void
    {
        Schema::table('utilizadores', function (Blueprint $table) {
            $table->dropColumn('deve_alterar_password');
        });
    }
};
