<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Regras da IA por cliente (Bloco B, ADR-081): instruções de texto que o cliente define e que se juntam ao prompt do
 * assistente em todas as conversas dele. Uma linha por cliente; com RLS por `tenant_id` (como `utilizadores`), para que
 * um cliente nunca leia nem escreva as regras de outro. Aditiva e idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ia_regras_cliente')) {
            Schema::create('ia_regras_cliente', function (Blueprint $t) {
                $t->foreignId('tenant_id')->primary()->constrained('tenants')->cascadeOnDelete();
                $t->text('regras')->default('');
                $t->timestampTz('atualizado_em')->useCurrent();
            });
        }

        DB::statement('ALTER TABLE ia_regras_cliente ENABLE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS isolamento_tenant ON ia_regras_cliente');
        DB::statement('CREATE POLICY isolamento_tenant ON ia_regras_cliente USING (tenant_id = app_tenant_id()) WITH CHECK (tenant_id = app_tenant_id())');
        DB::unprepared(<<<'SQL'
            DO $$ BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'erp_app') THEN
                    GRANT SELECT, INSERT, UPDATE, DELETE ON ia_regras_cliente TO erp_app;
                END IF;
            END $$
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ia_regras_cliente');
    }
};
