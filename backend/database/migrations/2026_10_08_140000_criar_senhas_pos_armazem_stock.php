<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POS de armazém › Monitor de Chamada de Senhas (js/ui_pos_armazem.js:1126-1254, RELATORIO 2.6.1). No legado as senhas
 * eram um contador em memória do navegador (perdiam-se ao recarregar e o monitor só funcionava no mesmo ecrã do operador).
 * Aqui ficam gravadas por empresa e armazém, numeradas por dia (P-001, P-002…), para o monitor público (outro ecrã/TV)
 * ler a senha em chamada. Migração aditiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('senhas_pos_armazem', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('armazem_id')->constrained('armazens')->restrictOnDelete();
            $table->date('data')->comment('Dia da senha (a numeração recomeça todos os dias)');
            $table->integer('numero');
            $table->string('codigo', 20)->comment('Ex.: P-001');
            $table->string('estado', 20)->default('AGUARDA')->comment('AGUARDA | CHAMADA');
            $table->timestampTz('chamada_em')->nullable();
            $table->integer('chamadas')->default(0)->comment('N.º de vezes que foi chamada (repetir chamada)');
            $table->string('criado_por', 100)->nullable();
            $table->string('chamado_por', 100)->nullable();
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
            $table->unique(['empresa_id', 'armazem_id', 'data', 'numero']);
            $table->index(['empresa_id', 'armazem_id', 'data', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('senhas_pos_armazem');
    }
};
