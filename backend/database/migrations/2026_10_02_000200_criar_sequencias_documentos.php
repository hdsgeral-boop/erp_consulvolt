<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Numeração sequencial transaccional (N.º de lançamento, séries de facturas, guias…).
 * Substitui o "max + 1" do legado (condição de corrida: dois operadores obtinham o mesmo número).
 * Acesso sempre com lock Redis + SELECT … FOR UPDATE (ServicoNumeracao).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sequencias_documentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->string('chave', 150)->comment('Ex.: lancamento:diario:12:2026');
            $table->bigInteger('ultimo_numero');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
            $table->unique(['empresa_id', 'chave']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sequencias_documentos');
    }
};
