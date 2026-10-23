<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SaaS — a licença é do CLIENTE (tenant), não de cada empresa (revê o ADR-074).
 *
 *  - `tenants.licenca_origem` (SEM_LIMITE | TESTE | PLANO), `licenca_inicio` e `licenca_fim` (inclusive; nulo = sem expiração).
 *  - Cada cliente fica com a licença MAIS FAVORÁVEL das suas empresas: SEM_LIMITE se alguma o for; senão a de fim mais tardio.
 *  - As empresas continuam a guardar uma CÓPIA da licença do cliente (o bloqueio, as funções da plataforma e os ecrãs que
 *    as lêem não mudam). Aqui as cópias só são ESTENDIDAS até à do cliente: nenhuma empresa perde acesso.
 *
 * Aditiva e idempotente; corre com a ligação do dono (sem RLS).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tenants', 'licenca_origem')) {
            DB::statement("ALTER TABLE tenants ADD COLUMN licenca_origem varchar(20) NOT NULL DEFAULT 'SEM_LIMITE'");
        }
        if (! Schema::hasColumn('tenants', 'licenca_inicio')) {
            DB::statement('ALTER TABLE tenants ADD COLUMN licenca_inicio date NULL');
        }
        if (! Schema::hasColumn('tenants', 'licenca_fim')) {
            DB::statement('ALTER TABLE tenants ADD COLUMN licenca_fim date NULL');
        }
        if (DB::selectOne("SELECT 1 AS x FROM pg_constraint WHERE conname = 'ck_tenants_licenca_origem'") === null) {
            DB::statement("ALTER TABLE tenants ADD CONSTRAINT ck_tenants_licenca_origem CHECK (licenca_origem IN ('SEM_LIMITE','TESTE','PLANO'))");
        }

        // 1) a licença do cliente = a mais favorável das suas empresas (sem empresas: fica SEM_LIMITE, ninguém é bloqueado)
        DB::statement(<<<'SQL'
            UPDATE tenants t SET
                licenca_origem = x.origem, licenca_inicio = x.inicio, licenca_fim = x.fim
            FROM (
                SELECT DISTINCT ON (e.tenant_id) e.tenant_id,
                    CASE WHEN bool_or(e.licenca_origem = 'SEM_LIMITE' OR e.licenca_fim IS NULL) OVER (PARTITION BY e.tenant_id) THEN 'SEM_LIMITE' ELSE e.licenca_origem END AS origem,
                    CASE WHEN bool_or(e.licenca_origem = 'SEM_LIMITE' OR e.licenca_fim IS NULL) OVER (PARTITION BY e.tenant_id) THEN NULL ELSE e.licenca_inicio END AS inicio,
                    CASE WHEN bool_or(e.licenca_origem = 'SEM_LIMITE' OR e.licenca_fim IS NULL) OVER (PARTITION BY e.tenant_id) THEN NULL ELSE e.licenca_fim END AS fim
                FROM empresas e
                WHERE e.eliminado_em IS NULL
                ORDER BY e.tenant_id, e.licenca_fim DESC NULLS LAST, (e.licenca_origem = 'PLANO') DESC
            ) x
            WHERE t.id = x.tenant_id AND t.licenca_inicio IS NULL AND t.licenca_fim IS NULL AND t.licenca_origem = 'SEM_LIMITE'
        SQL);

        // 2) as cópias nas empresas só sobem até à licença do cliente (nunca descem)
        DB::statement(<<<'SQL'
            UPDATE empresas e SET licenca_origem = t.licenca_origem, licenca_inicio = t.licenca_inicio, licenca_fim = t.licenca_fim
            FROM tenants t
            WHERE e.tenant_id = t.id
              AND (t.licenca_origem = 'SEM_LIMITE' OR (e.licenca_origem <> 'SEM_LIMITE' AND e.licenca_fim IS NOT NULL AND e.licenca_fim < t.licenca_fim))
              AND (e.licenca_origem IS DISTINCT FROM t.licenca_origem OR e.licenca_inicio IS DISTINCT FROM t.licenca_inicio OR e.licenca_fim IS DISTINCT FROM t.licenca_fim)
        SQL);
    }

    public function down(): void
    {
        // as empresas já têm a sua cópia: basta retirar as colunas do cliente
        DB::statement('ALTER TABLE tenants DROP CONSTRAINT IF EXISTS ck_tenants_licenca_origem');
        foreach (['licenca_fim', 'licenca_inicio', 'licenca_origem'] as $coluna) {
            if (Schema::hasColumn('tenants', $coluna)) {
                DB::statement("ALTER TABLE tenants DROP COLUMN {$coluna}");
            }
        }
    }
};
