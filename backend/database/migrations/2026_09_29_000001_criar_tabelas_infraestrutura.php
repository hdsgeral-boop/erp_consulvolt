<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabelas internas do framework (filas). Os NOMES das tabelas estão em português (config/queue.php);
 * as COLUNAS são as que o Laravel usa internamente e não podem ser renomeadas.
 * Cache, sessões e a fila activa vivem no Redis — por isso não há tabelas cache/sessions/jobs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lotes_trabalhos', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        Schema::create('trabalhos_falhados', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestampTz('failed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trabalhos_falhados');
        Schema::dropIfExists('lotes_trabalhos');
    }
};
