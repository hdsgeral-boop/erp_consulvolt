<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tesouraria › Conferência de caixa — campo «Nome do Tesoureiro / Operador» como no legado (ui_tesouraria.js:7255, texto
 * livre e obrigatório). O nome do operador passa a ser o escrito no formulário (por omissão o utilizador da sessão); para
 * o controlo «a assinatura do gerente é de outra pessoa» guarda-se à parte QUEM registou a conferência (utilizador).
 * Aditiva e com guardas: só cria a coluna se não existir; não altera dados.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('conferencias_caixa') && ! Schema::hasColumn('conferencias_caixa', 'registado_por')) {
            Schema::table('conferencias_caixa', function (Blueprint $table) {
                $table->string('registado_por', 100)->nullable()->comment('Utilizador (nome_utilizador) que registou a conferência; o operador é texto livre');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('conferencias_caixa') && Schema::hasColumn('conferencias_caixa', 'registado_por')) {
            Schema::table('conferencias_caixa', function (Blueprint $table) {
                $table->dropColumn('registado_por');
            });
        }
    }
};
