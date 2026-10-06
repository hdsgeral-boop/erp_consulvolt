<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo Contabilidade — GERADO por ferramentas/gerador/gerar_esquema.mjs a partir de docs/dicionario/mapa_de_para.json.
 * Não editar à mão: alterar glossario.mjs / esquema_extra.mjs e regenerar.
 * As chaves estrangeiras são criadas em 2026_09_30_900000_criar_chaves_estrangeiras.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        // chart_of_accounts (legado) -> plano_contas · 18035 linhas reais no backup · eliminação lógica
        Schema::create('plano_contas', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->string('codigo', 50)->nullable()->comment('legado: code · tipos mistos: string_inteiro=18033, string=2');
            $table->text('descricao')->nullable()->comment('legado: description');
            $table->string('tipo', 10)->nullable()->comment('legado: type');
            $table->string('codigo_moeda', 50)->nullable()->comment('legado: currency');
            $table->string('natureza_conta', 255)->nullable()->comment('legado: account_nature · do código legado js/app_v2.js:10836');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
            $table->timestampTz('eliminado_em')->nullable();
        });
        DB::statement('CREATE UNIQUE INDEX uq_plano_contas_empresa_id_codigo ON plano_contas (empresa_id, codigo) WHERE eliminado_em IS NULL');
        DB::statement('CREATE INDEX ix_plano_contas_empresa_id_tipo ON plano_contas (empresa_id, tipo)');
        DB::statement('ALTER TABLE plano_contas ADD CONSTRAINT ck_plano_contas_tipo CHECK (tipo IN (\'M\',\'T\'))');

        // journals (legado) -> diarios_contabeis · 436 linhas reais no backup · eliminação lógica
        Schema::create('diarios_contabeis', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->string('codigo', 50)->nullable()->comment('legado: code · tipos mistos: string=433, string_inteiro=3');
            $table->text('descricao')->nullable()->comment('legado: description');
            $table->string('nome', 255)->nullable()->comment('legado: name');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
            $table->timestampTz('eliminado_em')->nullable();
        });
        DB::statement('CREATE UNIQUE INDEX uq_diarios_contabeis_empresa_id_codigo ON diarios_contabeis (empresa_id, codigo) WHERE eliminado_em IS NULL');

        // journal_lines (legado) -> lancamentos_contabeis · 44400 linhas reais no backup
        Schema::create('lancamentos_contabeis', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->bigInteger('diario_id')->nullable()->comment('legado: journal_id · tipos Dexie: {"nan":9}');
            $table->date('data_documento')->nullable()->comment('legado: doc_date · tipos mistos: string_data=44344, string_datahora=52, string=4; tipo forçado (inferido: timestamptz)');
            $table->timestampTz('data_lancamento')->nullable()->comment('legado: entry_date · tipos mistos: string_data=32819, string_datahora=11087, string=492');
            $table->string('referencia', 100)->nullable()->comment('legado: reference · tipos mistos: string_inteiro=4723, string=39662');
            $table->string('numero_documento', 100)->nullable()->comment('legado: doc_number · tipos mistos: string_inteiro=6016, string=38377');
            $table->text('descricao')->nullable()->comment('legado: description');
            $table->decimal('valor', 15, 2)->nullable()->comment('legado: value · tipos mistos: inteiro=27330, decimal=17070');
            $table->string('tipo_dc', 10)->nullable()->comment('legado: type_dc');
            $table->string('codigo_conta', 20)->nullable()->comment('legado: account_code · tipos mistos: string_inteiro=44386, string=12, string_decimal=2');
            $table->bigInteger('terceiro_id')->nullable()->comment('legado: third_party_id · tipos Dexie: {"undef":2}');
            $table->bigInteger('nota_demonstracao_id')->nullable()->comment('legado: demo_note_id · tipos Dexie: {"undef":4}');
            $table->bigInteger('nota_fluxo_caixa_id')->nullable()->comment('legado: cashflow_note_id · tipos Dexie: {"undef":4,"nan":6}');
            $table->bigInteger('projeto_id')->nullable()->comment('legado: project_id · tipos Dexie: {"undef":10}');
            $table->text('codigo_projeto')->nullable()->comment('legado: project_code · sem valores reais: tipo a confirmar no código legado; tipos Dexie: {"undef":28}');
            $table->string('reconciliacao_codigo', 50)->nullable()->comment('legado: reconciliation_id');
            $table->bigInteger('periodo_id')->nullable()->comment('legado: period_id');
            $table->string('referencia_documento', 50)->nullable()->comment('legado: doc_ref');
            $table->integer('periodo_contabil')->nullable()->comment('legado: acc_period');
            $table->string('documento_origem_id', 10)->nullable()->comment('legado: source_doc_id');
            $table->string('tipo_documento_origem', 20)->nullable()->comment('legado: source_doc_type · código normalizado ∈ {FATURA_COMPRA, RECECAO_COMPRA}; texto original em tipo_documento_origem_original');
            $table->string('tipo_documento_origem_original', 100)->nullable()->comment('legado: source_doc_type · texto exacto do legado');
            $table->string('url_documento', 255)->nullable()->comment('legado: doc_url · tipos Dexie: {"undef":266}');
            $table->string('fonte_dados', 30)->nullable()->comment('legado: source');
            $table->bigInteger('unidade_negocio_id')->nullable()->comment('legado: business_unit_id');
            $table->bigInteger('centro_custo_id')->nullable()->comment('legado: cost_center_id');
            $table->string('numero_lan', 50)->nullable()->comment('legado: lan_number');
            $table->string('codigo_moeda', 50)->nullable()->comment('legado: currency');
            $table->decimal('valor_moeda', 15, 2)->nullable()->comment('legado: value_currency · tipos mistos: inteiro=12, decimal=2');
            $table->decimal('taxa_cambio', 18, 6)->nullable()->comment('legado: exchange_rate');
            $table->bigInteger('taxa_cambio_id')->nullable()->comment('legado: exchange_rate_id');
            $table->boolean('taxa_cambio_manual')->nullable()->comment('legado: exchange_rate_manual');
            $table->string('tipo_origem', 20)->nullable()->comment('legado: source_type');
            $table->bigInteger('sessao_pos_id')->nullable()->comment('legado: pos_session_id');
            $table->bigInteger('tarefa_projeto_id')->nullable()->comment('legado: task_id');
            $table->bigInteger('empresa_origem_id')->nullable()->comment('legado: source_company_id');
            $table->bigInteger('linha_origem_id')->nullable()->comment('legado: source_line_id');
            $table->bigInteger('execucao_consolidacao_id')->nullable()->comment('legado: consolidation_run_id');
            $table->string('tipo_consolidacao', 20)->nullable()->comment('legado: consolidation_type · código normalizado ∈ {AGREGACAO, ELIMINACAO, CONVERSAO}; texto original em tipo_consolidacao_original');
            $table->string('tipo_consolidacao_original', 100)->nullable()->comment('legado: consolidation_type · texto exacto do legado');
            $table->decimal('valor_kz_origem', 15, 2)->nullable()->comment('legado: value_kz_origem · tipos mistos: decimal=6480, inteiro=7084');
            $table->bigInteger('empresa_intragrupo_id')->nullable()->comment('legado: intragroup_company_id');
            $table->bigInteger('item_acrescimo_diferimento_id')->nullable()->comment('legado: ad_item_id');
            $table->integer('arredondamento_cambial')->nullable()->comment('legado: fx_rounding · do código legado js/moedas_lancamentos.js:260');
            $table->string('sistema_origem', 255)->nullable()->comment('legado: system_origin · do código legado js/ui_rotinas.js:1354');
            $table->string('nome_utilizador', 255)->nullable()->comment('legado: user · do código legado js/ui_rotinas.js:1355');
            $table->decimal('valor_imposto', 15, 2)->nullable()->comment('legado: tax_value · do código legado js/ui_rotinas.js:1360');
            $table->bigInteger('estorno_de_id')->nullable()->comment('Linha original que esta linha estorna');
            $table->bigInteger('estornado_por_id')->nullable()->comment('Linha de estorno que anulou esta linha');
            $table->timestampTz('estornado_em')->nullable()->comment('Data/hora do estorno');
            $table->timestampTz('criado_em')->nullable()->useCurrent()->comment('legado: created_at · tipos mistos: string_datahora=48, string_data=5495');
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_lancamentos_contabeis_empresa_id ON lancamentos_contabeis (empresa_id)');
        DB::statement('CREATE INDEX ix_lancamentos_contabeis_diario_id ON lancamentos_contabeis (diario_id)');
        DB::statement('CREATE INDEX ix_lancamentos_contabeis_terceiro_id ON lancamentos_contabeis (terceiro_id)');
        DB::statement('CREATE INDEX ix_lancamentos_contabeis_nota_demonstracao_id ON lancamentos_contabeis (nota_demonstracao_id)');
        DB::statement('CREATE INDEX ix_lancamentos_contabeis_nota_fluxo_caixa_id ON lancamentos_contabeis (nota_fluxo_caixa_id)');
        DB::statement('CREATE INDEX ix_lancamentos_contabeis_projeto_id ON lancamentos_contabeis (projeto_id)');
        DB::statement('CREATE INDEX ix_lancamentos_contabeis_unidade_negocio_id ON lancamentos_contabeis (unidade_negocio_id)');
        DB::statement('CREATE INDEX ix_lancamentos_contabeis_centro_custo_id ON lancamentos_contabeis (centro_custo_id)');
        DB::statement('CREATE INDEX ix_lancamentos_contabeis_taxa_cambio_id ON lancamentos_contabeis (taxa_cambio_id)');
        DB::statement('CREATE INDEX ix_lancamentos_contabeis_sessao_pos_id ON lancamentos_contabeis (sessao_pos_id)');
        DB::statement('CREATE INDEX ix_lancamentos_contabeis_tarefa_projeto_id ON lancamentos_contabeis (tarefa_projeto_id)');
        DB::statement('CREATE INDEX ix_lancamentos_contabeis_empresa_origem_id ON lancamentos_contabeis (empresa_origem_id)');
        DB::statement('CREATE INDEX ix_lancamentos_contabeis_execucao_consolidacao_id ON lancamentos_contabeis (execucao_consolidacao_id)');
        DB::statement('CREATE INDEX ix_lancamentos_contabeis_empresa_intragrupo_id ON lancamentos_contabeis (empresa_intragrupo_id)');
        DB::statement('CREATE INDEX ix_lancamentos_contabeis_item_acrescimo_diferimento_id ON lancamentos_contabeis (item_acrescimo_diferimento_id)');
        DB::statement('CREATE INDEX ix_lancamentos_contabeis_estorno_de_id ON lancamentos_contabeis (estorno_de_id)');
        DB::statement('CREATE INDEX ix_lancamentos_contabeis_estornado_por_id ON lancamentos_contabeis (estornado_por_id)');
        DB::statement('CREATE INDEX ix_lancamentos_contabeis_empresa_id_codigo_conta_data_documento ON lancamentos_contabeis (empresa_id, codigo_conta, data_documento)');
        DB::statement('CREATE INDEX ix_lancamentos_contabeis_empresa_id_diario_id_numero_lan ON lancamentos_contabeis (empresa_id, diario_id, numero_lan)');
        DB::statement('CREATE INDEX ix_lancamentos_contabeis_empresa_id_numero_documento ON lancamentos_contabeis (empresa_id, numero_documento)');
        DB::statement('CREATE INDEX ix_lancamentos_contabeis_empresa_id_reconciliacao_codigo ON lancamentos_contabeis (empresa_id, reconciliacao_codigo)');
        DB::statement('CREATE INDEX ix_lancamentos_contabeis_empresa_id_data_documento ON lancamentos_contabeis (empresa_id, data_documento)');
        DB::statement('ALTER TABLE lancamentos_contabeis ADD CONSTRAINT ck_lancamentos_tipo_dc CHECK (tipo_dc IN (\'D\',\'C\'))');
        DB::statement('ALTER TABLE lancamentos_contabeis ADD CONSTRAINT ck_lancamentos_valor CHECK (valor >= 0)');
        DB::statement('ALTER TABLE lancamentos_contabeis ADD CONSTRAINT ck_lancamentos_contabeis_tipo_documento_origem CHECK (tipo_documento_origem IS NULL OR tipo_documento_origem IN (\'FATURA_COMPRA\',\'RECECAO_COMPRA\'))');
        DB::statement('ALTER TABLE lancamentos_contabeis ADD CONSTRAINT ck_lancamentos_contabeis_tipo_consolidacao CHECK (tipo_consolidacao IS NULL OR tipo_consolidacao IN (\'AGREGACAO\',\'ELIMINACAO\',\'CONVERSAO\'))');

        // recycled_journal_lines (legado) -> lancamentos_estornados · 2505 linhas reais no backup
        Schema::create('lancamentos_estornados', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->bigInteger('diario_id')->nullable()->comment('legado: journal_id');
            $table->date('data_documento')->nullable()->comment('legado: doc_date · tipo forçado (inferido: date)');
            $table->timestampTz('data_lancamento')->nullable()->comment('legado: entry_date · tipos mistos: string_data=2176, string_datahora=327');
            $table->string('referencia', 50)->nullable()->comment('legado: reference');
            $table->string('numero_documento', 50)->nullable()->comment('legado: doc_number · tipos mistos: string=2397, string_inteiro=108');
            $table->text('descricao')->nullable()->comment('legado: description');
            $table->decimal('valor', 15, 2)->nullable()->comment('legado: value · tipos mistos: decimal=985, inteiro=1520');
            $table->string('tipo_dc', 10)->nullable()->comment('legado: type_dc');
            $table->string('codigo_conta', 20)->nullable()->comment('legado: account_code · tipos mistos: string_inteiro=2493, string_decimal=12');
            $table->bigInteger('terceiro_id')->nullable()->comment('legado: third_party_id');
            $table->bigInteger('nota_demonstracao_id')->nullable()->comment('legado: demo_note_id · tipos Dexie: {"undef":11}');
            $table->bigInteger('nota_fluxo_caixa_id')->nullable()->comment('legado: cashflow_note_id · tipos Dexie: {"undef":11}');
            $table->string('url_documento', 150)->nullable()->comment('legado: doc_url · tipos Dexie: {"undef":24}');
            $table->bigInteger('lancamento_original_id')->nullable()->comment('legado: original_id');
            $table->text('reconciliacao_codigo')->nullable()->comment('legado: reconciliation_id · sem valores reais: tipo a confirmar no código legado');
            $table->integer('periodo_contabil')->nullable()->comment('legado: acc_period');
            $table->bigInteger('periodo_id')->nullable()->comment('legado: period_id');
            $table->bigInteger('unidade_negocio_id')->nullable()->comment('legado: business_unit_id');
            $table->bigInteger('centro_custo_id')->nullable()->comment('legado: cost_center_id');
            $table->string('numero_lan', 50)->nullable()->comment('legado: lan_number');
            $table->string('documento_origem_id', 10)->nullable()->comment('legado: source_doc_id');
            $table->string('tipo_documento_origem', 20)->nullable()->comment('legado: source_doc_type · código normalizado ∈ {FATURA_COMPRA, RECECAO_COMPRA}; texto original em tipo_documento_origem_original');
            $table->string('tipo_documento_origem_original', 100)->nullable()->comment('legado: source_doc_type · texto exacto do legado');
            $table->bigInteger('projeto_id')->nullable()->comment('legado: project_id');
            $table->text('codigo_projeto')->nullable()->comment('legado: project_code · sem valores reais: tipo a confirmar no código legado; tipos Dexie: {"undef":2}');
            $table->string('sistema_origem', 20)->nullable()->comment('legado: system_origin');
            $table->string('nome_utilizador', 255)->nullable()->comment('legado: user');
            $table->decimal('valor_imposto', 15, 2)->nullable()->comment('legado: tax_value');
            $table->timestampTz('eliminado_em')->nullable()->comment('legado: deleted_at');
            $table->timestampTz('criado_em')->nullable()->useCurrent()->comment('legado: created_at');
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_lancamentos_estornados_empresa_id ON lancamentos_estornados (empresa_id)');
        DB::statement('CREATE INDEX ix_lancamentos_estornados_diario_id ON lancamentos_estornados (diario_id)');
        DB::statement('CREATE INDEX ix_lancamentos_estornados_terceiro_id ON lancamentos_estornados (terceiro_id)');
        DB::statement('CREATE INDEX ix_lancamentos_estornados_nota_demonstracao_id ON lancamentos_estornados (nota_demonstracao_id)');
        DB::statement('CREATE INDEX ix_lancamentos_estornados_nota_fluxo_caixa_id ON lancamentos_estornados (nota_fluxo_caixa_id)');
        DB::statement('CREATE INDEX ix_lancamentos_estornados_unidade_negocio_id ON lancamentos_estornados (unidade_negocio_id)');
        DB::statement('CREATE INDEX ix_lancamentos_estornados_centro_custo_id ON lancamentos_estornados (centro_custo_id)');
        DB::statement('CREATE INDEX ix_lancamentos_estornados_projeto_id ON lancamentos_estornados (projeto_id)');
        DB::statement('CREATE INDEX ix_lancamentos_estornados_empresa_id_lancamento_original_id ON lancamentos_estornados (empresa_id, lancamento_original_id)');
        DB::statement('ALTER TABLE lancamentos_estornados ADD CONSTRAINT ck_estornados_tipo_dc CHECK (tipo_dc IN (\'D\',\'C\'))');
        DB::statement('ALTER TABLE lancamentos_estornados ADD CONSTRAINT ck_lancamentos_estornados_tipo_documento_origem CHECK (tipo_documento_origem IS NULL OR tipo_documento_origem IN (\'FATURA_COMPRA\',\'RECECAO_COMPRA\'))');

        // demo_notes (legado) -> notas_demonstracao_resultados · 366 linhas reais no backup
        Schema::create('notas_demonstracao_resultados', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->string('codigo', 50)->nullable()->comment('legado: code');
            $table->text('descricao')->nullable()->comment('legado: description');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_notas_demonstracao_resultados_empresa_id ON notas_demonstracao_resultados (empresa_id)');
        DB::statement('CREATE INDEX ix_notas_demonstracao_resultados_empresa_id_codigo ON notas_demonstracao_resultados (empresa_id, codigo)');

        // cashflow_notes (legado) -> notas_fluxo_caixa · 411 linhas reais no backup
        Schema::create('notas_fluxo_caixa', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->string('codigo', 50)->nullable()->comment('legado: code · tipos mistos: string_inteiro=393, string=18');
            $table->text('descricao')->nullable()->comment('legado: description');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_notas_fluxo_caixa_empresa_id ON notas_fluxo_caixa (empresa_id)');
        DB::statement('CREATE INDEX ix_notas_fluxo_caixa_empresa_id_codigo ON notas_fluxo_caixa (empresa_id, codigo)');

        // historical_balances (legado) -> saldos_historicos · 114 linhas reais no backup
        Schema::create('saldos_historicos', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->integer('ano')->nullable()->comment('legado: year · tipos Dexie: {"undef":1}');
            $table->string('tipo', 28)->nullable()->comment('legado: type · tipos Dexie: {"undef":1}; código normalizado ∈ {DEMONSTRACAO_RESULTADOS, FLUXO_CAIXA}; texto original em tipo_original');
            $table->string('tipo_original', 100)->nullable()->comment('legado: type · texto exacto do legado');
            $table->string('codigo', 50)->nullable()->comment('legado: code · tipos mistos: string_inteiro=105, string=9');
            $table->decimal('valor', 15, 2)->nullable()->comment('legado: value · tipos mistos: decimal=52, inteiro=62');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_saldos_historicos_empresa_id ON saldos_historicos (empresa_id)');
        DB::statement('ALTER TABLE saldos_historicos ADD CONSTRAINT ck_saldos_historicos_tipo CHECK (tipo IS NULL OR tipo IN (\'DEMONSTRACAO_RESULTADOS\',\'FLUXO_CAIXA\'))');

        // annual_reports (legado) -> relatorios_anuais_contas · 1 linhas reais no backup
        Schema::create('relatorios_anuais_contas', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: report_company_id');
            $table->integer('ano_relatorio')->nullable()->comment('legado: report_year');
            $table->string('estado', 20)->nullable()->comment('legado: status · código normalizado ∈ {RASCUNHO, APROVADO}; texto original em estado_original');
            $table->string('estado_original', 100)->nullable()->comment('legado: status · texto exacto do legado');
            $table->jsonb('configuracao')->nullable()->comment('legado: config');
            $table->jsonb('textos')->nullable()->comment('legado: textos');
            $table->jsonb('notas_incluir')->nullable()->comment('legado: notas_incluir');
            $table->string('atualizado_por', 100)->nullable()->comment('legado: atualizado_por');
            $table->jsonb('fotografia')->nullable()->comment('Números do relatório no momento da conclusão');
            $table->timestampTz('concluido_em')->nullable()->comment('Data/hora da conclusão');
            $table->string('concluido_por', 100)->nullable()->comment('Utilizador que concluiu');
            $table->timestampTz('criado_em')->nullable()->useCurrent()->comment('legado: criado_em');
            $table->timestampTz('atualizado_em')->nullable()->useCurrent()->comment('legado: atualizado_em');
        });
        DB::statement('CREATE INDEX ix_relatorios_anuais_contas_empresa_id ON relatorios_anuais_contas (empresa_id)');
        DB::statement('ALTER TABLE relatorios_anuais_contas ADD CONSTRAINT ck_relatorios_anuais_contas_estado CHECK (estado IS NULL OR estado IN (\'RASCUNHO\',\'APROVADO\'))');

        // consolidation_groups (legado) -> grupos_consolidacao · 1 linhas reais no backup
        Schema::create('grupos_consolidacao', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('tenant (derivado no ETL)');
            $table->bigInteger('empresa_holding_id')->nullable()->comment('legado: holding_company_id');
            $table->string('nome', 255)->nullable()->comment('legado: name');
            $table->string('moeda_apresentacao', 10)->nullable()->comment('legado: presentation_currency');
            $table->string('conta_reserva_cambial', 20)->nullable()->comment('legado: fx_reserve_account');
            $table->boolean('eliminacao_ativa')->nullable()->comment('legado: elim_enabled');
            $table->string('prefixos_excluidos_eliminacao', 100)->nullable()->comment('legado: elim_exclude_prefixes · tipo forçado (inferido: varchar(10)); 1 valores são listas separadas por vírgulas -> tabela pivô');
            $table->string('conta_diferenca_eliminacao', 20)->nullable()->comment('legado: elim_diff_account');
            $table->bigInteger('ultima_execucao_id')->nullable()->comment('legado: last_run_id');
            $table->timestampTz('criado_em')->nullable()->useCurrent()->comment('legado: created_on');
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_grupos_consolidacao_empresa_id ON grupos_consolidacao (empresa_id)');
        DB::statement('CREATE INDEX ix_grupos_consolidacao_empresa_holding_id ON grupos_consolidacao (empresa_holding_id)');
        DB::statement('CREATE INDEX ix_grupos_consolidacao_ultima_execucao_id ON grupos_consolidacao (ultima_execucao_id)');

        // consolidation_members (legado) -> membros_consolidacao · 3 linhas reais no backup
        Schema::create('membros_consolidacao', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('derivada de grupo_consolidacao_id -> grupos_consolidacao');
            $table->bigInteger('grupo_consolidacao_id')->nullable()->comment('legado: group_id');
            $table->bigInteger('empresa_membro_id')->nullable()->comment('legado: member_company_id');
            $table->decimal('percentagem', 9, 4)->nullable()->comment('legado: percentage');
            $table->string('metodo', 20)->nullable()->comment('legado: method');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_membros_consolidacao_empresa_id ON membros_consolidacao (empresa_id)');
        DB::statement('CREATE INDEX ix_membros_consolidacao_grupo_consolidacao_id ON membros_consolidacao (grupo_consolidacao_id)');
        DB::statement('CREATE INDEX ix_membros_consolidacao_empresa_membro_id ON membros_consolidacao (empresa_membro_id)');

        // consolidation_runs (legado) -> execucoes_consolidacao · 4 linhas reais no backup
        Schema::create('execucoes_consolidacao', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('tenant (derivado no ETL)');
            $table->bigInteger('grupo_consolidacao_id')->nullable()->comment('legado: group_id');
            $table->bigInteger('empresa_holding_id')->nullable()->comment('legado: holding_company_id');
            $table->date('data_execucao')->nullable()->comment('legado: run_date');
            $table->timestampTz('executado_em')->nullable()->comment('legado: run_at');
            $table->date('data_fim')->nullable()->comment('legado: date_end');
            $table->string('codigo_moeda', 50)->nullable()->comment('legado: currency');
            $table->string('estado', 20)->nullable()->comment('legado: status');
            $table->string('nome_utilizador', 255)->nullable()->comment('legado: user');
            $table->jsonb('totais')->nullable()->comment('legado: totals');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_execucoes_consolidacao_empresa_id ON execucoes_consolidacao (empresa_id)');
        DB::statement('CREATE INDEX ix_execucoes_consolidacao_grupo_consolidacao_id ON execucoes_consolidacao (grupo_consolidacao_id)');
        DB::statement('CREATE INDEX ix_execucoes_consolidacao_empresa_holding_id ON execucoes_consolidacao (empresa_holding_id)');

        // utilizacoes_assistente_ia (tabela nova) · 0 linhas reais no backup
        Schema::create('utilizacoes_assistente_ia', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('tenant (derivado no ETL)');
            $table->bigInteger('utilizador_id')->nullable();
            $table->string('nome_utilizador', 255)->nullable();
            $table->string('motor', 20)->nullable()->comment('IA (Claude) ou REGRAS (motor interno)');
            $table->string('modelo', 60)->nullable();
            $table->string('estado', 20)->nullable()->comment('SUCESSO, SEM_PROPOSTA, RECUSA ou FALHA');
            $table->string('codigo_erro', 60)->nullable();
            $table->integer('propostas')->nullable();
            $table->integer('caracteres_texto')->nullable();
            $table->string('tipo_ficheiro', 60)->nullable();
            $table->integer('tamanho_ficheiro_kb')->nullable();
            $table->integer('tokens_entrada')->nullable();
            $table->integer('tokens_saida')->nullable();
            $table->decimal('custo_estimado_usd', 12, 6)->nullable();
            $table->integer('duracao_ms')->nullable();
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_utilizacoes_assistente_ia_empresa_id ON utilizacoes_assistente_ia (empresa_id)');
        DB::statement('CREATE INDEX ix_utilizacoes_assistente_ia_empresa_id_criado_em ON utilizacoes_assistente_ia (empresa_id, criado_em)');
    }

    public function down(): void
    {
        Schema::dropIfExists('utilizacoes_assistente_ia');
        Schema::dropIfExists('execucoes_consolidacao');
        Schema::dropIfExists('membros_consolidacao');
        Schema::dropIfExists('grupos_consolidacao');
        Schema::dropIfExists('relatorios_anuais_contas');
        Schema::dropIfExists('saldos_historicos');
        Schema::dropIfExists('notas_fluxo_caixa');
        Schema::dropIfExists('notas_demonstracao_resultados');
        Schema::dropIfExists('lancamentos_estornados');
        Schema::dropIfExists('lancamentos_contabeis');
        Schema::dropIfExists('diarios_contabeis');
        Schema::dropIfExists('plano_contas');
    }
};
