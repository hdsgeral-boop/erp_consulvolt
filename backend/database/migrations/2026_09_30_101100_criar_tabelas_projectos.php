<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo Projectos — GERADO por ferramentas/gerador/gerar_esquema.mjs a partir de docs/dicionario/mapa_de_para.json.
 * Não editar à mão: alterar glossario.mjs / esquema_extra.mjs e regenerar.
 * As chaves estrangeiras são criadas em 2026_09_30_900000_criar_chaves_estrangeiras.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        // projects (legado) -> projetos · 5 linhas reais no backup · eliminação lógica
        Schema::create('projetos', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->string('codigo', 50)->nullable()->comment('legado: code');
            $table->string('nome', 255)->nullable()->comment('legado: name');
            $table->string('estado', 10)->nullable()->comment('legado: status');
            $table->string('tipo', 20)->nullable()->comment('legado: type');
            $table->bigInteger('unidade_negocio_id')->nullable()->comment('legado: business_unit_id');
            $table->bigInteger('centro_custo_id')->nullable()->comment('legado: cost_center_id');
            $table->bigInteger('cliente_id')->nullable()->comment('legado: customer_id');
            $table->bigInteger('encomenda_venda_id')->nullable()->comment('legado: sales_order_id');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
            $table->timestampTz('eliminado_em')->nullable();
        });
        DB::statement('CREATE UNIQUE INDEX uq_projetos_empresa_id_codigo ON projetos (empresa_id, codigo) WHERE eliminado_em IS NULL');
        DB::statement('CREATE INDEX ix_projetos_unidade_negocio_id ON projetos (unidade_negocio_id)');
        DB::statement('CREATE INDEX ix_projetos_centro_custo_id ON projetos (centro_custo_id)');
        DB::statement('CREATE INDEX ix_projetos_cliente_id ON projetos (cliente_id)');
        DB::statement('CREATE INDEX ix_projetos_encomenda_venda_id ON projetos (encomenda_venda_id)');

        // project_milestones (legado) -> marcos_projeto · 15 linhas reais no backup
        Schema::create('marcos_projeto', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->bigInteger('projeto_id')->nullable()->comment('legado: project_id');
            $table->string('nome', 255)->nullable()->comment('legado: name');
            $table->date('data')->nullable()->comment('legado: date');
            $table->string('estado', 20)->nullable()->comment('legado: status');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_marcos_projeto_empresa_id ON marcos_projeto (empresa_id)');
        DB::statement('CREATE INDEX ix_marcos_projeto_projeto_id ON marcos_projeto (projeto_id)');

        // project_tasks (legado) -> tarefas_projeto · 51 linhas reais no backup
        Schema::create('tarefas_projeto', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->bigInteger('projeto_id')->nullable()->comment('legado: project_id');
            $table->bigInteger('tarefa_pai_id')->nullable()->comment('legado: parent_task_id');
            $table->text('codigo')->nullable()->comment('legado: code · sem valores reais: tipo a confirmar no código legado');
            $table->string('nome', 255)->nullable()->comment('legado: name');
            $table->date('data_inicio')->nullable()->comment('legado: start_date');
            $table->date('data_fim')->nullable()->comment('legado: end_date');
            $table->string('estado', 20)->nullable()->comment('legado: status · código normalizado ∈ {PENDENTE, EM_CURSO, CONCLUIDA, BLOQUEADA}; texto original em estado_original');
            $table->string('estado_original', 100)->nullable()->comment('legado: status · texto exacto do legado');
            $table->bigInteger('marco_projeto_id')->nullable()->comment('legado: milestone_id');
            $table->bigInteger('atribuido_a_id')->nullable()->comment('legado: assigned_to_id');
            $table->decimal('percentagem_execucao', 9, 4)->nullable()->comment('legado: execution_pct');
            $table->decimal('valor_contrato', 15, 2)->nullable()->comment('legado: contract_value');
            $table->integer('ordem')->nullable()->comment('legado: ordem');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_tarefas_projeto_empresa_id ON tarefas_projeto (empresa_id)');
        DB::statement('CREATE INDEX ix_tarefas_projeto_projeto_id ON tarefas_projeto (projeto_id)');
        DB::statement('CREATE INDEX ix_tarefas_projeto_tarefa_pai_id ON tarefas_projeto (tarefa_pai_id)');
        DB::statement('CREATE INDEX ix_tarefas_projeto_marco_projeto_id ON tarefas_projeto (marco_projeto_id)');
        DB::statement('ALTER TABLE tarefas_projeto ADD CONSTRAINT ck_tarefas_projeto_estado CHECK (estado IS NULL OR estado IN (\'PENDENTE\',\'EM_CURSO\',\'CONCLUIDA\',\'BLOQUEADA\'))');

        // project_teams (legado) -> equipas_projeto · 4 linhas reais no backup
        Schema::create('equipas_projeto', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->bigInteger('projeto_id')->nullable()->comment('legado: project_id');
            $table->string('nome', 255)->nullable()->comment('legado: name');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_equipas_projeto_empresa_id ON equipas_projeto (empresa_id)');
        DB::statement('CREATE INDEX ix_equipas_projeto_projeto_id ON equipas_projeto (projeto_id)');

        // project_team_members (legado) -> membros_equipa_projeto · 29 linhas reais no backup
        Schema::create('membros_equipa_projeto', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->bigInteger('equipa_projeto_id')->nullable()->comment('legado: team_id');
            $table->bigInteger('colaborador_id')->nullable()->comment('legado: employee_id');
            $table->bigInteger('terceiro_id')->nullable()->comment('legado: third_party_id');
            $table->text('nome_externo')->nullable()->comment('legado: external_name · sem valores reais: tipo a confirmar no código legado');
            $table->string('papel', 100)->nullable()->comment('legado: role');
            $table->decimal('horas_alocadas', 12, 3)->nullable()->comment('legado: allocated_hours · tipos mistos: inteiro=22, decimal=1');
            $table->decimal('valor_contrato', 15, 2)->nullable()->comment('legado: contract_value');
            $table->bigInteger('no_organigrama_projeto_id')->nullable()->comment('legado: org_node_id');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_membros_equipa_projeto_empresa_id ON membros_equipa_projeto (empresa_id)');
        DB::statement('CREATE INDEX ix_membros_equipa_projeto_equipa_projeto_id ON membros_equipa_projeto (equipa_projeto_id)');
        DB::statement('CREATE INDEX ix_membros_equipa_projeto_colaborador_id ON membros_equipa_projeto (colaborador_id)');
        DB::statement('CREATE INDEX ix_membros_equipa_projeto_terceiro_id ON membros_equipa_projeto (terceiro_id)');
        DB::statement('CREATE INDEX ix_membros_equipa_projeto_no_organigrama_projeto_id ON membros_equipa_projeto (no_organigrama_projeto_id)');

        // project_requisitions (legado) -> requisicoes_material_projeto · 7 linhas reais no backup
        Schema::create('requisicoes_material_projeto', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->bigInteger('projeto_id')->nullable()->comment('legado: project_id');
            $table->string('nome_requerente', 255)->nullable()->comment('legado: requester_name');
            $table->date('data')->nullable()->comment('legado: date');
            $table->string('estado', 20)->nullable()->comment('legado: status');
            $table->date('data_prevista')->nullable()->comment('legado: expected_date');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_requisicoes_material_projeto_empresa_id ON requisicoes_material_projeto (empresa_id)');
        DB::statement('CREATE INDEX ix_requisicoes_material_projeto_projeto_id ON requisicoes_material_projeto (projeto_id)');

        // project_requisition_lines (legado) -> linhas_requisicao_projeto · 10 linhas reais no backup
        Schema::create('linhas_requisicao_projeto', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->bigInteger('requisicao_material_projeto_id')->nullable()->comment('legado: requisition_id');
            $table->bigInteger('tarefa_projeto_id')->nullable()->comment('legado: task_id');
            $table->string('rubrica', 20)->nullable()->comment('legado: rubric');
            $table->text('descricao')->nullable()->comment('legado: description');
            $table->decimal('quantidade', 12, 3)->nullable()->comment('legado: quantity');
            $table->string('estado', 20)->nullable()->comment('legado: status');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_linhas_requisicao_projeto_empresa_id ON linhas_requisicao_projeto (empresa_id)');
        DB::statement('CREATE INDEX ix_linhas_requisicao_projeto_requisicao_material_projeto_id ON linhas_requisicao_projeto (requisicao_material_projeto_id)');
        DB::statement('CREATE INDEX ix_linhas_requisicao_projeto_tarefa_projeto_id ON linhas_requisicao_projeto (tarefa_projeto_id)');

        // project_budget_lines (legado) -> linhas_orcamento_projeto · 13 linhas reais no backup
        Schema::create('linhas_orcamento_projeto', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->bigInteger('projeto_id')->nullable()->comment('legado: project_id');
            $table->bigInteger('tarefa_projeto_id')->nullable()->comment('legado: task_id');
            $table->string('rubrica', 20)->nullable()->comment('legado: rubric');
            $table->decimal('montante', 15, 2)->nullable()->comment('legado: amount');
            $table->string('numero_conta', 20)->nullable()->comment('legado: account_number');
            $table->bigInteger('no_organigrama_projeto_id')->nullable()->comment('legado: org_node_id · do código legado js/projectos_organigrama.js:477');
            $table->bigInteger('membro_equipa_projeto_id')->nullable()->comment('legado: team_member_id · do código legado js/projectos_organigrama.js:477');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_linhas_orcamento_projeto_empresa_id ON linhas_orcamento_projeto (empresa_id)');
        DB::statement('CREATE INDEX ix_linhas_orcamento_projeto_projeto_id ON linhas_orcamento_projeto (projeto_id)');
        DB::statement('CREATE INDEX ix_linhas_orcamento_projeto_tarefa_projeto_id ON linhas_orcamento_projeto (tarefa_projeto_id)');
        DB::statement('CREATE INDEX ix_linhas_orcamento_projeto_no_organigrama_projeto_id ON linhas_orcamento_projeto (no_organigrama_projeto_id)');
        DB::statement('CREATE INDEX ix_linhas_orcamento_projeto_membro_equipa_projeto_id ON linhas_orcamento_projeto (membro_equipa_projeto_id)');

        // project_ledger (legado) -> razao_analitico_projetos · 30 linhas reais no backup
        Schema::create('razao_analitico_projetos', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->bigInteger('projeto_id')->nullable()->comment('legado: project_id');
            $table->bigInteger('tarefa_projeto_id')->nullable()->comment('legado: task_id');
            $table->string('rubrica', 30)->nullable()->comment('legado: rubric');
            $table->string('modulo_origem', 30)->nullable()->comment('legado: source_module · tipo forçado (inferido: varchar(10))');
            $table->string('tipo_documento_origem', 27)->nullable()->comment('legado: source_doc_type · tipo forçado (inferido: varchar(30)); código normalizado ∈ {AUTO_INTERNO, REGISTO_OBRA, FATURA, FATURA_RECIBO, PROCESSAMENTO_SALARIAL}; texto original em tipo_documento_origem_original');
            $table->string('tipo_documento_origem_original', 100)->nullable()->comment('legado: source_doc_type · texto exacto do legado');
            $table->string('natureza', 20)->nullable()->comment('legado: nature · código normalizado ∈ {CUSTO, CUSTO_REAL, PROVEITO}; texto original em natureza_original');
            $table->string('natureza_original', 100)->nullable()->comment('legado: nature · texto exacto do legado');
            $table->date('data')->nullable()->comment('legado: date');
            $table->decimal('valor', 15, 2)->nullable()->comment('legado: value');
            $table->decimal('montante', 15, 2)->nullable()->comment('legado: amount');
            $table->string('documento_origem_id', 60)->nullable()->comment('legado: source_doc_id · tipo forçado (inferido: varchar(30))');
            $table->bigInteger('lancamento_contabil_id')->nullable()->comment('legado: journal_line_id');
            $table->text('descricao')->nullable()->comment('legado: description');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_razao_analitico_projetos_empresa_id ON razao_analitico_projetos (empresa_id)');
        DB::statement('CREATE INDEX ix_razao_analitico_projetos_projeto_id ON razao_analitico_projetos (projeto_id)');
        DB::statement('CREATE INDEX ix_razao_analitico_projetos_tarefa_projeto_id ON razao_analitico_projetos (tarefa_projeto_id)');
        DB::statement('CREATE INDEX ix_razao_analitico_projetos_lancamento_contabil_id ON razao_analitico_projetos (lancamento_contabil_id)');
        DB::statement('ALTER TABLE razao_analitico_projetos ADD CONSTRAINT ck_razao_analitico_projetos_tipo_documento_origem CHECK (tipo_documento_origem IS NULL OR tipo_documento_origem IN (\'AUTO_INTERNO\',\'REGISTO_OBRA\',\'FATURA\',\'FATURA_RECIBO\',\'PROCESSAMENTO_SALARIAL\'))');
        DB::statement('ALTER TABLE razao_analitico_projetos ADD CONSTRAINT ck_razao_analitico_projetos_natureza CHECK (natureza IS NULL OR natureza IN (\'CUSTO\',\'CUSTO_REAL\',\'PROVEITO\'))');

        // project_timesheets (legado) -> folhas_horas_projeto · 2 linhas reais no backup
        Schema::create('folhas_horas_projeto', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->bigInteger('projeto_id')->nullable()->comment('legado: project_id');
            $table->bigInteger('tarefa_projeto_id')->nullable()->comment('legado: task_id');
            $table->bigInteger('colaborador_id')->nullable()->comment('legado: employee_id');
            $table->date('data')->nullable()->comment('legado: date');
            $table->decimal('horas', 12, 3)->nullable()->comment('legado: hours');
            $table->string('estado', 20)->nullable()->comment('legado: status');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_folhas_horas_projeto_empresa_id ON folhas_horas_projeto (empresa_id)');
        DB::statement('CREATE INDEX ix_folhas_horas_projeto_projeto_id ON folhas_horas_projeto (projeto_id)');
        DB::statement('CREATE INDEX ix_folhas_horas_projeto_tarefa_projeto_id ON folhas_horas_projeto (tarefa_projeto_id)');
        DB::statement('CREATE INDEX ix_folhas_horas_projeto_colaborador_id ON folhas_horas_projeto (colaborador_id)');

        // project_billings (legado) -> faturacao_projetos · 0 linhas reais no backup
        Schema::create('faturacao_projetos', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id · do código legado js/db_v2.js:559');
            $table->bigInteger('projeto_id')->nullable()->comment('legado: project_id · do código legado js/db_v2.js:559');
            $table->string('numero_documento', 255)->nullable()->comment('legado: doc_number · do código legado js/db_v2.js:559');
            $table->date('data')->nullable()->comment('legado: date · do código legado js/db_v2.js:559');
            $table->decimal('montante', 15, 2)->nullable()->comment('legado: amount · do código legado js/db_v2.js:559');
            $table->string('estado', 255)->nullable()->comment('legado: status · do código legado js/db_v2.js:559');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_faturacao_projetos_empresa_id ON faturacao_projetos (empresa_id)');
        DB::statement('CREATE INDEX ix_faturacao_projetos_projeto_id ON faturacao_projetos (projeto_id)');

        // project_asset_allocations (legado) -> afetacoes_ativos_projeto · 0 linhas reais no backup
        Schema::create('afetacoes_ativos_projeto', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id · do código legado js/db_v2.js:560');
            $table->bigInteger('projeto_id')->nullable()->comment('legado: project_id · do código legado js/db_v2.js:560');
            $table->bigInteger('ativo_imobilizado_id')->nullable()->comment('legado: asset_id · do código legado js/db_v2.js:560');
            $table->date('data_inicio')->nullable()->comment('legado: start_date · do código legado js/db_v2.js:560');
            $table->date('data_fim')->nullable()->comment('legado: end_date · do código legado js/db_v2.js:560');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_afetacoes_ativos_projeto_empresa_id ON afetacoes_ativos_projeto (empresa_id)');
        DB::statement('CREATE INDEX ix_afetacoes_ativos_projeto_projeto_id ON afetacoes_ativos_projeto (projeto_id)');
        DB::statement('CREATE INDEX ix_afetacoes_ativos_projeto_ativo_imobilizado_id ON afetacoes_ativos_projeto (ativo_imobilizado_id)');

        // project_change_orders (legado) -> aditamentos_alteracoes_projeto · 0 linhas reais no backup
        Schema::create('aditamentos_alteracoes_projeto', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id · do código legado js/ui_projects.js:3783');
            $table->bigInteger('projeto_id')->nullable()->comment('legado: project_id · do código legado js/ui_projects.js:3784');
            $table->text('descricao')->nullable()->comment('legado: description · do código legado js/ui_projects.js:3785');
            $table->decimal('montante', 15, 2)->nullable()->comment('legado: amount · do código legado js/ui_projects.js:3786');
            $table->string('estado', 255)->nullable()->comment('legado: status · do código legado js/ui_projects.js:3787');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_aditamentos_alteracoes_projeto_empresa_id ON aditamentos_alteracoes_projeto (empresa_id)');
        DB::statement('CREATE INDEX ix_aditamentos_alteracoes_projeto_projeto_id ON aditamentos_alteracoes_projeto (projeto_id)');

        // project_documents (legado) -> documentos_projeto · 0 linhas reais no backup
        Schema::create('documentos_projeto', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id · do código legado js/db_v2.js:562');
            $table->bigInteger('projeto_id')->nullable()->comment('legado: project_id · do código legado js/db_v2.js:562');
            $table->bigInteger('tipo_documento_id')->nullable()->comment('legado: type_id · do código legado js/db_v2.js:562');
            $table->string('nome_ficheiro', 255)->nullable()->comment('legado: file_name · do código legado js/db_v2.js:562');
            $table->date('data_carregamento')->nullable()->comment('legado: upload_date · do código legado js/db_v2.js:562');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_documentos_projeto_empresa_id ON documentos_projeto (empresa_id)');
        DB::statement('CREATE INDEX ix_documentos_projeto_projeto_id ON documentos_projeto (projeto_id)');
        DB::statement('CREATE INDEX ix_documentos_projeto_tipo_documento_id ON documentos_projeto (tipo_documento_id)');

        // project_activity_log (legado) -> logs_atividades_projeto · 0 linhas reais no backup
        Schema::create('logs_atividades_projeto', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id · do código legado js/db_v2.js:563');
            $table->bigInteger('projeto_id')->nullable()->comment('legado: project_id · do código legado js/db_v2.js:563');
            $table->timestampTz('ocorrido_em')->nullable()->comment('legado: timestamp · do código legado js/db_v2.js:563');
            $table->string('nome_utilizador', 255)->nullable()->comment('legado: user · do código legado js/db_v2.js:563');
            $table->string('acao', 255)->nullable()->comment('legado: action · do código legado js/db_v2.js:563');
            $table->text('detalhes')->nullable()->comment('legado: details · do código legado js/db_v2.js:563');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_logs_atividades_projeto_empresa_id ON logs_atividades_projeto (empresa_id)');
        DB::statement('CREATE INDEX ix_logs_atividades_projeto_projeto_id ON logs_atividades_projeto (projeto_id)');

        // project_settings (legado) -> configuracoes_projetos · 1 linhas reais no backup
        Schema::create('configuracoes_projetos', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->bigInteger('projeto_id')->nullable()->comment('legado: project_id');
            $table->string('chave', 150)->nullable()->comment('legado: key');
            $table->text('valor')->nullable()->comment('legado: value');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE UNIQUE INDEX uq_configuracoes_projetos_empresa_id_projeto_id_chave ON configuracoes_projetos (empresa_id, projeto_id, chave) WHERE projeto_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX uq_configuracoes_projetos_empresa_id_chave ON configuracoes_projetos (empresa_id, chave) WHERE projeto_id IS NULL');
        DB::statement('CREATE INDEX ix_configuracoes_projetos_empresa_id ON configuracoes_projetos (empresa_id)');
        DB::statement('CREATE INDEX ix_configuracoes_projetos_projeto_id ON configuracoes_projetos (projeto_id)');

        // project_reviews (legado) -> revisoes_mensais_projeto · 20 linhas reais no backup
        Schema::create('revisoes_mensais_projeto', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->bigInteger('projeto_id')->nullable()->comment('legado: project_id');
            $table->integer('mes')->nullable()->comment('legado: month');
            $table->integer('ano')->nullable()->comment('legado: year');
            $table->string('estado', 20)->nullable()->comment('legado: status');
            $table->timestampTz('criado_em')->nullable()->useCurrent()->comment('legado: created_at');
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_revisoes_mensais_projeto_empresa_id ON revisoes_mensais_projeto (empresa_id)');
        DB::statement('CREATE INDEX ix_revisoes_mensais_projeto_projeto_id ON revisoes_mensais_projeto (projeto_id)');

        // project_review_lines (legado) -> linhas_revisao_projeto · 55 linhas reais no backup
        Schema::create('linhas_revisao_projeto', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->bigInteger('revisao_mensal_projeto_id')->nullable()->comment('legado: review_id');
            $table->string('tipo', 20)->nullable()->comment('legado: type · código normalizado ∈ {MAO_OBRA, SUBEMPREITADA}; texto original em tipo_original');
            $table->string('tipo_original', 100)->nullable()->comment('legado: type · texto exacto do legado');
            $table->bigInteger('terceiro_id')->nullable()->comment('legado: third_party_id');
            $table->bigInteger('tarefa_projeto_id')->nullable()->comment('legado: task_id');
            $table->decimal('percentagem_anterior', 9, 4)->nullable()->comment('legado: previous_pct');
            $table->decimal('percentagem_atual', 9, 4)->nullable()->comment('legado: current_pct');
            $table->decimal('valor_calculado', 15, 2)->nullable()->comment('legado: calculated_value');
            $table->string('documento_gerado_id', 50)->nullable()->comment('legado: generated_doc_id · tipo forçado (inferido: varchar(10))');
            $table->bigInteger('colaborador_id')->nullable()->comment('legado: employee_id');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_linhas_revisao_projeto_empresa_id ON linhas_revisao_projeto (empresa_id)');
        DB::statement('CREATE INDEX ix_linhas_revisao_projeto_revisao_mensal_projeto_id ON linhas_revisao_projeto (revisao_mensal_projeto_id)');
        DB::statement('CREATE INDEX ix_linhas_revisao_projeto_terceiro_id ON linhas_revisao_projeto (terceiro_id)');
        DB::statement('CREATE INDEX ix_linhas_revisao_projeto_tarefa_projeto_id ON linhas_revisao_projeto (tarefa_projeto_id)');
        DB::statement('CREATE INDEX ix_linhas_revisao_projeto_colaborador_id ON linhas_revisao_projeto (colaborador_id)');
        DB::statement('ALTER TABLE linhas_revisao_projeto ADD CONSTRAINT ck_linhas_revisao_projeto_tipo CHECK (tipo IS NULL OR tipo IN (\'MAO_OBRA\',\'SUBEMPREITADA\'))');

        // project_org_nodes (legado) -> nos_organigrama_projeto · 30 linhas reais no backup
        Schema::create('nos_organigrama_projeto', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->bigInteger('projeto_id')->nullable()->comment('legado: project_id');
            $table->bigInteger('no_pai_id')->nullable()->comment('legado: parent_id');
            $table->string('titulo', 255)->nullable()->comment('legado: titulo');
            $table->string('area', 100)->nullable()->comment('legado: area · tipo forçado (inferido: varchar(30))');
            $table->text('descricao')->nullable()->comment('legado: descricao · sem valores reais: tipo a confirmar no código legado');
            $table->integer('vagas')->nullable()->comment('legado: vagas');
            $table->bigInteger('membro_responsavel_id')->nullable()->comment('legado: responsavel_member_id');
            $table->integer('ordem')->nullable()->comment('legado: ordem');
            $table->string('cor', 20)->nullable()->comment('legado: cor');
            $table->integer('apoio')->nullable()->comment('legado: apoio');
            $table->jsonb('tarefas')->nullable()->comment('legado: tarefas');
            $table->string('disposicao', 30)->nullable()->comment('legado: disposicao · tipo forçado (inferido: varchar(10))');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_nos_organigrama_projeto_empresa_id ON nos_organigrama_projeto (empresa_id)');
        DB::statement('CREATE INDEX ix_nos_organigrama_projeto_projeto_id ON nos_organigrama_projeto (projeto_id)');
        DB::statement('CREATE INDEX ix_nos_organigrama_projeto_no_pai_id ON nos_organigrama_projeto (no_pai_id)');
        DB::statement('CREATE INDEX ix_nos_organigrama_projeto_membro_responsavel_id ON nos_organigrama_projeto (membro_responsavel_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('nos_organigrama_projeto');
        Schema::dropIfExists('linhas_revisao_projeto');
        Schema::dropIfExists('revisoes_mensais_projeto');
        Schema::dropIfExists('configuracoes_projetos');
        Schema::dropIfExists('logs_atividades_projeto');
        Schema::dropIfExists('documentos_projeto');
        Schema::dropIfExists('aditamentos_alteracoes_projeto');
        Schema::dropIfExists('afetacoes_ativos_projeto');
        Schema::dropIfExists('faturacao_projetos');
        Schema::dropIfExists('folhas_horas_projeto');
        Schema::dropIfExists('razao_analitico_projetos');
        Schema::dropIfExists('linhas_orcamento_projeto');
        Schema::dropIfExists('linhas_requisicao_projeto');
        Schema::dropIfExists('requisicoes_material_projeto');
        Schema::dropIfExists('membros_equipa_projeto');
        Schema::dropIfExists('equipas_projeto');
        Schema::dropIfExists('tarefas_projeto');
        Schema::dropIfExists('marcos_projeto');
        Schema::dropIfExists('projetos');
    }
};
