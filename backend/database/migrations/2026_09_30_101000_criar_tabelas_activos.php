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
        // fixed_assets (legado) -> ativos_imobilizados · 176 linhas reais no backup · eliminação lógica
        Schema::create('ativos_imobilizados', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->string('codigo', 50)->nullable()->comment('legado: code');
            $table->text('descricao')->nullable()->comment('legado: description');
            $table->bigInteger('categoria_ativo_id')->nullable()->comment('legado: category_id');
            $table->bigInteger('centro_custo_id')->nullable()->comment('legado: cost_center_id');
            $table->decimal('valor_aquisicao', 15, 2)->nullable()->comment('legado: acquisition_value · tipos mistos: decimal=91, inteiro=85');
            $table->integer('vida_util')->nullable()->comment('legado: useful_life');
            $table->date('data_aquisicao')->nullable()->comment('legado: acquisition_date');
            $table->string('estado', 20)->nullable()->comment('legado: status · código normalizado ∈ {ACTIVO, INACTIVO, ABATIDO}; texto original em estado_original');
            $table->string('estado_original', 100)->nullable()->comment('legado: status · texto exacto do legado');
            $table->bigInteger('lancamento_contabil_id')->nullable()->comment('legado: journal_line_id');
            $table->bigInteger('fornecedor_id')->nullable()->comment('legado: supplier_id');
            $table->decimal('valor_residual', 15, 2)->nullable()->comment('legado: residual_value');
            $table->decimal('amortizacao_acumulada', 15, 2)->nullable()->comment('legado: accumulated_depreciation · tipos mistos: decimal=127, inteiro=49');
            $table->integer('vida_util_restante')->nullable()->comment('legado: remaining_life');
            $table->decimal('amortizacao_acumulada_inicial', 15, 2)->nullable()->comment('legado: accumulated_dep_initial · tipos mistos: inteiro=96, decimal=72');
            $table->decimal('quota_fixa', 15, 2)->nullable()->comment('legado: fixed_quota · tipos mistos: inteiro=12, decimal=32');
            $table->bigInteger('edificio_id')->nullable()->comment('legado: building_id');
            $table->bigInteger('andar_id')->nullable()->comment('legado: floor_id');
            $table->bigInteger('porta_id')->nullable()->comment('legado: room_id');
            $table->integer('acumulado_fim_ano')->nullable()->comment('legado: accumulated_end_year · tipo forçado (inferido: numeric(15,2))');
            $table->bigInteger('unidade_negocio_id')->nullable()->comment('legado: business_unit_id');
            $table->string('tipo_ativo', 20)->nullable()->comment('legado: asset_type');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
            $table->timestampTz('eliminado_em')->nullable();
        });
        DB::statement('CREATE UNIQUE INDEX uq_ativos_imobilizados_empresa_id_codigo ON ativos_imobilizados (empresa_id, codigo) WHERE eliminado_em IS NULL');
        DB::statement('CREATE INDEX ix_ativos_imobilizados_categoria_ativo_id ON ativos_imobilizados (categoria_ativo_id)');
        DB::statement('CREATE INDEX ix_ativos_imobilizados_centro_custo_id ON ativos_imobilizados (centro_custo_id)');
        DB::statement('CREATE INDEX ix_ativos_imobilizados_lancamento_contabil_id ON ativos_imobilizados (lancamento_contabil_id)');
        DB::statement('CREATE INDEX ix_ativos_imobilizados_fornecedor_id ON ativos_imobilizados (fornecedor_id)');
        DB::statement('CREATE INDEX ix_ativos_imobilizados_edificio_id ON ativos_imobilizados (edificio_id)');
        DB::statement('CREATE INDEX ix_ativos_imobilizados_andar_id ON ativos_imobilizados (andar_id)');
        DB::statement('CREATE INDEX ix_ativos_imobilizados_porta_id ON ativos_imobilizados (porta_id)');
        DB::statement('CREATE INDEX ix_ativos_imobilizados_unidade_negocio_id ON ativos_imobilizados (unidade_negocio_id)');
        DB::statement('ALTER TABLE ativos_imobilizados ADD CONSTRAINT ck_ativos_imobilizados_estado CHECK (estado IS NULL OR estado IN (\'ACTIVO\',\'INACTIVO\',\'ABATIDO\'))');

        // asset_categories (legado) -> categorias_ativos · 17 linhas reais no backup · eliminação lógica
        Schema::create('categorias_ativos', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->string('nome', 255)->nullable()->comment('legado: name');
            $table->decimal('taxa_anual', 9, 4)->nullable()->comment('legado: annual_rate · tipos mistos: inteiro=15, decimal=2');
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
            $table->decimal('taxa_iva', 5, 2)->nullable()->comment('Venda: taxa de IVA liquidado (0, 5, 7 ou 14 %)');
            $table->decimal('valor_iva', 15, 2)->nullable()->comment('Venda: IVA liquidado (valor × taxa)');
            $table->string('conta_iva', 20)->nullable()->comment('Venda: conta do IVA liquidado creditada');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_abates_vendas_ativos_empresa_id ON abates_vendas_ativos (empresa_id)');
        DB::statement('CREATE INDEX ix_abates_vendas_ativos_ativo_imobilizado_id ON abates_vendas_ativos (ativo_imobilizado_id)');
        DB::statement('CREATE INDEX ix_abates_vendas_ativos_terceiro_id ON abates_vendas_ativos (terceiro_id)');

        // asset_maintenance_plans (legado) -> planos_manutencao_ativos · 1 linhas reais no backup
        Schema::create('planos_manutencao_ativos', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->string('codigo', 50)->nullable()->comment('legado: codigo');
            $table->string('nome', 255)->nullable()->comment('legado: nome');
            $table->bigInteger('ativo_imobilizado_id')->nullable()->comment('legado: asset_id');
            $table->bigInteger('categoria_ativo_id')->nullable()->comment('legado: category_id');
            $table->string('tipo', 20)->nullable()->comment('legado: tipo');
            $table->jsonb('periodicidade')->nullable()->comment('legado: periodicidade');
            $table->date('proxima_data')->nullable()->comment('legado: proxima_data');
            $table->decimal('proximo_contador', 15, 2)->nullable()->comment('legado: proximo_contador · sem valores reais: tipo a confirmar no código legado; tipo forçado (inferido: text)');
            $table->decimal('contador_atual', 15, 2)->nullable()->comment('legado: contador_actual · tipo forçado (inferido: integer)');
            $table->integer('antecedencia_dias')->nullable()->comment('legado: antecedencia_dias');
            $table->decimal('duracao_horas', 12, 3)->nullable()->comment('legado: duracao_horas');
            $table->text('responsavel')->nullable()->comment('legado: responsavel · sem valores reais: tipo a confirmar no código legado');
            $table->text('instrucoes')->nullable()->comment('legado: instrucoes · sem valores reais: tipo a confirmar no código legado');
            $table->jsonb('tarefas')->nullable()->comment('legado: tarefas');
            $table->jsonb('itens')->nullable()->comment('legado: itens');
            $table->boolean('ativo')->nullable()->comment('legado: activo');
            $table->date('ultima_data')->nullable()->comment('legado: ultima_data');
            $table->integer('execucoes')->nullable()->comment('legado: execucoes');
            $table->string('criado_por', 100)->nullable()->comment('legado: criado_por');
            $table->timestampTz('criado_em')->nullable()->useCurrent()->comment('legado: criado_em');
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_planos_manutencao_ativos_empresa_id ON planos_manutencao_ativos (empresa_id)');
        DB::statement('CREATE INDEX ix_planos_manutencao_ativos_ativo_imobilizado_id ON planos_manutencao_ativos (ativo_imobilizado_id)');
        DB::statement('CREATE INDEX ix_planos_manutencao_ativos_categoria_ativo_id ON planos_manutencao_ativos (categoria_ativo_id)');

        // asset_maintenance_records (legado) -> registos_manutencao_ativos · 2 linhas reais no backup
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
            $table->bigInteger('ordem_manutencao_id')->nullable()->comment('legado: order_id');
            $table->string('numero_ordem', 50)->nullable()->comment('legado: order_numero');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_registos_manutencao_ativos_empresa_id ON registos_manutencao_ativos (empresa_id)');
        DB::statement('CREATE INDEX ix_registos_manutencao_ativos_ativo_imobilizado_id ON registos_manutencao_ativos (ativo_imobilizado_id)');
        DB::statement('CREATE INDEX ix_registos_manutencao_ativos_ordem_manutencao_id ON registos_manutencao_ativos (ordem_manutencao_id)');
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

        // asset_floors (legado) -> andares_edificios · 3 linhas reais no backup
        Schema::create('andares_edificios', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->bigInteger('edificio_id')->nullable()->comment('legado: building_id');
            $table->string('numero', 20)->nullable()->comment('legado: numero · tipo forçado (inferido: integer)');
            $table->string('nome', 255)->nullable()->comment('legado: nome');
            $table->text('notas')->nullable()->comment('legado: notas · sem valores reais: tipo a confirmar no código legado');
            $table->integer('ordem')->nullable()->comment('legado: ordem');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_andares_edificios_empresa_id ON andares_edificios (empresa_id)');
        DB::statement('CREATE INDEX ix_andares_edificios_edificio_id ON andares_edificios (edificio_id)');

        // asset_rooms (legado) -> portas_edificios · 8 linhas reais no backup
        Schema::create('portas_edificios', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->bigInteger('edificio_id')->nullable()->comment('legado: building_id');
            $table->bigInteger('andar_id')->nullable()->comment('legado: floor_id');
            $table->string('numero', 20)->nullable()->comment('legado: numero · tipos mistos: string_inteiro=6, string=2; tipo forçado (inferido: varchar(10))');
            $table->text('nome')->nullable()->comment('legado: nome · sem valores reais: tipo a confirmar no código legado');
            $table->string('tipo', 50)->nullable()->comment('legado: tipo');
            $table->integer('capacidade')->nullable()->comment('legado: capacidade');
            $table->integer('ordem')->nullable()->comment('legado: ordem');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_portas_edificios_empresa_id ON portas_edificios (empresa_id)');
        DB::statement('CREATE INDEX ix_portas_edificios_edificio_id ON portas_edificios (edificio_id)');
        DB::statement('CREATE INDEX ix_portas_edificios_andar_id ON portas_edificios (andar_id)');

        // asset_inventories (legado) -> inventarios_fisicos_ativos · 0 linhas reais no backup
        Schema::create('inventarios_fisicos_ativos', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('tenant (derivado no ETL)');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_inventarios_fisicos_ativos_empresa_id ON inventarios_fisicos_ativos (empresa_id)');

        // asset_inventory_lines (legado) -> linhas_inventario_fisico_ativos · 0 linhas reais no backup
        Schema::create('linhas_inventario_fisico_ativos', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('tenant (derivado no ETL)');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_linhas_inventario_fisico_ativos_empresa_id ON linhas_inventario_fisico_ativos (empresa_id)');

        // asset_maintenance_orders (legado) -> ordens_manutencao_ativos · 2 linhas reais no backup
        Schema::create('ordens_manutencao_ativos', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->string('numero', 20)->nullable()->comment('legado: numero');
            $table->bigInteger('ativo_imobilizado_id')->nullable()->comment('legado: asset_id');
            $table->bigInteger('plano_manutencao_id')->nullable()->comment('legado: plan_id');
            $table->string('tipo', 20)->nullable()->comment('legado: tipo');
            $table->string('prioridade', 10)->nullable()->comment('legado: prioridade');
            $table->string('estado', 20)->nullable()->comment('legado: estado');
            $table->date('data_prevista')->nullable()->comment('legado: data_prevista');
            $table->date('data_inicio')->nullable()->comment('legado: data_inicio');
            $table->date('data_fim')->nullable()->comment('legado: data_fim');
            $table->text('descricao')->nullable()->comment('legado: descricao');
            $table->text('responsavel')->nullable()->comment('legado: responsavel · sem valores reais: tipo a confirmar no código legado');
            $table->jsonb('tarefas')->nullable()->comment('legado: tarefas');
            $table->decimal('horas', 12, 3)->nullable()->comment('legado: horas');
            $table->decimal('custo_mao_obra', 15, 2)->nullable()->comment('legado: custo_mao_obra · tipo forçado (inferido: numeric(15,2))');
            $table->decimal('custo_materiais', 15, 2)->nullable()->comment('legado: custo_materiais · tipo forçado (inferido: numeric(15,2))');
            $table->decimal('custo_servicos', 15, 2)->nullable()->comment('legado: custo_servicos · tipo forçado (inferido: numeric(15,2))');
            $table->text('resolucao')->nullable()->comment('legado: resolucao');
            $table->decimal('leitura_contador', 15, 2)->nullable()->comment('legado: leitura_contador · sem valores reais: tipo a confirmar no código legado; tipo forçado (inferido: text)');
            $table->string('criado_por', 100)->nullable()->comment('legado: criado_por');
            $table->text('motivo_cancelamento')->nullable()->comment('legado: motivo_cancelamento');
            $table->timestampTz('cancelado_em')->nullable()->comment('legado: cancelado_em');
            $table->string('cancelado_por', 100)->nullable()->comment('legado: cancelado_por · tipo forçado (inferido: varchar(10))');
            $table->timestampTz('concluido_em')->nullable()->comment('legado: concluido_em');
            $table->string('concluido_por', 100)->nullable()->comment('legado: concluido_por · tipo forçado (inferido: varchar(10))');
            $table->timestampTz('criado_em')->nullable()->useCurrent()->comment('legado: criado_em');
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_ordens_manutencao_ativos_empresa_id ON ordens_manutencao_ativos (empresa_id)');
        DB::statement('CREATE INDEX ix_ordens_manutencao_ativos_ativo_imobilizado_id ON ordens_manutencao_ativos (ativo_imobilizado_id)');
        DB::statement('CREATE INDEX ix_ordens_manutencao_ativos_plano_manutencao_id ON ordens_manutencao_ativos (plano_manutencao_id)');

        // asset_maintenance_order_items (legado) -> itens_ordem_manutencao · 1 linhas reais no backup
        Schema::create('itens_ordem_manutencao', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->bigInteger('ordem_manutencao_id')->nullable()->comment('legado: order_id');
            $table->string('tipo', 20)->nullable()->comment('legado: tipo');
            $table->bigInteger('produto_id')->nullable()->comment('legado: product_id');
            $table->text('descricao')->nullable()->comment('legado: descricao');
            $table->decimal('quantidade', 12, 3)->nullable()->comment('legado: quantidade');
            $table->decimal('quantidade_recebida', 15, 3)->nullable()->comment('legado: quantidade_recebida · tipo forçado (inferido: numeric(12,3))');
            $table->string('estado', 20)->nullable()->comment('legado: estado');
            $table->text('destino')->nullable()->comment('legado: destino · sem valores reais: tipo a confirmar no código legado');
            $table->text('origem_tipo')->nullable()->comment('legado: origem_tipo · sem valores reais: tipo a confirmar no código legado');
            $table->text('origem_id')->nullable()->comment('legado: origem_id · sem valores reais: tipo a confirmar no código legado');
            $table->decimal('custo', 15, 2)->nullable()->comment('legado: custo · tipo forçado (inferido: numeric(15,2))');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_itens_ordem_manutencao_empresa_id ON itens_ordem_manutencao (empresa_id)');
        DB::statement('CREATE INDEX ix_itens_ordem_manutencao_ordem_manutencao_id ON itens_ordem_manutencao (ordem_manutencao_id)');
        DB::statement('CREATE INDEX ix_itens_ordem_manutencao_produto_id ON itens_ordem_manutencao (produto_id)');

        // maint_requisitions (legado) -> requisicoes_manutencao · 0 linhas reais no backup
        Schema::create('requisicoes_manutencao', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('tenant (derivado no ETL)');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_requisicoes_manutencao_empresa_id ON requisicoes_manutencao (empresa_id)');

        // maint_requisition_lines (legado) -> linhas_requisicao_manutencao · 0 linhas reais no backup
        Schema::create('linhas_requisicao_manutencao', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('tenant (derivado no ETL)');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_linhas_requisicao_manutencao_empresa_id ON linhas_requisicao_manutencao (empresa_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('linhas_requisicao_manutencao');
        Schema::dropIfExists('requisicoes_manutencao');
        Schema::dropIfExists('itens_ordem_manutencao');
        Schema::dropIfExists('ordens_manutencao_ativos');
        Schema::dropIfExists('linhas_inventario_fisico_ativos');
        Schema::dropIfExists('inventarios_fisicos_ativos');
        Schema::dropIfExists('portas_edificios');
        Schema::dropIfExists('andares_edificios');
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
