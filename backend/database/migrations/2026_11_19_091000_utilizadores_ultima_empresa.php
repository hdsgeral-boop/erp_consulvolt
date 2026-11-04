<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Última empresa escolhida pelo utilizador (ADR-082): substitui `erp.empresas_recentes.<utilizador>` do localStorage do
 * navegador — a ordem «a última usada primeiro» no ecrã de escolha passa a vir do servidor (GET /autenticacao/eu).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('utilizadores', 'ultima_empresa_id')) {
            Schema::table('utilizadores', function (Blueprint $table) {
                $table->unsignedBigInteger('ultima_empresa_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('utilizadores', 'ultima_empresa_id')) {
            Schema::table('utilizadores', function (Blueprint $table) {
                $table->dropColumn('ultima_empresa_id');
            });
        }
    }
};
