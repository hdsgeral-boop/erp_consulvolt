<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6 da extensão SaaS (segurança.txt, secção 14, ponto 269 "Alertas"): regista quando um ticket foi avisado por
 * violar o SLA, para o comando agendado nunca enviar o mesmo aviso duas vezes. Aditiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tickets_suporte', 'sla_violado_avisado_em')) {
            DB::statement('ALTER TABLE tickets_suporte ADD COLUMN sla_violado_avisado_em timestamptz NULL');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tickets_suporte', 'sla_violado_avisado_em')) {
            DB::statement('ALTER TABLE tickets_suporte DROP COLUMN sla_violado_avisado_em');
        }
    }
};
