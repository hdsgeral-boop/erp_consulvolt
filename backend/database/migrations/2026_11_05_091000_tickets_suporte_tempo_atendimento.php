<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6 da extensão SaaS (segurança.txt, secção 15, ponto 289 "Registar tempo de atendimento"): tempo total, em
 * minutos, que a equipa de suporte dedicou ao ticket — registo manual do agente, sem cronómetro automático (não
 * existe em lado nenhum do sistema um mecanismo de início/fim de sessão de atendimento). Aditiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tickets_suporte', 'tempo_atendimento_minutos')) {
            DB::statement('ALTER TABLE tickets_suporte ADD COLUMN tempo_atendimento_minutos integer NULL');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tickets_suporte', 'tempo_atendimento_minutos')) {
            DB::statement('ALTER TABLE tickets_suporte DROP COLUMN tempo_atendimento_minutos');
        }
    }
};
