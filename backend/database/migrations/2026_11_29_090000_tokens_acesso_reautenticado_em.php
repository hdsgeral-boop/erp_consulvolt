<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step-up para operações críticas (ADR-085). Só acrescenta `tokens_acesso.reautenticado_em`: o momento da última
 * autenticação forte DESTA sessão (código MFA ou palavra-passe de reautenticação). Nula = vale a criação da sessão
 * (o login). Aditiva e nula por omissão: nenhuma sessão aberta é afectada, ninguém tem de voltar a entrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tokens_acesso', function (Blueprint $table) {
            if (! Schema::hasColumn('tokens_acesso', 'reautenticado_em')) {
                $table->timestampTz('reautenticado_em')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('tokens_acesso', function (Blueprint $table) {
            if (Schema::hasColumn('tokens_acesso', 'reautenticado_em')) {
                $table->dropColumn('reautenticado_em');
            }
        });
    }
};
