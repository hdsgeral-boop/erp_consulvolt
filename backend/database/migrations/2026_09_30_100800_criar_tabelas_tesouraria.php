<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo Tesouraria — GERADO por ferramentas/gerador/gerar_esquema.mjs a partir de docs/dicionario/mapa_de_para.json.
 * Não editar à mão: alterar glossario.mjs / esquema_extra.mjs e regenerar.
 * As chaves estrangeiras são criadas em 2026_09_30_900000_criar_chaves_estrangeiras.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        // treasury_documents (legado) -> documentos_tesouraria · 5781 linhas reais no backup
        Schema::create('documentos_tesouraria', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->string('tipo', 20)->nullable()->comment('legado: type · código normalizado ∈ {PAGAMENTO, RECEBIMENTO}; texto original em tipo_original');
            $table->string('tipo_original', 100)->nullable()->comment('legado: type · texto exacto do legado');
            $table->date('data_documento')->nullable()->comment('legado: doc_date');
            $table->string('conta_financeira', 20)->nullable()->comment('legado: account_fin · tipos mistos: string_inteiro=5777, string=4');
            $table->text('descricao')->nullable()->comment('legado: description');
            $table->decimal('valor_total', 15, 2)->nullable()->comment('legado: total_value · tipos mistos: inteiro=4901, decimal=880');
            $table->string('estado', 20)->nullable()->comment('legado: status · código normalizado ∈ {PENDENTE, INTEGRADO, ANULADO}; texto original em estado_original');
            $table->string('estado_original', 100)->nullable()->comment('legado: status · texto exacto do legado');
            $table->string('referencia', 100)->nullable()->comment('legado: reference · tipos mistos: string=4037, string_inteiro=1742');
            $table->bigInteger('projeto_id')->nullable()->comment('legado: project_id');
            $table->text('codigo_projeto')->nullable()->comment('legado: project_code · sem valores reais: tipo a confirmar no código legado');
            $table->boolean('importado')->nullable()->comment('legado: is_imported');
            $table->string('url_documento', 150)->nullable()->comment('legado: doc_url');
            $table->string('codigo_moeda', 50)->nullable()->comment('legado: currency');
            $table->decimal('taxa_cambio', 18, 6)->nullable()->comment('legado: exchange_rate');
            $table->bigInteger('taxa_cambio_id')->nullable()->comment('legado: exchange_rate_id');
            $table->boolean('taxa_cambio_manual')->nullable()->comment('legado: exchange_rate_manual');
            $table->decimal('valor_total_moeda', 15, 2)->nullable()->comment('legado: total_value_currency');
            $table->bigInteger('periodo_processamento_salarial_id')->nullable()->comment('legado: payroll_period_id');
            $table->string('reconciliacao_codigo', 255)->nullable()->comment('legado: reconciliation_id · do código legado js/ui_lancamentos.js:2344');
            $table->string('numero_documento', 50)->nullable()->comment('N.º "PAG|REC <série>/<n>" (a referência continua texto livre)');
            $table->string('numero_lan_contabilizacao', 30)->nullable()->comment('N.º do lançamento da integração');
            $table->timestampTz('integrado_em')->nullable()->comment('Data/hora da integração');
            $table->string('integrado_por', 100)->nullable()->comment('Utilizador que integrou');
            $table->timestampTz('anulado_em')->nullable()->comment('Data/hora da anulação');
            $table->text('motivo_anulacao')->nullable()->comment('Motivo da anulação');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE UNIQUE INDEX uq_documentos_tesouraria_empresa_id_numero_documento ON documentos_tesouraria (empresa_id, numero_documento) WHERE numero_documento IS NOT NULL');
        DB::statement('CREATE INDEX ix_documentos_tesouraria_empresa_id ON documentos_tesouraria (empresa_id)');
        DB::statement('CREATE INDEX ix_documentos_tesouraria_projeto_id ON documentos_tesouraria (projeto_id)');
        DB::statement('CREATE INDEX ix_documentos_tesouraria_taxa_cambio_id ON documentos_tesouraria (taxa_cambio_id)');
        DB::statement('CREATE INDEX ix_documentos_tesouraria_periodo_processamento_salarial_id ON documentos_tesouraria (periodo_processamento_salarial_id)');
        DB::statement('CREATE INDEX ix_documentos_tesouraria_empresa_id_data_documento ON documentos_tesouraria (empresa_id, data_documento)');
        DB::statement('ALTER TABLE documentos_tesouraria ADD CONSTRAINT ck_documentos_tesouraria_tipo CHECK (tipo IS NULL OR tipo IN (\'PAGAMENTO\',\'RECEBIMENTO\'))');
        DB::statement('ALTER TABLE documentos_tesouraria ADD CONSTRAINT ck_documentos_tesouraria_estado CHECK (estado IS NULL OR estado IN (\'PENDENTE\',\'INTEGRADO\',\'ANULADO\'))');

        // treasury_items (legado) -> itens_documento_tesouraria · 6252 linhas reais no backup
        Schema::create('itens_documento_tesouraria', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('derivada de documento_tesouraria_id -> documentos_tesouraria');
            $table->string('codigo_conta', 20)->nullable()->comment('legado: account_code · tipos mistos: string_inteiro=6249, string=3');
            $table->bigInteger('terceiro_id')->nullable()->comment('legado: third_party_id');
            $table->string('numero_documento', 100)->nullable()->comment('legado: doc_number · tipos mistos: string=3076, string_inteiro=3136');
            $table->bigInteger('nota_demonstracao_id')->nullable()->comment('legado: demo_note_id');
            $table->bigInteger('nota_fluxo_caixa_id')->nullable()->comment('legado: cashflow_note_id');
            $table->text('descricao')->nullable()->comment('legado: description');
            $table->decimal('valor', 15, 2)->nullable()->comment('legado: value · tipos mistos: inteiro=5250, decimal=1002');
            $table->string('tipo_dc', 10)->nullable()->comment('legado: type_dc');
            $table->bigInteger('documento_tesouraria_id')->nullable()->comment('legado: doc_id');
            $table->bigInteger('projeto_id')->nullable()->comment('legado: project_id');
            $table->text('codigo_projeto')->nullable()->comment('legado: project_code · sem valores reais: tipo a confirmar no código legado');
            $table->string('data_documento_original', 100)->nullable()->comment('legado: orig_doc_date · tipos mistos: string_data=6042, string=45, string_datahora=1; tipo forçado (inferido: timestamptz)');
            $table->string('nif_importado', 30)->nullable()->comment('legado: nif_imported · tipos mistos: string_inteiro=995, string=245');
            $table->bigInteger('unidade_negocio_id')->nullable()->comment('legado: business_unit_id');
            $table->bigInteger('centro_custo_id')->nullable()->comment('legado: cost_center_id');
            $table->string('codigo_moeda', 50)->nullable()->comment('legado: currency');
            $table->decimal('valor_moeda', 15, 2)->nullable()->comment('legado: value_currency');
            $table->decimal('taxa_cambio', 18, 6)->nullable()->comment('legado: exchange_rate');
            $table->bigInteger('taxa_cambio_id')->nullable()->comment('legado: exchange_rate_id');
            $table->boolean('taxa_cambio_manual')->nullable()->comment('legado: exchange_rate_manual');
            $table->text('cambial_moeda_documento')->nullable()->comment('legado: fx_doc_currency · sem valores reais: tipo a confirmar no código legado');
            $table->decimal('cambial_saldo_moeda', 15, 2)->nullable()->comment('legado: fx_bal_cur');
            $table->decimal('cambial_saldo_kz', 15, 2)->nullable()->comment('legado: fx_bal_kz');
            $table->decimal('valor_introduzido', 15, 2)->nullable()->comment('legado: value_input');
            $table->bigInteger('venda_id')->nullable()->comment('Factura de venda liquidada por esta linha');
            $table->bigInteger('fatura_compra_id')->nullable()->comment('Factura de fornecedor liquidada por esta linha');
            $table->decimal('valor_kz_documento', 15, 2)->nullable()->comment('Multi-moeda: valor da linha em Kz ao câmbio do documento (a diferença para o valor histórico é diferença de câmbio)');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_itens_documento_tesouraria_empresa_id ON itens_documento_tesouraria (empresa_id)');
        DB::statement('CREATE INDEX ix_itens_documento_tesouraria_terceiro_id ON itens_documento_tesouraria (terceiro_id)');
        DB::statement('CREATE INDEX ix_itens_documento_tesouraria_nota_demonstracao_id ON itens_documento_tesouraria (nota_demonstracao_id)');
        DB::statement('CREATE INDEX ix_itens_documento_tesouraria_nota_fluxo_caixa_id ON itens_documento_tesouraria (nota_fluxo_caixa_id)');
        DB::statement('CREATE INDEX ix_itens_documento_tesouraria_documento_tesouraria_id ON itens_documento_tesouraria (documento_tesouraria_id)');
        DB::statement('CREATE INDEX ix_itens_documento_tesouraria_projeto_id ON itens_documento_tesouraria (projeto_id)');
        DB::statement('CREATE INDEX ix_itens_documento_tesouraria_unidade_negocio_id ON itens_documento_tesouraria (unidade_negocio_id)');
        DB::statement('CREATE INDEX ix_itens_documento_tesouraria_centro_custo_id ON itens_documento_tesouraria (centro_custo_id)');
        DB::statement('CREATE INDEX ix_itens_documento_tesouraria_taxa_cambio_id ON itens_documento_tesouraria (taxa_cambio_id)');
        DB::statement('CREATE INDEX ix_itens_documento_tesouraria_venda_id ON itens_documento_tesouraria (venda_id)');
        DB::statement('CREATE INDEX ix_itens_documento_tesouraria_fatura_compra_id ON itens_documento_tesouraria (fatura_compra_id)');
        DB::statement('ALTER TABLE itens_documento_tesouraria ADD CONSTRAINT ck_itens_tesouraria_tipo_dc CHECK (tipo_dc IN (\'D\',\'C\'))');

        // bank_statement_lines (legado) -> linhas_extrato_bancario · 2821 linhas reais no backup
        Schema::create('linhas_extrato_bancario', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->string('codigo_conta', 20)->nullable()->comment('legado: account_code');
            $table->date('data')->nullable()->comment('legado: date');
            $table->string('referencia', 50)->nullable()->comment('legado: reference · tipos mistos: string=614, string_inteiro=2207');
            $table->text('descricao')->nullable()->comment('legado: description');
            $table->decimal('valor', 15, 2)->nullable()->comment('legado: value · tipos mistos: inteiro=2391, decimal=430');
            $table->string('tipo_dc', 10)->nullable()->comment('legado: type_dc');
            $table->string('estado', 20)->nullable()->comment('legado: status · código normalizado ∈ {PENDENTE, CONCILIADO, ANULADO}; texto original em estado_original');
            $table->string('estado_original', 100)->nullable()->comment('legado: status · texto exacto do legado');
            $table->string('reconciliacao_codigo', 30)->nullable()->comment('legado: reconciliation_id');
            $table->string('lote_codigo', 30)->nullable()->comment('legado: batch_id');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_linhas_extrato_bancario_empresa_id ON linhas_extrato_bancario (empresa_id)');
        DB::statement('CREATE INDEX ix_linhas_extrato_bancario_empresa_id_codigo_conta_data ON linhas_extrato_bancario (empresa_id, codigo_conta, data)');
        DB::statement('CREATE INDEX ix_linhas_extrato_bancario_empresa_id_reconciliacao_codigo ON linhas_extrato_bancario (empresa_id, reconciliacao_codigo)');
        DB::statement('ALTER TABLE linhas_extrato_bancario ADD CONSTRAINT ck_extrato_tipo_dc CHECK (tipo_dc IN (\'D\',\'C\'))');
        DB::statement('ALTER TABLE linhas_extrato_bancario ADD CONSTRAINT ck_linhas_extrato_bancario_estado CHECK (estado IS NULL OR estado IN (\'PENDENTE\',\'CONCILIADO\',\'ANULADO\'))');

        // reconciliations (legado) -> reconciliacoes_bancarias · 2469 linhas reais no backup
        Schema::create('reconciliacoes_bancarias', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->string('reconciliacao_codigo', 50)->nullable()->comment('legado: reconciliation_id');
            $table->timestampTz('data')->nullable()->comment('legado: date · tipos mistos: string_data=1104, string_datahora=1365');
            $table->decimal('valor_total', 15, 2)->nullable()->comment('legado: total_value · tipos mistos: inteiro=1829, decimal=640');
            $table->string('estado', 30)->nullable()->comment('legado: status');
            $table->string('importacao_codigo', 30)->nullable()->comment('legado: import_id');
            $table->string('tipo', 21)->nullable()->comment('legado: type · código normalizado ∈ {ATUALIZACAO_LOTE}; texto original em tipo_original');
            $table->string('tipo_original', 100)->nullable()->comment('legado: type · texto exacto do legado');
            $table->text('detalhes')->nullable()->comment('legado: details');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_reconciliacoes_bancarias_empresa_id ON reconciliacoes_bancarias (empresa_id)');
        DB::statement('ALTER TABLE reconciliacoes_bancarias ADD CONSTRAINT ck_reconciliacoes_bancarias_tipo CHECK (tipo IS NULL OR tipo IN (\'ATUALIZACAO_LOTE\'))');

        // reconciliation_matches (legado) -> correspondencias_reconciliacao · 1788 linhas reais no backup
        Schema::create('correspondencias_reconciliacao', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->string('reconciliacao_codigo', 30)->nullable()->comment('legado: reconciliation_id');
            $table->bigInteger('lancamento_contabil_id')->nullable()->comment('legado: internal_id');
            $table->bigInteger('linha_extrato_bancario_id')->nullable()->comment('legado: external_id');
            $table->string('tipo_correspondencia', 20)->nullable()->comment('legado: match_type · código normalizado ∈ {AUTOMATICA, MANUAL}; texto original em tipo_correspondencia_original');
            $table->string('tipo_correspondencia_original', 100)->nullable()->comment('legado: match_type · texto exacto do legado');
            $table->decimal('valor', 15, 2)->nullable()->comment('legado: value · tipos mistos: inteiro=1512, decimal=276');
            $table->timestampTz('data')->nullable()->comment('legado: date');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_correspondencias_reconciliacao_empresa_id ON correspondencias_reconciliacao (empresa_id)');
        DB::statement('CREATE INDEX ix_correspondencias_reconciliacao_lancamento_contabil_id ON correspondencias_reconciliacao (lancamento_contabil_id)');
        DB::statement('CREATE INDEX ix_correspondencias_reconciliacao_linha_extrato_bancario_id ON correspondencias_reconciliacao (linha_extrato_bancario_id)');
        DB::statement('ALTER TABLE correspondencias_reconciliacao ADD CONSTRAINT ck_correspondencias_reconciliacao_tipo_correspondencia CHECK (tipo_correspondencia IS NULL OR tipo_correspondencia IN (\'AUTOMATICA\',\'MANUAL\'))');

        // payment_methods (legado) -> meios_pagamento · 3 linhas reais no backup · eliminação lógica
        Schema::create('meios_pagamento', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->string('nome', 255)->nullable()->comment('legado: name');
            $table->string('codigo_conta', 20)->nullable()->comment('legado: account_code');
            $table->string('iban', 50)->nullable()->comment('legado: iban');
            $table->string('swift', 20)->nullable()->comment('legado: swift');
            $table->boolean('ativo')->nullable()->comment('legado: is_active');
            $table->boolean('predefinido')->nullable()->comment('legado: is_default');
            $table->string('codigo_moeda', 50)->nullable()->comment('legado: currency');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
            $table->timestampTz('eliminado_em')->nullable();
        });
        DB::statement('CREATE INDEX ix_meios_pagamento_empresa_id ON meios_pagamento (empresa_id)');

        // cash_audits (legado) -> conferencias_caixa · 1 linhas reais no backup
        Schema::create('conferencias_caixa', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->string('codigo_conta', 20)->nullable()->comment('legado: account_code');
            $table->string('nome_conta', 255)->nullable()->comment('legado: account_name');
            $table->timestampTz('data_conferencia')->nullable()->comment('legado: audit_date');
            $table->string('nome_operador', 255)->nullable()->comment('legado: operator_name');
            $table->text('denominacoes')->nullable()->comment('legado: denominations');
            $table->decimal('total_fisico', 15, 2)->nullable()->comment('legado: total_physical');
            $table->decimal('total_sistema', 15, 2)->nullable()->comment('legado: total_system');
            $table->decimal('saldo_externo', 15, 2)->nullable()->comment('legado: external_balance');
            $table->decimal('diferenca', 15, 2)->nullable()->comment('legado: difference');
            $table->text('justificacao')->nullable()->comment('legado: justification');
            $table->text('conta_regularizacao')->nullable()->comment('legado: regularization_account · sem valores reais: tipo a confirmar no código legado');
            $table->text('nome_conta_regularizacao')->nullable()->comment('legado: regularization_account_name · sem valores reais: tipo a confirmar no código legado');
            $table->text('referencia_lancamento')->nullable()->comment('legado: journal_entry_ref · sem valores reais: tipo a confirmar no código legado');
            $table->string('estado', 20)->nullable()->comment('legado: status · código normalizado ∈ {RASCUNHO, FINALIZADO}; texto original em estado_original');
            $table->string('estado_original', 100)->nullable()->comment('legado: status · texto exacto do legado');
            $table->string('nome_gerente', 255)->nullable()->comment('legado: manager_name · do código legado js/ui_tesouraria.js:7438');
            $table->timestampTz('assinado_gerente_em')->nullable()->comment('legado: manager_signed_at · do código legado js/ui_tesouraria.js:7439');
            $table->timestampTz('criado_em')->nullable()->useCurrent()->comment('legado: created_at');
            $table->timestampTz('atualizado_em')->nullable()->useCurrent()->comment('legado: updated_at');
        });
        DB::statement('CREATE INDEX ix_conferencias_caixa_empresa_id ON conferencias_caixa (empresa_id)');
        DB::statement('ALTER TABLE conferencias_caixa ADD CONSTRAINT ck_conferencias_caixa_estado CHECK (estado IS NULL OR estado IN (\'RASCUNHO\',\'FINALIZADO\'))');

        // cash_sessions (legado) -> sessoes_caixa · 12 linhas reais no backup
        Schema::create('sessoes_caixa', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->string('codigo_conta', 20)->nullable()->comment('legado: account_code');
            $table->string('operador', 100)->nullable()->comment('legado: operator');
            $table->date('data_abertura')->nullable()->comment('legado: open_date');
            $table->date('data_fecho')->nullable()->comment('legado: close_date');
            $table->decimal('saldo_abertura', 15, 2)->nullable()->comment('legado: opening_balance · tipos mistos: inteiro=10, decimal=2');
            $table->decimal('saldo_fecho', 15, 2)->nullable()->comment('legado: closing_balance · tipos mistos: decimal=5, inteiro=6');
            $table->decimal('saldo_fisico', 15, 2)->nullable()->comment('legado: physical_balance · tipos mistos: decimal=5, inteiro=6');
            $table->string('estado', 20)->nullable()->comment('legado: status · código normalizado ∈ {ABERTA, FECHADA, CONTABILIZADA}; texto original em estado_original');
            $table->string('estado_original', 100)->nullable()->comment('legado: status · texto exacto do legado');
            $table->string('codigo_moeda', 50)->nullable()->comment('legado: currency');
            $table->jsonb('numeros_lan_contabilizacao')->nullable()->comment('Lançamentos da contabilização (um por data de movimento + diferença de fecho)');
            $table->string('fechado_por', 100)->nullable()->comment('Utilizador que fechou');
            $table->timestampTz('contabilizado_em')->nullable()->comment('Data/hora da contabilização');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_sessoes_caixa_empresa_id ON sessoes_caixa (empresa_id)');
        DB::statement('ALTER TABLE sessoes_caixa ADD CONSTRAINT ck_sessoes_caixa_estado CHECK (estado IS NULL OR estado IN (\'ABERTA\',\'FECHADA\',\'CONTABILIZADA\'))');

        // cash_lines (legado) -> movimentos_caixa · 186 linhas reais no backup
        Schema::create('movimentos_caixa', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->bigInteger('sessao_caixa_id')->nullable()->comment('legado: session_id');
            $table->string('tipo', 20)->nullable()->comment('legado: type · código normalizado ∈ {REC, PAG}; texto original em tipo_original');
            $table->string('tipo_original', 100)->nullable()->comment('legado: type · texto exacto do legado');
            $table->date('data_documento')->nullable()->comment('legado: doc_date');
            $table->string('numero_documento', 50)->nullable()->comment('legado: doc_number · tipos mistos: string=183, string_inteiro=2');
            $table->string('referencia', 100)->nullable()->comment('legado: reference · tipos mistos: string=183, string_inteiro=2');
            $table->bigInteger('terceiro_id')->nullable()->comment('legado: third_party_id');
            $table->bigInteger('produto_id')->nullable()->comment('legado: product_id');
            $table->string('conta_debito', 20)->nullable()->comment('legado: account_debit');
            $table->string('conta_credito', 20)->nullable()->comment('legado: account_credit');
            $table->text('descricao')->nullable()->comment('legado: description');
            $table->decimal('valor', 15, 2)->nullable()->comment('legado: value · tipos mistos: inteiro=168, decimal=18');
            $table->bigInteger('unidade_negocio_id')->nullable()->comment('legado: bu_id');
            $table->bigInteger('centro_custo_id')->nullable()->comment('legado: cc_id');
            $table->string('tipo_origem', 20)->nullable()->comment('legado: source_type · código normalizado ∈ {CONTABILIDADE, IMPORTACAO, MANUAL, FATURA_COMPRA, POS}; texto original em tipo_origem_original');
            $table->string('tipo_origem_original', 100)->nullable()->comment('legado: source_type · texto exacto do legado');
            $table->bigInteger('origem_id')->nullable()->comment('legado: source_id');
            $table->boolean('contabilizado')->nullable()->comment('legado: is_posted');
            $table->string('codigo_moeda', 50)->nullable()->comment('legado: currency');
            $table->decimal('taxa_cambio', 18, 6)->nullable()->comment('legado: exchange_rate');
            $table->bigInteger('taxa_cambio_id')->nullable()->comment('legado: exchange_rate_id');
            $table->decimal('valor_kz', 15, 2)->nullable()->comment('legado: value_kz');
            $table->bigInteger('nota_demonstracao_id')->nullable()->comment('legado: demo_note_id');
            $table->bigInteger('nota_fluxo_caixa_id')->nullable()->comment('legado: cashflow_note_id');
            $table->string('url_documento', 150)->nullable()->comment('legado: doc_url');
            $table->decimal('contravalor_kz', 15, 2)->nullable()->comment('legado: contra_value_kz · do código legado js/moedas_tesouraria.js:384');
            $table->string('contra_moeda', 255)->nullable()->comment('legado: contra_currency · do código legado js/moedas_tesouraria.js:384');
            $table->string('contravalor_moeda', 255)->nullable()->comment('legado: contra_value_currency · do código legado js/moedas_tesouraria.js:384');
            $table->bigInteger('venda_id')->nullable()->comment('Factura de venda recebida por este movimento');
            $table->bigInteger('fatura_compra_id')->nullable()->comment('Factura de fornecedor paga por este movimento');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_movimentos_caixa_empresa_id ON movimentos_caixa (empresa_id)');
        DB::statement('CREATE INDEX ix_movimentos_caixa_sessao_caixa_id ON movimentos_caixa (sessao_caixa_id)');
        DB::statement('CREATE INDEX ix_movimentos_caixa_terceiro_id ON movimentos_caixa (terceiro_id)');
        DB::statement('CREATE INDEX ix_movimentos_caixa_produto_id ON movimentos_caixa (produto_id)');
        DB::statement('CREATE INDEX ix_movimentos_caixa_unidade_negocio_id ON movimentos_caixa (unidade_negocio_id)');
        DB::statement('CREATE INDEX ix_movimentos_caixa_centro_custo_id ON movimentos_caixa (centro_custo_id)');
        DB::statement('CREATE INDEX ix_movimentos_caixa_taxa_cambio_id ON movimentos_caixa (taxa_cambio_id)');
        DB::statement('CREATE INDEX ix_movimentos_caixa_nota_demonstracao_id ON movimentos_caixa (nota_demonstracao_id)');
        DB::statement('CREATE INDEX ix_movimentos_caixa_nota_fluxo_caixa_id ON movimentos_caixa (nota_fluxo_caixa_id)');
        DB::statement('CREATE INDEX ix_movimentos_caixa_venda_id ON movimentos_caixa (venda_id)');
        DB::statement('CREATE INDEX ix_movimentos_caixa_fatura_compra_id ON movimentos_caixa (fatura_compra_id)');
        DB::statement('ALTER TABLE movimentos_caixa ADD CONSTRAINT ck_movimentos_caixa_tipo CHECK (tipo IS NULL OR tipo IN (\'REC\',\'PAG\'))');
        DB::statement('ALTER TABLE movimentos_caixa ADD CONSTRAINT ck_movimentos_caixa_tipo_origem CHECK (tipo_origem IS NULL OR tipo_origem IN (\'CONTABILIDADE\',\'IMPORTACAO\',\'MANUAL\',\'FATURA_COMPRA\',\'POS\'))');

        // configuracoes_contabeis_tesouraria (tabela nova) · 0 linhas reais no backup
        Schema::create('configuracoes_contabeis_tesouraria', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('tenant (derivado no ETL)');
            $table->string('chave', 150)->nullable()->comment('Chave da conta (ver ServicoConfigTesouraria::CHAVES)');
            $table->string('codigo_conta', 20)->nullable()->comment('Conta do plano');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE UNIQUE INDEX uq_configuracoes_contabeis_tesouraria_empresa_id_chave ON configuracoes_contabeis_tesouraria (empresa_id, chave)');

        // rascunhos_reconciliacao (tabela nova) · 0 linhas reais no backup
        Schema::create('rascunhos_reconciliacao', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('tenant (derivado no ETL)');
            $table->string('codigo_conta', 20)->comment('Conta bancária (43) reconciliada');
            $table->date('periodo_inicio')->nullable()->comment('Início do período trabalhado');
            $table->date('periodo_fim')->nullable()->comment('Fim do período trabalhado');
            $table->jsonb('grupos')->comment('Grupos emparelhados por confirmar: [{extrato: [ids], lancamentos: [ids]}]');
            $table->text('observacoes')->nullable()->comment('Notas do autor');
            $table->string('criado_por', 100)->nullable()->comment('Utilizador que gravou o rascunho');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_rascunhos_reconciliacao_empresa_id ON rascunhos_reconciliacao (empresa_id)');
        DB::statement('CREATE INDEX ix_rascunhos_reconciliacao_empresa_id_codigo_conta ON rascunhos_reconciliacao (empresa_id, codigo_conta)');
    }

    public function down(): void
    {
        Schema::dropIfExists('rascunhos_reconciliacao');
        Schema::dropIfExists('configuracoes_contabeis_tesouraria');
        Schema::dropIfExists('movimentos_caixa');
        Schema::dropIfExists('sessoes_caixa');
        Schema::dropIfExists('conferencias_caixa');
        Schema::dropIfExists('meios_pagamento');
        Schema::dropIfExists('correspondencias_reconciliacao');
        Schema::dropIfExists('reconciliacoes_bancarias');
        Schema::dropIfExists('linhas_extrato_bancario');
        Schema::dropIfExists('itens_documento_tesouraria');
        Schema::dropIfExists('documentos_tesouraria');
    }
};
