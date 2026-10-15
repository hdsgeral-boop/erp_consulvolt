<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase D (Versão Actual, ADR-071) — Tesouraria › Cartas de pagamento.
 *   - modelos_cartas_pagamento: o legado guarda UM texto por empresa e tipo (payment_letter_templates); o sistema novo
 *     permite vários modelos por tipo, com nome e um predefinido por tipo (índice único parcial). O modelo mais antigo de
 *     cada empresa/tipo fica predefinido (os dados migrados continuam a valer como o texto padrão do legado).
 *   - cartas_pagamento_bancario: numeração própria por empresa e ano (CP 2026/001) — índice único parcial (só as cartas
 *     numeradas; as cartas antigas do RH não têm número).
 * Aditiva, com guardas: as colunas novas não estão no esquema.json (gravadas com forceFill no serviço).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('modelos_cartas_pagamento')) {
            Schema::table('modelos_cartas_pagamento', function (Blueprint $table) {
                if (! Schema::hasColumn('modelos_cartas_pagamento', 'nome')) {
                    $table->string('nome', 100)->nullable()->comment('Nome do modelo (sistema novo; o legado tinha um texto por tipo)');
                }
                if (! Schema::hasColumn('modelos_cartas_pagamento', 'predefinido')) {
                    $table->boolean('predefinido')->default(false)->comment('Modelo usado por omissão no tipo (um por empresa e tipo)');
                }
            });
            DB::statement(<<<'SQL'
                UPDATE modelos_cartas_pagamento m SET predefinido = true
                WHERE m.id IN (SELECT MIN(id) FROM modelos_cartas_pagamento GROUP BY empresa_id, tipo)
                  AND NOT EXISTS (SELECT 1 FROM modelos_cartas_pagamento o WHERE o.empresa_id = m.empresa_id AND o.tipo IS NOT DISTINCT FROM m.tipo AND o.predefinido)
            SQL);
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS ux_modelos_cartas_pagamento_predefinido ON modelos_cartas_pagamento (empresa_id, tipo) WHERE predefinido');
        }

        if (Schema::hasTable('cartas_pagamento_bancario')) {
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS ux_cartas_pagamento_bancario_numeracao ON cartas_pagamento_bancario (empresa_id, ano, sequencia) WHERE ano IS NOT NULL AND sequencia IS NOT NULL');
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ux_cartas_pagamento_bancario_numeracao');
        DB::statement('DROP INDEX IF EXISTS ux_modelos_cartas_pagamento_predefinido');
        if (Schema::hasTable('modelos_cartas_pagamento')) {
            Schema::table('modelos_cartas_pagamento', function (Blueprint $table) {
                foreach (['predefinido', 'nome'] as $c) {
                    if (Schema::hasColumn('modelos_cartas_pagamento', $c)) {
                        $table->dropColumn($c);
                    }
                }
            });
        }
    }
};
