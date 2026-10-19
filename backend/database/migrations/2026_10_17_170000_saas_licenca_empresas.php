<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SaaS — licença por empresa (ADR-074). Aditiva e com guardas.
 *
 *  - `licenca_origem`: SEM_LIMITE (omissão; as empresas actuais), TESTE (7 dias) ou PLANO (paga, por plano e tempo).
 *  - `licenca_inicio` / `licenca_fim`: período válido (inclusive). `licenca_fim` nulo = sem expiração.
 *
 * As empresas existentes ficam SEM_LIMITE: nenhuma perde acesso. Só tem efeito com `erp.licencas.ativo=true`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('empresas', 'licenca_origem')) {
            DB::statement("ALTER TABLE empresas ADD COLUMN licenca_origem varchar(20) NOT NULL DEFAULT 'SEM_LIMITE'");
        }
        if (! Schema::hasColumn('empresas', 'licenca_inicio')) {
            DB::statement('ALTER TABLE empresas ADD COLUMN licenca_inicio date NULL');
        }
        if (! Schema::hasColumn('empresas', 'licenca_fim')) {
            DB::statement('ALTER TABLE empresas ADD COLUMN licenca_fim date NULL');
        }

        $existe = DB::selectOne("SELECT 1 AS x FROM pg_constraint WHERE conname = 'ck_empresas_licenca_origem'");
        if ($existe === null) {
            DB::statement("ALTER TABLE empresas ADD CONSTRAINT ck_empresas_licenca_origem CHECK (licenca_origem IN ('SEM_LIMITE','TESTE','PLANO'))");
        }
        DB::statement('CREATE INDEX IF NOT EXISTS idx_empresas_licenca_fim ON empresas (licenca_fim) WHERE licenca_fim IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_empresas_licenca_fim');
        DB::statement('ALTER TABLE empresas DROP CONSTRAINT IF EXISTS ck_empresas_licenca_origem');
        foreach (['licenca_fim', 'licenca_inicio', 'licenca_origem'] as $coluna) {
            if (Schema::hasColumn('empresas', $coluna)) {
                DB::statement("ALTER TABLE empresas DROP COLUMN {$coluna}");
            }
        }
    }
};
