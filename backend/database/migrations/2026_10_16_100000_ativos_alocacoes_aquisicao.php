<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Activos — inventariação combinada de várias linhas de aquisição (legado: «Inventariar Seleccionados (Combinar)»,
 * js/ui_assets.js:2784, 3208-3217, 745 — journal_line_id = "ID1,ID2"). A FK ativos_imobilizados.lancamento_contabil_id
 * só liga uma linha; esta tabela guarda quanto de cada linha cobre cada activo combinado. Um activo com alocações conta
 * pelas alocações (não pela FK) no valor inventariado de cada linha. Migração aditiva, com guardas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('alocacoes_aquisicao_ativos')) {
            Schema::create('alocacoes_aquisicao_ativos', function (Blueprint $table) {
                $table->id();
                $table->bigInteger('empresa_id');
                $table->bigInteger('ativo_imobilizado_id');
                $table->bigInteger('lancamento_contabil_id')->comment('linha de aquisição (11/12 a débito)');
                $table->decimal('valor', 15, 2)->comment('parte da linha coberta por este activo');
                $table->timestampTz('criado_em')->nullable()->useCurrent();
                $table->timestampTz('atualizado_em')->nullable()->useCurrent();
                $table->unique(['ativo_imobilizado_id', 'lancamento_contabil_id'], 'uq_alocacoes_aquisicao_ativos_ativo_linha');
                $table->index(['empresa_id', 'lancamento_contabil_id'], 'ix_alocacoes_aquisicao_ativos_linha');
            });
        }
        $this->fk('empresa_id', 'empresas', 'RESTRICT');
        $this->fk('ativo_imobilizado_id', 'ativos_imobilizados', 'CASCADE');
        $this->fk('lancamento_contabil_id', 'lancamentos_contabeis', 'RESTRICT');
        if (! DB::selectOne('SELECT 1 AS existe FROM pg_constraint WHERE conname = ?', ['ck_alocacoes_aquisicao_ativos_valor'])) {
            DB::statement('ALTER TABLE alocacoes_aquisicao_ativos ADD CONSTRAINT ck_alocacoes_aquisicao_ativos_valor CHECK (valor > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('alocacoes_aquisicao_ativos');
    }

    private function fk(string $coluna, string $alvo, string $aoEliminar): void
    {
        $nome = "fk_alocacoes_aquisicao_ativos_{$coluna}";
        if (Schema::hasTable($alvo) && ! DB::selectOne('SELECT 1 AS existe FROM pg_constraint WHERE conname = ?', [$nome])) {
            DB::statement("ALTER TABLE alocacoes_aquisicao_ativos ADD CONSTRAINT {$nome} FOREIGN KEY ({$coluna}) REFERENCES {$alvo} (id) ON DELETE {$aoEliminar} DEFERRABLE INITIALLY IMMEDIATE");
        }
    }
};
