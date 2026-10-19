<?php

use App\Support\Tenancy\IsolamentoRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SaaS — fase 2 (ADR-073): RLS por tenant em todas as tabelas com `empresa_id` e role `erp_app`. Idempotente.
 *
 * Inerte enquanto a aplicação ligar como dono/superuser (a RLS não se aplica ao dono sem FORCE): o sistema continua a
 * comportar-se como antes. Só tem efeito quando a aplicação liga como `erp_app` com erp.saas.ativo=true (ver
 * docs/arquitetura/SAAS_ATIVACAO.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        $rls = new IsolamentoRls(DB::connection());
        $rls->aplicarATodas();
        $rls->prepararRole((string) env('DB_APP_PASSWORD', ''));
    }

    public function down(): void
    {
        $tabelas = DB::select(
            "SELECT tablename FROM pg_policies WHERE schemaname = 'public' AND policyname = ?",
            [IsolamentoRls::POLITICA],
        );
        foreach ($tabelas as $t) {
            DB::statement('DROP POLICY IF EXISTS '.IsolamentoRls::POLITICA." ON {$t->tablename}");
            DB::statement("ALTER TABLE {$t->tablename} DISABLE ROW LEVEL SECURITY");
        }
        // A role erp_app não é removida: pode ter dependências e privilégios; remover à mão se necessário.
    }
};
