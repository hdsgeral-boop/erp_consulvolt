<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos que o legado grava em companies mas que o backup não contém (docs/dicionario/campos_codigo_legado.json):
 * configuração das horas extra (js/app_v2.js:9172) e moeda funcional (js/moedas.js).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->decimal('he_percentagem_1', 9, 4)->nullable()->comment('legado: he_percentagem_1 · % horas extra até ao limite (padrão do legado: 50)');
            $table->decimal('he_limite_horas', 12, 3)->nullable()->comment('legado: he_limite_horas · limite de horas do 1.º escalão (padrão do legado: 30)');
            $table->decimal('he_percentagem_2', 9, 4)->nullable()->comment('legado: he_percentagem_2 · % horas extra acima do limite (padrão do legado: 75)');
            $table->string('moeda_funcional', 10)->nullable()->comment('legado: functional_currency');
        });
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropColumn(['he_percentagem_1', 'he_limite_horas', 'he_percentagem_2', 'moeda_funcional']);
        });
    }
};
