<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Primeiro acesso (pedido de 2026-10-22): uma palavra-passe definida por um administrador (conta nova ou reposta) é
 * provisória — no primeiro acesso o sistema pede para a alterar; o utilizador pode adiar, mas só dessa vez.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('utilizadores', 'alterar_palavra_passe')) {
            Schema::table('utilizadores', function (Blueprint $t) {
                $t->boolean('alterar_palavra_passe')->default(false)->comment('palavra-passe provisória: pedir a alteração no próximo acesso');
            });
        }
    }

    public function down(): void
    {
        Schema::table('utilizadores', fn (Blueprint $t) => $t->dropColumn('alterar_palavra_passe'));
    }
};
