<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo Activos — GERADO por ferramentas/gerador/gerar_esquema.mjs a partir de docs/dicionario/mapa_de_para.json.
 * Não editar à mão: alterar glossario.mjs / esquema_extra.mjs e regenerar.
 * As chaves estrangeiras são criadas em 2026_09_30_900000_criar_chaves_estrangeiras.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        // fixed_assets (legado) -> ativos_imobilizados · 174 linhas reais no backup · eliminação lógica
        Schema::create('ativos_imobilizados', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->string('codigo', 50)->nullable()->comment('legado: code');
            $table->text('descricao')->nullable()->comment('legado: description');
            $table->bigInteger('categoria_ativo_id')->nullable()->comment('legado: category_id');
            $table->bigInteger('centro_custo_id')->nullable()->comment('legado: cost_center_id');
            $table->decimal('valor_aquisicao', 15, 2)->nullable()->comment('legado: acquisition_value · tipos mistos: decimal=91, inteiro=83');
            $table->integer('vida_util')->nullable()->comment('legado: useful_life');
            $table->date('data_aquisicao')->nullable()->comment('legado: acquisition_date');
            $table->string('estado', 20)->nullable()->comment('legado: status · código normalizado ∈ {ACTIVO, INACTIVO, ABATIDO}; texto original em estado_original');
            $table->string('estado_original', 100)->nullable()->comment('legado: status · texto exacto do legado');
            $table->bigInteger('lancamento_contabil_id')->nullable()->comment('legado: journal_line_id');
            $table->bigInteger('fornecedor_id')->nullable()->comment('legado: supplier_id');
            $table->decimal('valor_residual', 15, 2)->nullable()->comment('legado: residual_value');
            $table->decimal('amortizacao_acumulada', 15, 2)->nullable()->comment('legado: accumulated_depreciation · tipos mistos: decimal=127, inteiro=47');
            $table->integer('vida_util_restante')->nullable()->comment('legado: remaining_life');
            $table->decimal('amortizacao_acumulada_inicial', 15, 2)->nullable()->comment('legado: accumulated_dep_initial · tipos mistos: inteiro=94, decimal=72');
            $table->decimal('quota_fixa', 15, 2)->nullable()->comment('legado: fixed_quota · tipos mistos: inteiro=10, decimal=32');
            $table->integer('acumulado_fim_ano')->nullable()->comment('legado: accumulated_end_year · tipo forçado (inferido: numeric(15,2))');
            $table->bigInteger('unidade_negocio_id')->nullable()->comment('legado: business_unit_id');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
            $table->timestampTz('eliminado_em')->nullable();
        });
        DB::statement('CREATE UNIQUE INDEX uq_ativos_imobilizados_empresa_id_codigo ON ativos_imobilizados (empresa_id, codigo) WHERE eliminado_em IS NULL');
        DB::statement('CREATE INDEX ix_ativos_imobilizados_categoria_ativo_id ON ativos_imobilizados (categoria_ativo_id)');
        DB::statement('CREATE INDEX ix_ativos_imobilizados_centro_custo_id ON ativos_imobilizados (centro_custo_id)');
        DB::statement('CREATE INDEX ix_ativos_imobilizados_lancamento_contabil_id ON ativos_imobilizados (lancamento_contabil_id)');
        DB::statement('CREATE INDEX ix_ativos_imobilizados_fornecedor_id ON ativos_imobilizados (fornecedor_id)');
        DB::statement('CREATE INDEX ix_ativos_imobilizados_unidade_negocio_id ON ativos_imobilizados (unidade_negocio_id)');
        DB::statement('ALTER TABLE ativos_imobilizados ADD CONSTRAINT ck_ativos_imobilizados_estado CHECK (estado IS NULL OR estado IN (\'ACTIVO\',\'INACTIVO\',\'ABATIDO\'))');

        // asset_categories (legado) -> categorias_ativos · 11 linhas reais no backup · eliminação lógica
        Schema::create('categorias_ativos', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->string('nome', 255)->nullable()->comment('legado: name');
            $table->decimal('taxa_anual', 9, 4)->nullable()->comment('legado: annual_rate · tipos mistos: inteiro=10, decimal=1');
            $table->integer('vida_util_padrao')->nullable()->comment('legado: default_useful_life');
            $table->string('conta_gasto', 20)->nullable()->comment('legado: account_expense');
            $table->string('conta_amortizacao_acumulada', 20)->nullable()->comment('legado: account_accumulated');
            $table->string('conta_venda', 20)->nullable()->comment('legado: account_sale');
            $table->string('conta_perda', 20)->nullable()->comment('legado: account_loss');
            $table->string('conta_ativo', 255)->nullable()->comment('legado: account_asset · do código legado js/ui_assets.js:824');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
            $table->timestampTz('eliminado_em')->nullable();
        });
        DB::statement('CREATE INDEX ix_categorias_ativos_empresa_id ON categorias_ativos (empresa_id)');

        // asset_depreciations (legado) -> amortizacoes_ativos · 1448 linhas reais no backup
        Schema::create('amortizacoes_ativos', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->bigInteger('ativo_imobilizado_id')->nullable()->comment('legado: asset_id');
            $table->string('periodo_codigo', 20)->nullable()->comment('legado: period_id');
            $table->boolean('contabilizado')->nullable()->comment('legado: is_posted');
            $table->decimal('valor', 15, 2)->nullable()->comment('legado: value · tipos mistos: decimal=1377, inteiro=71');
            $table->date('data')->nullable()->comment('legado: date');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE UNIQUE INDEX uq_amortizacoes_ativos_empresa__ativo_im_periodo_ ON amortizacoes_ativos (empresa_id, ativo_imobilizado_id, periodo_codigo)');
        DB::statement('CREATE INDEX ix_amortizacoes_ativos_ativo_imobilizado_id ON amortizacoes_ativos (ativo_imobilizado_id)');
        DB::statement('CREATE INDEX ix_amortizacoes_ativos_empresa__ativo_im_periodo_ ON amortizacoes_ativos (empresa_id, ativo_imobilizado_id, periodo_codigo)');

        // asset_disposals (legado) -> abates_vendas_ativos · 0 linhas reais no backup
        Schema::create('abates_vendas_ativos', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id · do código legado js/ui_assets.js:2509');
            $table->bigInteger('ativo_imobilizado_id')->nullable()->comment('legado: asset_id · do código legado js/ui_assets.js:2510');
            $table->string('tipo', 255)->nullable()->comment('legado: type · do código legado js/ui_assets.js:2511');
            $table->date('data')->nullable()->comment('legado: date · do código legado js/ui_assets.js:2512');
            $table->text('descricao')->nullable()->comment('legado: description · do código legado js/ui_assets.js:2513');
            $table->decimal('valor', 15, 2)->nullable()->comment('legado: value · do código legado js/ui_assets.js:2514');
            $table->bigInteger('terceiro_id')->nullable()->comment('legado: third_party_id · do código legado js/ui_assets.js:2515');
            $table->string('conta_terceiro', 255)->nullable()->comment('legado: account_third · do código legado js/ui_assets.js:2516');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_abates_vendas_ativos_empresa_id ON abates_vendas_ativos (empresa_id)');
        DB::statement('CREATE INDEX ix_abates_vendas_ativos_ativo_imobilizado_id ON abates_vendas_ativos (ativo_imobilizado_id)');
        DB::statement('CREATE INDEX ix_abates_vendas_ativos_terceiro_id ON abates_vendas_ativos (terceiro_id)');

        // asset_maintenance_plans (legado) -> planos_manutencao_ativos · 0 linhas reais no backup
        Schema::create('planos_manutencao_ativos', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id · do código legado js/db_v2.js:51');
            $table->bigInteger('ativo_imobilizado_id')->nullable()->comment('legado: asset_id · do código legado js/db_v2.js:51');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_planos_manutencao_ativos_empresa_id ON planos_manutencao_ativos (empresa_id)');
        DB::statement('CREATE INDEX ix_planos_manutencao_ativos_ativo_imobilizado_id ON planos_manutencao_ativos (ativo_imobilizado_id)');

        // asset_maintenance_records (legado) -> registos_manutencao_ativos · 1 linhas reais no backup
        Schema::create('registos_manutencao_ativos', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->bigInteger('ativo_imobilizado_id')->nullable()->comment('legado: asset_id');
            $table->string('tipo', 20)->nullable()->comment('legado: type · código normalizado ∈ {PREVENTIVA, CORRECTIVA}; texto original em tipo_original');
            $table->string('tipo_original', 100)->nullable()->comment('legado: type · texto exacto do legado');
            $table->date('data')->nullable()->comment('legado: date');
            $table->text('descricao')->nullable()->comment('legado: description');
            $table->decimal('custo', 15, 2)->nullable()->comment('legado: cost');
            $table->string('estado', 20)->nullable()->comment('legado: status · código normalizado ∈ {PLANEADA, EM_CURSO, CONCLUIDA}; texto original em estado_original');
            $table->string('estado_original', 100)->nullable()->comment('legado: status · texto exacto do legado');
            $table->text('resolucao')->nullable()->comment('legado: resolution');
            $table->date('data_execucao')->nullable()->comment('legado: execution_date');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_registos_manutencao_ativos_empresa_id ON registos_manutencao_ativos (empresa_id)');
        DB::statement('CREATE INDEX ix_registos_manutencao_ativos_ativo_imobilizado_id ON registos_manutencao_ativos (ativo_imobilizado_id)');
        DB::statement('ALTER TABLE registos_manutencao_ativos ADD CONSTRAINT ck_registos_manutencao_ativos_tipo CHECK (tipo IS NULL OR tipo IN (\'PREVENTIVA\',\'CORRECTIVA\'))');
        DB::statement('ALTER TABLE registos_manutencao_ativos ADD CONSTRAINT ck_registos_manutencao_ativos_estado CHECK (estado IS NULL OR estado IN (\'PLANEADA\',\'EM_CURSO\',\'CONCLUIDA\'))');

        // asset_movements (legado) -> transferencias_centros_custo_ativos · 1 linhas reais no backup
        Schema::create('transferencias_centros_custo_ativos', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->bigInteger('ativo_imobilizado_id')->nullable()->comment('legado: asset_id');
            $table->bigInteger('centro_custo_origem_id')->nullable()->comment('legado: from_cc_id');
            $table->bigInteger('centro_custo_destino_id')->nullable()->comment('legado: to_cc_id');
            $table->date('data')->nullable()->comment('legado: date');
            $table->bigInteger('projeto_id')->nullable()->comment('legado: project_id');
            $table->text('codigo_projeto')->nullable()->comment('legado: project_code · sem valores reais: tipo a confirmar no código legado');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_transferencias_centros_custo_ativos_empresa_id ON transferencias_centros_custo_ativos (empresa_id)');
        DB::statement('CREATE INDEX ix_transferencias_centros_custo_ativos_ativo_imobilizado_id ON transferencias_centros_custo_ativos (ativo_imobilizado_id)');
        DB::statement('CREATE INDEX ix_transferencias_centros_custo_ativos_centro_custo_origem_id ON transferencias_centros_custo_ativos (centro_custo_origem_id)');
        DB::statement('CREATE INDEX ix_transferencias_centros_custo_ativos_centro_custo_destino_id ON transferencias_centros_custo_ativos (centro_custo_destino_id)');
        DB::statement('CREATE INDEX ix_transferencias_centros_custo_ativos_projeto_id ON transferencias_centros_custo_ativos (projeto_id)');

        // maintenance_requests (legado) -> pedidos_manutencao_equipamentos · 0 linhas reais no backup
        Schema::create('pedidos_manutencao_equipamentos', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->nullable()->comment('legado: maint_company_id · do código legado js/manutencao.js:525');
            $table->string('estado', 255)->nullable()->comment('legado: status · do código legado js/manutencao.js:329');
            $table->jsonb('historico_alteracoes')->nullable()->comment('legado: history · do código legado js/manutencao.js:329');
            $table->string('ambito', 255)->nullable()->comment('legado: scope · do código legado js/manutencao.js:525');
            $table->string('nome_empresa_ambito', 255)->nullable()->comment('legado: scope_company_name · do código legado js/manutencao.js:525');
            $table->string('acao', 255)->nullable()->comment('legado: action · do código legado js/manutencao.js:526');
            $table->string('rotulo_acao', 255)->nullable()->comment('legado: action_label · do código legado js/manutencao.js:526');
            $table->jsonb('parametros')->nullable()->comment('legado: params · do código legado js/manutencao.js:526');
            $table->string('resumo_parametros', 255)->nullable()->comment('legado: params_resumo · do código legado js/manutencao.js:526');
            $table->text('justificacao')->nullable()->comment('legado: justification · do código legado js/manutencao.js:527');
            $table->jsonb('impacto_no_pedido')->nullable()->comment('legado: impact_at_request · do código legado js/manutencao.js:527');
            $table->jsonb('pedido_por')->nullable()->comment('legado: requested_by · do código legado js/manutencao.js:528');
            $table->jsonb('aprovado_por')->nullable()->comment('legado: approved_by · do código legado js/manutencao.js:613');
            $table->timestampTz('aprovado_em')->nullable()->comment('legado: approved_at · do código legado js/manutencao.js:613');
            $table->string('nota_aprovacao', 255)->nullable()->comment('legado: approval_note · do código legado js/manutencao.js:613');
            $table->timestampTz('expira_em')->nullable()->comment('legado: expires_at · do código legado js/manutencao.js:614');
            $table->jsonb('impacto_na_aprovacao')->nullable()->comment('legado: impact_at_approval · do código legado js/manutencao.js:614');
            $table->jsonb('rejeitado_por')->nullable()->comment('legado: rejected_by · do código legado js/manutencao.js:624');
            $table->timestampTz('rejeitado_em')->nullable()->comment('legado: rejected_at · do código legado js/manutencao.js:624');
            $table->text('motivo_rejeicao')->nullable()->comment('legado: rejection_reason · do código legado js/manutencao.js:624');
            $table->jsonb('executado_por')->nullable()->comment('legado: executed_by · do código legado js/manutencao.js:690');
            $table->timestampTz('executado_em')->nullable()->comment('legado: executed_at · do código legado js/manutencao.js:690');
            $table->jsonb('impacto_na_execucao')->nullable()->comment('legado: impact_at_execution · do código legado js/manutencao.js:690');
            $table->string('resultado', 255)->nullable()->comment('legado: result · do código legado js/manutencao.js:690');
            $table->jsonb('cancelado_por')->nullable()->comment('legado: cancelled_by · do código legado js/manutencao.js:724');
            $table->timestampTz('cancelado_em')->nullable()->comment('legado: cancelled_at · do código legado js/manutencao.js:724');
            $table->timestampTz('criado_em')->nullable()->useCurrent()->comment('legado: created_at · do código legado js/manutencao.js:528');
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_pedidos_manutencao_equipamentos_empresa_id ON pedidos_manutencao_equipamentos (empresa_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('pedidos_manutencao_equipamentos');
        Schema::dropIfExists('transferencias_centros_custo_ativos');
        Schema::dropIfExists('registos_manutencao_ativos');
        Schema::dropIfExists('planos_manutencao_ativos');
        Schema::dropIfExists('abates_vendas_ativos');
        Schema::dropIfExists('amortizacoes_ativos');
        Schema::dropIfExists('categorias_ativos');
        Schema::dropIfExists('ativos_imobilizados');
    }
};
