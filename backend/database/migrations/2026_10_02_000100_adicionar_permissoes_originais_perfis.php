<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perfis no formato antigo do legado são convertidos para v2 na migração (com o próprio converterAntigo do
 * legado — ferramentas/levantamento/converter_perfis_antigos.mjs). O JSON original fica aqui para auditoria.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('perfis_utilizador', function (Blueprint $table) {
            $table->jsonb('permissoes_originais')->nullable()->comment('Permissões no formato antigo do legado, antes da conversão para v2');
        });
    }

    public function down(): void
    {
        Schema::table('perfis_utilizador', function (Blueprint $table) {
            $table->dropColumn('permissoes_originais');
        });
    }
};
