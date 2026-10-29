<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 8 da extensão SaaS (segurança.txt, secção 17): período experimental configurável por plano, em vez de só o
 * global `erp.licencas.dias_teste`/`definicoes_plataforma.dias_teste_padrao`. `null` = usa o global (nunca obriga a
 * repetir o mesmo valor em todos os planos). Aditiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('planos_licenca', 'dias_teste')) {
            DB::statement('ALTER TABLE planos_licenca ADD COLUMN dias_teste integer NULL');
        }
        if (! Schema::hasColumn('planos_licenca_versoes', 'dias_teste')) {
            DB::statement('ALTER TABLE planos_licenca_versoes ADD COLUMN dias_teste integer NULL');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('planos_licenca_versoes', 'dias_teste')) {
            DB::statement('ALTER TABLE planos_licenca_versoes DROP COLUMN dias_teste');
        }
        if (Schema::hasColumn('planos_licenca', 'dias_teste')) {
            DB::statement('ALTER TABLE planos_licenca DROP COLUMN dias_teste');
        }
    }
};
