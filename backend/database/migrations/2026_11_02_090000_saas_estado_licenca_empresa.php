<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SaaS — máquina de estados da licença por empresa (Fase 1 da extensão SaaS, secção 4.2 de `segurança.txt`). Aditiva.
 *
 * `estado_licenca` substitui a inferência por `licenca_fim < hoje` espalhada pelo código por um campo explícito, com
 * transições validadas em `MaquinaEstadosLicenca`. `motivo_suspensao` só se aplica ao estado SUSPENSA, distinguindo
 * incumprimento/administrativa/segurança/voluntária (secção 10). `estado_licenca_desde` guarda quando o estado actual
 * começou (janela do período de tolerância; métricas de churn/conversão da Fase 5).
 *
 * As empresas existentes são recalculadas a partir do estado actual (licenca_origem/licenca_fim): esta migração não
 * tira nem dá acesso a ninguém — o estado novo só passa a ser lido a partir da Fase 2/3.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('empresas', 'estado_licenca')) {
            DB::statement("ALTER TABLE empresas ADD COLUMN estado_licenca varchar(25) NOT NULL DEFAULT 'ACTIVA'");
        }
        if (! Schema::hasColumn('empresas', 'motivo_suspensao')) {
            DB::statement('ALTER TABLE empresas ADD COLUMN motivo_suspensao varchar(25) NULL');
        }
        if (! Schema::hasColumn('empresas', 'estado_licenca_desde')) {
            DB::statement('ALTER TABLE empresas ADD COLUMN estado_licenca_desde timestamptz NULL');
        }

        $existeEstado = DB::selectOne("SELECT 1 AS x FROM pg_constraint WHERE conname = 'ck_empresas_estado_licenca'");
        if ($existeEstado === null) {
            DB::statement('ALTER TABLE empresas ADD CONSTRAINT ck_empresas_estado_licenca CHECK (estado_licenca IN '
                ."('PENDENTE_ACTIVACAO','TRIAL','ACTIVA','PAGAMENTO_PENDENTE','TOLERANCIA','SUSPENSA','CANCELADA','EM_ENCERRAMENTO','ENCERRADA'))");
        }
        $existeMotivo = DB::selectOne("SELECT 1 AS x FROM pg_constraint WHERE conname = 'ck_empresas_motivo_suspensao'");
        if ($existeMotivo === null) {
            DB::statement('ALTER TABLE empresas ADD CONSTRAINT ck_empresas_motivo_suspensao CHECK (motivo_suspensao IS NULL OR motivo_suspensao IN '
                ."('INCUMPRIMENTO','ADMINISTRATIVA','SEGURANCA','VOLUNTARIA'))");
        }

        // Backfill: recalcula o estado actual a partir dos dados já existentes (mesma regra de Empresa::licencaExpirada()).
        $diasTolerancia = (int) config('erp.licencas.dias_tolerancia', 5);
        DB::statement("
            UPDATE empresas SET
                estado_licenca = CASE
                    WHEN licenca_fim IS NULL THEN 'ACTIVA'
                    WHEN licenca_fim >= CURRENT_DATE THEN (CASE WHEN licenca_origem = 'TESTE' THEN 'TRIAL' ELSE 'ACTIVA' END)
                    WHEN licenca_fim >= CURRENT_DATE - INTERVAL '{$diasTolerancia} days' THEN 'TOLERANCIA'
                    ELSE 'SUSPENSA'
                END,
                motivo_suspensao = CASE
                    WHEN licenca_fim IS NOT NULL AND licenca_fim < CURRENT_DATE - INTERVAL '{$diasTolerancia} days' THEN 'INCUMPRIMENTO'
                    ELSE NULL
                END,
                estado_licenca_desde = COALESCE(licenca_inicio::timestamptz, criado_em, now())
        ");

        DB::statement('CREATE INDEX IF NOT EXISTS idx_empresas_estado_licenca ON empresas (estado_licenca)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_empresas_estado_licenca');
        DB::statement('ALTER TABLE empresas DROP CONSTRAINT IF EXISTS ck_empresas_motivo_suspensao');
        DB::statement('ALTER TABLE empresas DROP CONSTRAINT IF EXISTS ck_empresas_estado_licenca');
        foreach (['estado_licenca_desde', 'motivo_suspensao', 'estado_licenca'] as $coluna) {
            if (Schema::hasColumn('empresas', $coluna)) {
                DB::statement("ALTER TABLE empresas DROP COLUMN {$coluna}");
            }
        }
    }
};
