<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase H (Versão Actual, ADR-071) — Assistente IA › Regras em ficheiro (ai_rule_files, Dexie v1269 → ficheiros_regras_ia).
 * A tabela já existe (gerada do backup novo). Acrescenta-se só um índice único parcial por empresa e nome da regra (sem
 * maiúsculas/minúsculas): importar um ficheiro com o mesmo `rule_name` substitui a regra (versão seguinte), e o índice
 * impede duas regras com o mesmo nome criadas em simultâneo. Aditiva e com guardas: se já houver nomes repetidos (dados
 * migrados), o índice não é criado e nada é alterado.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ficheiros_regras_ia')) {
            return;
        }
        $repetidos = DB::selectOne('SELECT COUNT(*) AS n FROM (SELECT 1 FROM ficheiros_regras_ia WHERE nome_regra IS NOT NULL GROUP BY empresa_id, lower(nome_regra) HAVING COUNT(*) > 1) x');
        if ((int) $repetidos->n > 0) {
            return;
        }
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS ux_ficheiros_regras_ia_empresa_nome ON ficheiros_regras_ia (empresa_id, lower(nome_regra)) WHERE nome_regra IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ux_ficheiros_regras_ia_empresa_nome');
    }
};
