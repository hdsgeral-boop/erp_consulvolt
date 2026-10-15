<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase E (Versão Actual, ADR-071) — projectos: execução medida, dependências e ponte para a produtividade do RH.
 * O esquema gerado do backup novo já traz calendario, metas, concluida_em, meta_execucoes, dependências e mapas_qtd.
 * Falta só o que o backup não conhecia ou que não tem índice no Dexie:
 *   - folhas_horas_projeto.membro_equipa_projeto_id (legado novo: team_member_id, ui_projects.js:5068-5125): permite
 *     lançar horas de membros externos ou entidades sem as pôr no subsídio de ninguém;
 *   - índice único (empresa_id, origem_chave) nos registos de produtividade: o reenvio da execução dos projectos
 *     actualiza o registo em vez de o duplicar (produtividade_projectos.js:208, produtividade.js:286-287);
 *   - CHECK do tipo de dependência (FS, SS ou FF; projectos_hierarquia.js:36-40), NOT VALID para não tocar nos dados
 *     migrados.
 * Migração aditiva, com guardas (não apaga nem altera dados).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('folhas_horas_projeto') && ! Schema::hasColumn('folhas_horas_projeto', 'membro_equipa_projeto_id')) {
            Schema::table('folhas_horas_projeto', function (Blueprint $table) {
                $table->bigInteger('membro_equipa_projeto_id')->nullable()->comment('legado: team_member_id (Versão Actual)');
            });
        }
        if (Schema::hasTable('folhas_horas_projeto')) {
            DB::statement('CREATE INDEX IF NOT EXISTS ix_folhas_horas_projeto_membro_equipa_projeto_id ON folhas_horas_projeto (membro_equipa_projeto_id)');
            $fk = DB::selectOne("SELECT 1 AS existe FROM pg_constraint WHERE conname = 'fk_folhas_horas_projeto_membro_equipa_projeto_id'");
            if (! $fk && Schema::hasTable('membros_equipa_projeto')) {
                DB::statement('ALTER TABLE folhas_horas_projeto ADD CONSTRAINT fk_folhas_horas_projeto_membro_equipa_projeto_id FOREIGN KEY (membro_equipa_projeto_id) REFERENCES membros_equipa_projeto (id) ON DELETE RESTRICT DEFERRABLE INITIALLY IMMEDIATE');
            }
        }

        if (Schema::hasTable('registos_produtividade_rh') && Schema::hasColumn('registos_produtividade_rh', 'origem_chave')) {
            $repetidas = DB::selectOne('SELECT COUNT(*) AS n FROM (SELECT 1 FROM registos_produtividade_rh WHERE origem_chave IS NOT NULL GROUP BY empresa_id, origem_chave HAVING COUNT(*) > 1) x');
            // com chaves repetidas nos dados migrados fica um índice simples (a unicidade é garantida no serviço, com bloqueio)
            DB::statement((int) ($repetidas->n ?? 0) === 0
                ? 'CREATE UNIQUE INDEX IF NOT EXISTS ux_registos_produtividade_rh_origem_chave ON registos_produtividade_rh (empresa_id, origem_chave) WHERE origem_chave IS NOT NULL'
                : 'CREATE INDEX IF NOT EXISTS ix_registos_produtividade_rh_origem_chave ON registos_produtividade_rh (empresa_id, origem_chave) WHERE origem_chave IS NOT NULL');
        }

        if (Schema::hasTable('tarefas_projeto') && Schema::hasColumn('tarefas_projeto', 'tipo_dependencia')) {
            $ck = DB::selectOne("SELECT 1 AS existe FROM pg_constraint WHERE conname = 'ck_tarefas_projeto_tipo_dependencia'");
            if (! $ck) {
                DB::statement("ALTER TABLE tarefas_projeto ADD CONSTRAINT ck_tarefas_projeto_tipo_dependencia CHECK (tipo_dependencia IS NULL OR tipo_dependencia IN ('FS','SS','FF')) NOT VALID");
            }
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE IF EXISTS tarefas_projeto DROP CONSTRAINT IF EXISTS ck_tarefas_projeto_tipo_dependencia');
        DB::statement('DROP INDEX IF EXISTS ux_registos_produtividade_rh_origem_chave');
        DB::statement('DROP INDEX IF EXISTS ix_registos_produtividade_rh_origem_chave');
        DB::statement('ALTER TABLE IF EXISTS folhas_horas_projeto DROP CONSTRAINT IF EXISTS fk_folhas_horas_projeto_membro_equipa_projeto_id');
        DB::statement('DROP INDEX IF EXISTS ix_folhas_horas_projeto_membro_equipa_projeto_id');
        if (Schema::hasTable('folhas_horas_projeto') && Schema::hasColumn('folhas_horas_projeto', 'membro_equipa_projeto_id')) {
            Schema::table('folhas_horas_projeto', fn (Blueprint $table) => $table->dropColumn('membro_equipa_projeto_id'));
        }
    }
};
