<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase A (Versão Actual) — anulação dos documentos de venda com motivo, autor e data (js/shared/anulacao_documentos.js:
 * anularVenda grava status ANULADO, anulado_em, anulado_por, anulacao_motivo) e autor da anulação dos recibos
 * (anularRecibo / cancelarRecebimentos). Aditiva e idempotente: só acrescenta as colunas que faltam.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendas', function (Blueprint $table) {
            if (! Schema::hasColumn('vendas', 'anulado_em')) {
                $table->timestampTz('anulado_em')->nullable()->comment('legado novo: anulado_em (anulacao_documentos.js)');
            }
            if (! Schema::hasColumn('vendas', 'anulado_por')) {
                $table->string('anulado_por', 255)->nullable()->comment('legado novo: anulado_por — nome do utilizador que anulou');
            }
            if (! Schema::hasColumn('vendas', 'motivo_anulacao')) {
                $table->text('motivo_anulacao')->nullable()->comment('legado novo: anulacao_motivo');
            }
        });
        Schema::table('recibos_venda', function (Blueprint $table) {
            if (! Schema::hasColumn('recibos_venda', 'anulado_por')) {
                $table->string('anulado_por', 255)->nullable()->comment('legado novo: anulado_por — nome do utilizador que anulou');
            }
        });
    }

    public function down(): void
    {
        Schema::table('recibos_venda', function (Blueprint $table) {
            if (Schema::hasColumn('recibos_venda', 'anulado_por')) {
                $table->dropColumn('anulado_por');
            }
        });
        Schema::table('vendas', function (Blueprint $table) {
            foreach (['motivo_anulacao', 'anulado_por', 'anulado_em'] as $c) {
                if (Schema::hasColumn('vendas', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
