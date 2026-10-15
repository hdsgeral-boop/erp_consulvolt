<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Integração das Fases B–H no esquema gerado (ADR-071). Numa base criada pelo gerador regenerado (2026_09_30_*) tudo isto já
 * existe e a migração não faz nada; numa base anterior, acerta o que as migrations aditivas das fases não fizeram:
 *   - vendas.unidades_alocadas passa de text para jsonb, como nas outras tabelas com repartição por unidades de negócio
 *     (TIPOS_FORCADOS 'sales.unidades_alocadas'); texto que não seja um objecto JSON fica NULL (o legado só gravava
 *     objectos {criterio_id, unidades[]} ou nada);
 *   - FKs das prestações de empréstimo para o lançamento e o processamento que as descontaram (o contrato do esquema tem-nas,
 *     como em bonificacoes_avaliacao_rh). Só se criam quando não há referências órfãs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('vendas', 'unidades_alocadas')) {
            $tipo = DB::selectOne("SELECT data_type AS t FROM information_schema.columns
                WHERE table_schema = current_schema() AND table_name = 'vendas' AND column_name = 'unidades_alocadas'")?->t;
            if ($tipo !== 'jsonb') {
                DB::statement("ALTER TABLE vendas ALTER COLUMN unidades_alocadas TYPE jsonb
                    USING (CASE WHEN unidades_alocadas ~ '^\\s*\\{' THEN unidades_alocadas::jsonb END)");
            }
        }

        foreach (['linha_folha_salarial_id' => 'linhas_folha_salarial', 'periodo_processamento_salarial_id' => 'periodos_processamento_salarial'] as $coluna => $alvo) {
            if (! Schema::hasColumn('prestacoes_emprestimos', $coluna) || ! Schema::hasTable($alvo)) {
                continue;
            }
            $nome = "fk_prestacoes_emprestimos_{$coluna}";
            if (DB::selectOne('SELECT 1 AS existe FROM pg_constraint WHERE conname = ?', [$nome])) {
                continue;
            }
            $orfaos = DB::selectOne("SELECT COUNT(*) AS n FROM prestacoes_emprestimos p WHERE p.{$coluna} IS NOT NULL
                AND NOT EXISTS (SELECT 1 FROM {$alvo} a WHERE a.id = p.{$coluna})")->n;
            if ((int) $orfaos === 0) {
                DB::statement("ALTER TABLE prestacoes_emprestimos ADD CONSTRAINT {$nome} FOREIGN KEY ({$coluna}) REFERENCES {$alvo} (id) ON DELETE RESTRICT DEFERRABLE INITIALLY IMMEDIATE");
            }
        }
    }

    public function down(): void
    {
        // não se reverte: o tipo jsonb e as FKs são os do contrato do esquema (database/legado/esquema.json)
    }
};
