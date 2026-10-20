<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SaaS: logótipo do sistema por cliente (ecrã de entrada e menu de <slug>.<base>), ao lado de `nome_sistema`. Substitui
 * a chave global `login_logo` de configuracoes_sistema, que mudava a entrada de todos os clientes. Aditiva e idempotente;
 * `tenants` não tem `empresa_id`, por isso sem RLS.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tenants', 'logotipo_sistema')) {
            Schema::table('tenants', fn (Blueprint $t) => $t->text('logotipo_sistema')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tenants', 'logotipo_sistema')) {
            Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn('logotipo_sistema'));
        }
    }
};
