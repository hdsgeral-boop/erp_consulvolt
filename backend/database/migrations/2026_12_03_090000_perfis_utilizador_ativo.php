<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Especializações de suporte (ADR-077): permite desactivar uma especialização (deixa de ser oferecida a novos agentes,
 * sem tocar nos que já a têm). Aditiva: todos os perfis existentes ficam activos (default true).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('perfis_utilizador') || Schema::hasColumn('perfis_utilizador', 'ativo')) {
            return;
        }
        Schema::table('perfis_utilizador', function (Blueprint $t) {
            $t->boolean('ativo')->default(true);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('perfis_utilizador') && Schema::hasColumn('perfis_utilizador', 'ativo')) {
            Schema::table('perfis_utilizador', fn (Blueprint $t) => $t->dropColumn('ativo'));
        }
    }
};
