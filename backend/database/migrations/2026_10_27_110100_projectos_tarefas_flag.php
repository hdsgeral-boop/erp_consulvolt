<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bandeira de atenção manual numa tarefa ou subtarefa (mesma tabela — a subtarefa é só uma tarefa com
 * tarefa_pai_id preenchido): verde, amarelo, vermelho ou preto. Marcação do utilizador, distinta do semáforo
 * calculado automaticamente da carteira (regraSemaforo.ts, que não tem preto).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tarefas_projeto')) {
            return;
        }
        Schema::table('tarefas_projeto', function (Blueprint $t) {
            if (! Schema::hasColumn('tarefas_projeto', 'flag')) {
                $t->string('flag', 10)->nullable();
            }
        });
        if (! Schema::hasColumn('tarefas_projeto', 'flag')) {
            return;
        }
        DB::statement("ALTER TABLE tarefas_projeto ADD CONSTRAINT ck_tarefas_projeto_flag CHECK (flag IS NULL OR flag IN ('verde','amarelo','vermelho','preto'))");
    }

    public function down(): void
    {
        Schema::table('tarefas_projeto', function (Blueprint $t) {
            if (Schema::hasColumn('tarefas_projeto', 'flag')) {
                DB::statement('ALTER TABLE tarefas_projeto DROP CONSTRAINT IF EXISTS ck_tarefas_projeto_flag');
                $t->dropColumn('flag');
            }
        });
    }
};
