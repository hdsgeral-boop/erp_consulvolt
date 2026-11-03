<?php

use App\Support\Tenancy\IsolamentoRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase K (ADR-079) — multas de entregáveis na folha. Uma multa nasce PROPOSTA a partir de um atraso (entregável sem a meta
 * cumprida depois do prazo, sem impedimento aceite) e só se desconta depois de uma pessoa a APLICAR; DISPENSADA fica no
 * histórico; DESCONTADA liga-se à linha da folha onde foi lançada. Uma por (entregável, período, penalizado).
 * Migração aditiva, com guardas; GRANT ao papel erp_app e política RLS (ADR-073).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('multas_entregavel')) {
            Schema::create('multas_entregavel', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->unsignedBigInteger('entregavel_id');
                $t->unsignedBigInteger('tarefa_unidade_id');
                $t->unsignedBigInteger('unidade_organica_id');
                $t->unsignedBigInteger('colaborador_id')->comment('penalizado: dono do entregável, responsável da tarefa ou da unidade');
                $t->date('periodo_de');
                $t->date('periodo_ate');
                $t->date('limite');
                $t->integer('dias_atraso')->default(0);
                $t->decimal('valor', 15, 2);
                $t->string('descricao', 255)->nullable();
                $t->string('estado', 12)->default('PROPOSTA');
                $t->string('decidido_por', 100)->nullable();
                $t->unsignedBigInteger('decidido_por_colaborador_id')->nullable();
                $t->timestampTz('decidido_em')->nullable();
                $t->text('motivo_decisao')->nullable();
                $t->unsignedBigInteger('periodo_processamento_salarial_id')->nullable();
                $t->unsignedBigInteger('linha_folha_salarial_id')->nullable();
                $t->timestampTz('descontada_em')->nullable();
                $t->string('criado_por', 100)->nullable();
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->foreign('empresa_id', 'fk_multas_entregavel_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
                $t->foreign('entregavel_id', 'fk_multas_entregavel_entregavel')->references('id')->on('entregaveis_tarefa_unidade')->restrictOnDelete();
                $t->foreign('tarefa_unidade_id', 'fk_multas_entregavel_tarefa')->references('id')->on('tarefas_unidade_organica')->restrictOnDelete();
                $t->foreign('unidade_organica_id', 'fk_multas_entregavel_unidade')->references('id')->on('unidades_organicas')->restrictOnDelete();
                $t->foreign('colaborador_id', 'fk_multas_entregavel_colaborador')->references('id')->on('colaboradores')->restrictOnDelete();
                $t->foreign('decidido_por_colaborador_id', 'fk_multas_entregavel_decisor')->references('id')->on('colaboradores')->nullOnDelete();
                $t->foreign('periodo_processamento_salarial_id', 'fk_multas_entregavel_periodo')->references('id')->on('periodos_processamento_salarial')->nullOnDelete();
                $t->foreign('linha_folha_salarial_id', 'fk_multas_entregavel_linha')->references('id')->on('linhas_folha_salarial')->nullOnDelete();
            });
            DB::statement("ALTER TABLE multas_entregavel ADD CONSTRAINT ck_multas_entregavel_estado CHECK (estado IN ('PROPOSTA','APLICADA','DISPENSADA','DESCONTADA'))");
            DB::statement('ALTER TABLE multas_entregavel ADD CONSTRAINT ck_multas_entregavel_valor CHECK (valor > 0)');
            DB::statement('CREATE UNIQUE INDEX uq_multas_entregavel_periodo ON multas_entregavel (empresa_id, entregavel_id, periodo_de, colaborador_id)');
            DB::statement('CREATE INDEX ix_multas_entregavel_estado ON multas_entregavel (empresa_id, estado)');
            DB::statement('CREATE INDEX ix_multas_entregavel_colaborador ON multas_entregavel (empresa_id, colaborador_id)');
        }

        DB::statement("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'erp_app') THEN "
            .'GRANT SELECT, INSERT, UPDATE, DELETE ON multas_entregavel TO erp_app; '
            .'GRANT USAGE, SELECT ON SEQUENCE multas_entregavel_id_seq TO erp_app; END IF; END $$');

        (new IsolamentoRls(DB::connection()))->aplicarATodas();
    }

    public function down(): void
    {
        Schema::dropIfExists('multas_entregavel');
    }
};
