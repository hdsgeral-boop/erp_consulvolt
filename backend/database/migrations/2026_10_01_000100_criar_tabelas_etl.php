<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Infraestrutura do ETL do backup legado (comando erp:migrar-backup-legado).
 *  - execucoes_migracao: uma linha por execução (ficheiro, SHA-256, estado, resumo e relatório de validação).
 *  - etl_linhas_legado: área de preparação (UNLOGGED) com as linhas reais do backup, lidas por streaming.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('execucoes_migracao', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 40)->unique();
            $table->text('ficheiro');
            $table->string('sha256', 64);
            $table->bigInteger('tamanho_bytes');
            $table->string('estado', 20);
            $table->boolean('simulacao')->default(false);
            $table->integer('linhas_lidas')->nullable();
            $table->integer('linhas_ficticias')->nullable();
            $table->integer('linhas_migradas')->nullable();
            $table->integer('linhas_quarentena')->nullable();
            $table->jsonb('relatorio')->nullable();
            $table->text('erro')->nullable();
            $table->string('executado_por', 100)->nullable();
            $table->timestampTz('iniciado_em')->useCurrent();
            $table->timestampTz('concluido_em')->nullable();
        });
        DB::statement("ALTER TABLE execucoes_migracao ADD CONSTRAINT ck_execucoes_migracao_estado CHECK (estado IN ('EM_CURSO','CONCLUIDA','SIMULADA','FALHADA'))");

        DB::statement(<<<'SQL'
            CREATE UNLOGGED TABLE etl_linhas_legado (
                id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                execucao    VARCHAR(40) NOT NULL,
                tabela      VARCHAR(100) NOT NULL,
                id_legado   VARCHAR(100),
                dados       JSONB NOT NULL
            )
        SQL);
        DB::statement('CREATE INDEX ix_etl_linhas_legado_tabela ON etl_linhas_legado (execucao, tabela, id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('etl_linhas_legado');
        Schema::dropIfExists('execucoes_migracao');
    }
};
