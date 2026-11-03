<?php

use App\Support\Tenancy\IsolamentoRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase J (ADR-078) — Estrutura Orgânica: tarefas das unidades com entregáveis mensuráveis, execuções (entregas) validadas
 * pelo responsável, impedimentos, elegibilidade de produtividade fora do contrato e o departamento (unidade orgânica) nos
 * assuntos do Método de Trabalho. Legado: org_unit_tasks, org_task_deliverables, org_task_executions, org_task_impediments
 * e rh_prod_eligibility (js/db_v2.js v1282-v1284; js/modules/estrutura/estrutura_tarefas.js).
 * Migração aditiva, com guardas; GRANT ao papel erp_app e política RLS em todas as tabelas novas (ADR-073).
 */
return new class extends Migration
{
    private const TABELAS = ['tarefas_unidade_organica', 'entregaveis_tarefa_unidade', 'execucoes_entregavel', 'impedimentos_entregavel', 'elegibilidade_produtividade_rh'];

    public function up(): void
    {
        if (! Schema::hasTable('tarefas_unidade_organica')) {
            Schema::create('tarefas_unidade_organica', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->unsignedBigInteger('unidade_organica_id');
                $t->string('codigo', 50)->nullable();
                $t->string('nome', 255);
                $t->text('descricao')->nullable();
                $t->string('url_instrucoes', 500)->nullable();
                $t->string('periodicidade', 12)->default('MENSAL');
                $t->unsignedBigInteger('colaborador_responsavel_id')->nullable();
                $t->unsignedBigInteger('posto_trabalho_id')->nullable();
                $t->boolean('ativo')->default(true);
                $t->integer('ordem')->default(0);
                $t->string('criado_por', 100)->nullable();
                $t->string('atualizado_por', 100)->nullable();
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->foreign('empresa_id', 'fk_tarefas_unidade_organica_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
                $t->foreign('unidade_organica_id', 'fk_tarefas_unidade_organica_unidade')->references('id')->on('unidades_organicas')->restrictOnDelete();
                $t->foreign('colaborador_responsavel_id', 'fk_tarefas_unidade_organica_responsavel')->references('id')->on('colaboradores')->nullOnDelete();
                $t->foreign('posto_trabalho_id', 'fk_tarefas_unidade_organica_posto')->references('id')->on('postos_trabalho')->nullOnDelete();
            });
            DB::statement("ALTER TABLE tarefas_unidade_organica ADD CONSTRAINT ck_tarefas_unidade_organica_periodicidade CHECK (periodicidade IN ('PONTUAL','DIARIA','SEMANAL','MENSAL','TRIMESTRAL','ANUAL'))");
            DB::statement('CREATE INDEX ix_tarefas_unidade_organica_unidade ON tarefas_unidade_organica (empresa_id, unidade_organica_id)');
            DB::statement('CREATE UNIQUE INDEX uq_tarefas_unidade_organica_codigo ON tarefas_unidade_organica (empresa_id, lower(codigo)) WHERE codigo IS NOT NULL');
        }

        if (! Schema::hasTable('entregaveis_tarefa_unidade')) {
            Schema::create('entregaveis_tarefa_unidade', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->unsignedBigInteger('tarefa_unidade_id');
                $t->string('nome', 255);
                $t->text('descricao')->nullable();
                $t->string('metrica', 20)->default('QUANTIDADE');
                $t->string('unidade', 20);
                $t->decimal('meta', 15, 4)->nullable();
                $t->unsignedBigInteger('item_produtividade_id')->nullable();
                $t->unsignedBigInteger('colaborador_id')->nullable()->comment('dono do entregável');
                $t->string('origem_dados', 10)->default('MANUAL');
                $t->jsonb('crm_filtro')->nullable()->comment('{tipos:[], funil_vendas_crm_id, so_ganhas}');
                $t->smallInteger('prazo_dia')->nullable();
                $t->smallInteger('prazo_mes')->default(1);
                $t->boolean('paga_fora_prazo')->default(true);
                $t->boolean('alerta')->default(false);
                $t->decimal('multa_valor', 15, 2)->nullable();
                $t->string('multa_descricao', 255)->nullable();
                $t->boolean('avaliacao')->default(false);
                $t->decimal('peso_avaliacao', 6, 2)->default(1);
                $t->boolean('ativo')->default(true);
                $t->integer('ordem')->default(0);
                $t->unsignedBigInteger('copiado_de_id')->nullable();
                $t->string('criado_por', 100)->nullable();
                $t->string('atualizado_por', 100)->nullable();
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->foreign('empresa_id', 'fk_entregaveis_tarefa_unidade_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
                $t->foreign('tarefa_unidade_id', 'fk_entregaveis_tarefa_unidade_tarefa')->references('id')->on('tarefas_unidade_organica')->restrictOnDelete();
                $t->foreign('item_produtividade_id', 'fk_entregaveis_tarefa_unidade_item')->references('id')->on('itens_produtividade_rh')->nullOnDelete();
                $t->foreign('colaborador_id', 'fk_entregaveis_tarefa_unidade_colaborador')->references('id')->on('colaboradores')->nullOnDelete();
            });
            DB::statement("ALTER TABLE entregaveis_tarefa_unidade ADD CONSTRAINT ck_entregaveis_tarefa_unidade_metrica CHECK (metrica IN ('QUANTIDADE','HORAS','OBJECTIVO','PONTOS','TAREFAS'))");
            DB::statement("ALTER TABLE entregaveis_tarefa_unidade ADD CONSTRAINT ck_entregaveis_tarefa_unidade_origem CHECK (origem_dados IN ('MANUAL','CRM'))");
            DB::statement('ALTER TABLE entregaveis_tarefa_unidade ADD CONSTRAINT ck_entregaveis_tarefa_unidade_prazo CHECK ((prazo_dia IS NULL OR prazo_dia BETWEEN 1 AND 31) AND prazo_mes >= 1)');
            DB::statement('CREATE INDEX ix_entregaveis_tarefa_unidade_tarefa ON entregaveis_tarefa_unidade (empresa_id, tarefa_unidade_id)');
            DB::statement('CREATE INDEX ix_entregaveis_tarefa_unidade_colaborador ON entregaveis_tarefa_unidade (empresa_id, colaborador_id)');
        }

        if (! Schema::hasTable('execucoes_entregavel')) {
            Schema::create('execucoes_entregavel', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->unsignedBigInteger('entregavel_id');
                $t->unsignedBigInteger('tarefa_unidade_id');
                $t->unsignedBigInteger('unidade_organica_id');
                $t->unsignedBigInteger('colaborador_id')->comment('quem executou');
                $t->decimal('quantidade', 15, 4);
                $t->date('data');
                $t->text('observacoes')->nullable();
                $t->string('url_evidencia', 500)->nullable();
                $t->string('registado_por', 100)->nullable();
                $t->unsignedBigInteger('registado_por_colaborador_id')->nullable();
                $t->string('origem', 10)->default('MANUAL');
                $t->string('origem_chave', 100)->nullable();
                $t->string('validacao', 10)->default('PENDENTE');
                $t->text('validacao_motivo')->nullable();
                $t->string('validada_por', 100)->nullable();
                $t->unsignedBigInteger('validada_por_colaborador_id')->nullable();
                $t->timestampTz('validada_em')->nullable();
                $t->string('validada_como', 12)->nullable();
                $t->jsonb('devolucao_anterior')->nullable();
                $t->unsignedBigInteger('periodo_produtividade_id')->nullable();
                $t->timestampTz('convertida_em')->nullable();
                $t->string('convertida_por', 100)->nullable();
                $t->string('criado_por', 100)->nullable();
                $t->string('atualizado_por', 100)->nullable();
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->foreign('empresa_id', 'fk_execucoes_entregavel_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
                $t->foreign('entregavel_id', 'fk_execucoes_entregavel_entregavel')->references('id')->on('entregaveis_tarefa_unidade')->restrictOnDelete();
                $t->foreign('tarefa_unidade_id', 'fk_execucoes_entregavel_tarefa')->references('id')->on('tarefas_unidade_organica')->restrictOnDelete();
                $t->foreign('unidade_organica_id', 'fk_execucoes_entregavel_unidade')->references('id')->on('unidades_organicas')->restrictOnDelete();
                $t->foreign('colaborador_id', 'fk_execucoes_entregavel_colaborador')->references('id')->on('colaboradores')->restrictOnDelete();
                $t->foreign('periodo_produtividade_id', 'fk_execucoes_entregavel_periodo')->references('id')->on('periodos_produtividade_rh')->nullOnDelete();
            });
            DB::statement('ALTER TABLE execucoes_entregavel ADD CONSTRAINT ck_execucoes_entregavel_quantidade CHECK (quantidade > 0)');
            DB::statement("ALTER TABLE execucoes_entregavel ADD CONSTRAINT ck_execucoes_entregavel_origem CHECK (origem IN ('MANUAL','EXCEL','PORTAL','CRM'))");
            DB::statement("ALTER TABLE execucoes_entregavel ADD CONSTRAINT ck_execucoes_entregavel_validacao CHECK (validacao IN ('PENDENTE','VALIDADA','DEVOLVIDA','RECUSADA'))");
            DB::statement("ALTER TABLE execucoes_entregavel ADD CONSTRAINT ck_execucoes_entregavel_validada_como CHECK (validada_como IS NULL OR validada_como IN ('RESPONSAVEL','PERMISSAO'))");
            DB::statement('CREATE INDEX ix_execucoes_entregavel_entregavel ON execucoes_entregavel (empresa_id, entregavel_id)');
            DB::statement('CREATE INDEX ix_execucoes_entregavel_data ON execucoes_entregavel (empresa_id, data)');
            DB::statement('CREATE INDEX ix_execucoes_entregavel_colaborador ON execucoes_entregavel (empresa_id, colaborador_id)');
            DB::statement('CREATE INDEX ix_execucoes_entregavel_unidade ON execucoes_entregavel (empresa_id, unidade_organica_id)');
            DB::statement('CREATE UNIQUE INDEX uq_execucoes_entregavel_origem_chave ON execucoes_entregavel (empresa_id, origem_chave) WHERE origem_chave IS NOT NULL');
        }

        if (! Schema::hasTable('impedimentos_entregavel')) {
            Schema::create('impedimentos_entregavel', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->unsignedBigInteger('entregavel_id');
                $t->unsignedBigInteger('tarefa_unidade_id');
                $t->unsignedBigInteger('unidade_organica_id');
                $t->unsignedBigInteger('colaborador_id')->comment('autor');
                $t->date('periodo_de')->comment('1.º dia do período servido');
                $t->date('prazo')->nullable();
                $t->text('motivo');
                $t->string('url_documento', 500)->nullable();
                $t->string('estado', 10)->default('ABERTO');
                $t->text('decisao_nota')->nullable();
                $t->string('decidido_por', 100)->nullable();
                $t->unsignedBigInteger('decidido_por_colaborador_id')->nullable();
                $t->timestampTz('decidido_em')->nullable();
                $t->string('criado_por', 100)->nullable();
                $t->string('atualizado_por', 100)->nullable();
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->foreign('empresa_id', 'fk_impedimentos_entregavel_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
                $t->foreign('entregavel_id', 'fk_impedimentos_entregavel_entregavel')->references('id')->on('entregaveis_tarefa_unidade')->restrictOnDelete();
                $t->foreign('tarefa_unidade_id', 'fk_impedimentos_entregavel_tarefa')->references('id')->on('tarefas_unidade_organica')->restrictOnDelete();
                $t->foreign('unidade_organica_id', 'fk_impedimentos_entregavel_unidade')->references('id')->on('unidades_organicas')->restrictOnDelete();
                $t->foreign('colaborador_id', 'fk_impedimentos_entregavel_colaborador')->references('id')->on('colaboradores')->restrictOnDelete();
            });
            DB::statement("ALTER TABLE impedimentos_entregavel ADD CONSTRAINT ck_impedimentos_entregavel_estado CHECK (estado IN ('ABERTO','ACEITE','RECUSADO'))");
            DB::statement('CREATE INDEX ix_impedimentos_entregavel_entregavel ON impedimentos_entregavel (empresa_id, entregavel_id, periodo_de)');
            DB::statement("CREATE UNIQUE INDEX uq_impedimentos_entregavel_periodo ON impedimentos_entregavel (entregavel_id, periodo_de) WHERE estado <> 'RECUSADO'");
        }

        if (! Schema::hasTable('elegibilidade_produtividade_rh')) {
            Schema::create('elegibilidade_produtividade_rh', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->unsignedBigInteger('colaborador_id');
                $t->unsignedBigInteger('item_produtividade_id');
                $t->boolean('ativo')->default(true);
                $t->string('origem', 12)->default('MANUAL');
                $t->decimal('preco_unitario', 15, 4)->nullable();
                $t->string('nota', 255)->nullable();
                $t->string('criado_por', 100)->nullable();
                $t->string('atualizado_por', 100)->nullable();
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->foreign('empresa_id', 'fk_elegibilidade_produtividade_rh_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
                $t->foreign('colaborador_id', 'fk_elegibilidade_produtividade_rh_colaborador')->references('id')->on('colaboradores')->restrictOnDelete();
                $t->foreign('item_produtividade_id', 'fk_elegibilidade_produtividade_rh_item')->references('id')->on('itens_produtividade_rh')->restrictOnDelete();
            });
            DB::statement("ALTER TABLE elegibilidade_produtividade_rh ADD CONSTRAINT ck_elegibilidade_produtividade_rh_origem CHECK (origem IN ('MANUAL','ENTREGAVEL'))");
            DB::statement('CREATE UNIQUE INDEX uq_elegibilidade_produtividade_rh ON elegibilidade_produtividade_rh (empresa_id, colaborador_id, item_produtividade_id)');
        }

        // Método de Trabalho: departamento (unidade orgânica) opcional no WHERE
        if (Schema::hasTable('assuntos_metodo_trabalho') && ! Schema::hasColumn('assuntos_metodo_trabalho', 'unidade_organica_id')) {
            Schema::table('assuntos_metodo_trabalho', function (Blueprint $t) {
                $t->unsignedBigInteger('unidade_organica_id')->nullable()->comment('novo: departamento (unidade orgânica) do assunto');
                $t->foreign('unidade_organica_id', 'fk_assuntos_metodo_trabalho_unidade_organica')->references('id')->on('unidades_organicas')->nullOnDelete();
            });
            DB::statement('CREATE INDEX IF NOT EXISTS ix_assuntos_metodo_trabalho_unidade_organica ON assuntos_metodo_trabalho (unidade_organica_id)');
        }

        foreach (self::TABELAS as $tabela) {
            DB::statement("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'erp_app') THEN "
                ."GRANT SELECT, INSERT, UPDATE, DELETE ON {$tabela} TO erp_app; "
                ."GRANT USAGE, SELECT ON SEQUENCE {$tabela}_id_seq TO erp_app; END IF; END \$\$");
        }

        (new IsolamentoRls(DB::connection()))->aplicarATodas();
    }

    public function down(): void
    {
        if (Schema::hasTable('assuntos_metodo_trabalho') && Schema::hasColumn('assuntos_metodo_trabalho', 'unidade_organica_id')) {
            DB::statement('ALTER TABLE assuntos_metodo_trabalho DROP CONSTRAINT IF EXISTS fk_assuntos_metodo_trabalho_unidade_organica');
            Schema::table('assuntos_metodo_trabalho', fn (Blueprint $t) => $t->dropColumn('unidade_organica_id'));
        }
        foreach (array_reverse(self::TABELAS) as $tabela) {
            Schema::dropIfExists($tabela);
        }
    }
};
