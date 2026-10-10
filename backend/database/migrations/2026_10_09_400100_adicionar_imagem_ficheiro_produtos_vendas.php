<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Imagem do produto em ficheiro (paridade 4.1.5 — «Imagem do Produto» do formulário do legado, usada nos cartões do POS).
 * Aditiva: o ficheiro fica no disco privado («local»), numa pasta por empresa, com nome gerado no servidor; aqui só o
 * caminho relativo, o tipo MIME validado e a data (versão para a cache do navegador). A coluna imagem_base64 (migrada
 * do legado) mantém-se e continua a ser servida enquanto não houver ficheiro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produtos', function (Blueprint $table) {
            if (! Schema::hasColumn('produtos', 'imagem_caminho')) {
                $table->string('imagem_caminho', 255)->nullable()->comment('Imagem do produto: caminho relativo no disco privado (empresas/{id}/produtos/…)');
            }
            if (! Schema::hasColumn('produtos', 'imagem_mime')) {
                $table->string('imagem_mime', 50)->nullable()->comment('Tipo MIME validado da imagem (image/jpeg, image/png, image/webp)');
            }
            if (! Schema::hasColumn('produtos', 'imagem_atualizada_em')) {
                $table->timestampTz('imagem_atualizada_em')->nullable()->comment('Última alteração da imagem (versão no URL)');
            }
        });
    }

    public function down(): void
    {
        Schema::table('produtos', function (Blueprint $table) {
            $table->dropColumn(['imagem_caminho', 'imagem_mime', 'imagem_atualizada_em']);
        });
    }
};
