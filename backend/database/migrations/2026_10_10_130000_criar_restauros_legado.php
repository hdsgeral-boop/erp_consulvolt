<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Restauro da plataforma a partir do backup do legado (JSON Dexie), pelo ecrã (Configurações › Manutenção de dados).
 * Histórico permanente: SEM chaves estrangeiras de propósito — o restauro faz TRUNCATE … CASCADE das tabelas de negócio,
 * utilizadores e auditoria, e este registo tem de sobreviver (quem pediu/aprovou/executou fica em JSON).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restauros_legado', function (Blueprint $table) {
            $table->id();
            $table->string('estado', 20)->comment('SIMULANDO, SIMULADO, SIMULACAO_FALHADA, APROVADO, EM_EXECUCAO, CONCLUIDO, FALHADO, CANCELADO');
            $table->string('nome_ficheiro', 255);
            $table->string('caminho', 500)->nullable()->comment('Ficheiro no disco privado; apagado no fim (contém dados reais)');
            $table->char('sha256', 64);
            $table->unsignedBigInteger('tamanho_bytes');
            $table->jsonb('pedido_por');
            $table->timestampTz('pedido_em');
            $table->jsonb('simulacao')->nullable()->comment('Resumo da simulação (contagens, quarentena, equilíbrio)');
            $table->timestampTz('simulado_em')->nullable();
            $table->jsonb('aprovado_por')->nullable();
            $table->timestampTz('aprovado_em')->nullable();
            $table->timestampTz('expira_em')->nullable();
            $table->jsonb('executado_por')->nullable();
            $table->timestampTz('iniciado_em')->nullable();
            $table->timestampTz('concluido_em')->nullable();
            $table->string('copia_seguranca', 500)->nullable()->comment('pg_dump da base antes de substituir');
            $table->jsonb('relatorio')->nullable();
            $table->text('erro')->nullable();
            $table->jsonb('historico')->nullable();
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restauros_legado');
    }
};
