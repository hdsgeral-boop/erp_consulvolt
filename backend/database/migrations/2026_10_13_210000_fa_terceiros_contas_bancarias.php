<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase A (Versão Actual) — contas bancárias dos terceiros (third_party_bank_details, db_v2.js v1271; ui_aux.js:920-1005).
 * A tabela foi gerada do backup novo só com empresa_id (0 linhas reais): faltam as colunas que o legado grava
 * (third_party_id, banco, numero_conta, iban, titular, predefinida e a moeda). Migração aditiva, com guardas.
 * Uma só conta predefinida por terceiro (índice único parcial).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contas_bancarias_terceiros')) {
            return;
        }
        Schema::table('contas_bancarias_terceiros', function (Blueprint $table) {
            $colunas = [
                'terceiro_id' => fn () => $table->bigInteger('terceiro_id')->nullable()->comment('legado: third_party_id'),
                'banco_id' => fn () => $table->bigInteger('banco_id')->nullable()->comment('legado: bank_id (opcional; o legado grava o nome em banco)'),
                'banco' => fn () => $table->string('banco', 150)->nullable()->comment('legado: banco'),
                'numero_conta' => fn () => $table->string('numero_conta', 50)->nullable()->comment('legado: numero_conta'),
                'iban' => fn () => $table->string('iban', 50)->nullable()->comment('legado: iban (sem espaços, maiúsculas)'),
                'titular' => fn () => $table->string('titular', 150)->nullable()->comment('legado: titular'),
                'codigo_moeda' => fn () => $table->string('codigo_moeda', 10)->nullable()->comment('legado: moeda'),
                'predefinida' => fn () => $table->boolean('predefinida')->default(false)->comment('legado: predefinida'),
            ];
            foreach ($colunas as $nome => $criar) {
                if (! Schema::hasColumn('contas_bancarias_terceiros', $nome)) {
                    $criar();
                }
            }
        });

        DB::statement('CREATE INDEX IF NOT EXISTS ix_contas_bancarias_terceiros_empresa_terceiro ON contas_bancarias_terceiros (empresa_id, terceiro_id)');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS ux_contas_bancarias_terceiros_predefinida ON contas_bancarias_terceiros (terceiro_id) WHERE predefinida');
        // qualquer FK desta tabela para terceiros (o gerador do esquema cria a sua quando conhece a coluna — ADR-071)
        $fk = DB::selectOne("SELECT 1 AS existe FROM pg_constraint WHERE contype = 'f' AND conrelid = 'contas_bancarias_terceiros'::regclass AND confrelid = 'terceiros'::regclass");
        if (! $fk) {
            DB::statement('ALTER TABLE contas_bancarias_terceiros ADD CONSTRAINT fk_contas_bancarias_terceiros_terceiro_id FOREIGN KEY (terceiro_id) REFERENCES terceiros (id) ON DELETE CASCADE DEFERRABLE INITIALLY IMMEDIATE');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('contas_bancarias_terceiros')) {
            return;
        }
        DB::statement('ALTER TABLE contas_bancarias_terceiros DROP CONSTRAINT IF EXISTS fk_contas_bancarias_terceiros_terceiro_id');
        DB::statement('DROP INDEX IF EXISTS ux_contas_bancarias_terceiros_predefinida');
        DB::statement('DROP INDEX IF EXISTS ix_contas_bancarias_terceiros_empresa_terceiro');
        Schema::table('contas_bancarias_terceiros', function (Blueprint $table) {
            foreach (['predefinida', 'codigo_moeda', 'titular', 'iban', 'numero_conta', 'banco', 'banco_id', 'terceiro_id'] as $c) {
                if (Schema::hasColumn('contas_bancarias_terceiros', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
