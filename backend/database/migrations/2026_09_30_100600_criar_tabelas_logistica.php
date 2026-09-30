<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo Logística — GERADO por ferramentas/gerador/gerar_esquema.mjs a partir de docs/dicionario/mapa_de_para.json.
 * Não editar à mão: alterar glossario.mjs / esquema_extra.mjs e regenerar.
 * As chaves estrangeiras são criadas em 2026_09_30_900000_criar_chaves_estrangeiras.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        // warehouses (legado) -> armazens · 6 linhas reais no backup · eliminação lógica
        Schema::create('armazens', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->string('nome', 255)->nullable()->comment('legado: name');
            $table->string('localizacao', 150)->nullable()->comment('legado: location');
            $table->string('codigo', 20)->nullable()->comment('Código do armazém');
            $table->boolean('predefinido')->nullable()->comment('Armazém por omissão (o legado usava o primeiro)');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
            $table->timestampTz('eliminado_em')->nullable();
        });
        DB::statement('CREATE INDEX ix_armazens_empresa_id ON armazens (empresa_id)');

        // products (legado) -> produtos · 100 linhas reais no backup · eliminação lógica
        Schema::create('produtos', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->string('codigo', 50)->nullable()->comment('legado: code');
            $table->string('nome', 255)->nullable()->comment('legado: name');
            $table->decimal('preco_unitario', 15, 2)->nullable()->comment('legado: unit_price · tipos mistos: inteiro=99, decimal=1');
            $table->decimal('taxa_imposto', 9, 4)->nullable()->comment('legado: tax_rate');
            $table->decimal('quantidade_stock', 12, 3)->nullable()->comment('legado: stock_qty');
            $table->boolean('movimenta_stock')->nullable()->comment('legado: is_inventory · tipos mistos: boolean=77, inteiro=23');
            $table->string('codigo_conta', 20)->nullable()->comment('legado: account_code');
            $table->string('conta_compra', 20)->nullable()->comment('legado: account_purchase · tipos mistos: string_inteiro=13, inteiro=19');
            $table->string('conta_inventario', 20)->nullable()->comment('legado: account_inventory · tipos mistos: string_inteiro=13, inteiro=19');
            $table->string('conta_custo', 20)->nullable()->comment('legado: account_cost · tipos mistos: string_inteiro=17, inteiro=19');
            $table->bigInteger('categoria_produto_id')->nullable()->comment('legado: category_id');
            $table->string('conta_iva', 20)->nullable()->comment('legado: account_iva · tipos mistos: string_inteiro=26, inteiro=42');
            $table->string('conta_iva_liquidado', 20)->nullable()->comment('legado: account_iva_liquidado');
            $table->string('conta_iva_dedutivel', 20)->nullable()->comment('legado: account_iva_dedutivel');
            $table->string('conta_quebra', 20)->nullable()->comment('legado: account_shortage');
            $table->string('conta_sobra', 20)->nullable()->comment('legado: account_surplus');
            $table->text('imagem_base64')->nullable()->comment('legado: image_base64');
            $table->boolean('e_quarto')->nullable()->comment('legado: is_room');
            $table->boolean('e_ativo_imobilizado')->nullable()->comment('legado: is_asset');
            $table->text('conta_ativo')->nullable()->comment('legado: account_asset · sem valores reais: tipo a confirmar no código legado');
            $table->decimal('preco_por_hora', 15, 2)->nullable()->comment('legado: pricePerHour · tipos Dexie: {"undef":18}');
            $table->decimal('preco_por_dia', 15, 2)->nullable()->comment('legado: pricePerDay · tipos Dexie: {"undef":18}');
            $table->decimal('horas_minimas', 12, 3)->nullable()->comment('legado: minHours · tipos Dexie: {"undef":18}');
            $table->boolean('e_servico')->nullable()->comment('legado: is_service · tipos mistos: inteiro=24, boolean=7');
            $table->boolean('bloqueado')->nullable()->comment('legado: is_blocked');
            $table->string('lavandaria_grupo', 20)->nullable()->comment('legado: lav_group');
            $table->string('lavandaria_unidade', 10)->nullable()->comment('legado: lav_unit');
            $table->integer('lavandaria_dias_entrega')->nullable()->comment('legado: lav_lead_days');
            $table->boolean('lavandaria_requer_orcamento')->nullable()->comment('legado: lav_requires_quote');
            $table->boolean('lavandaria_ativa')->nullable()->comment('legado: lav_active');
            $table->decimal('lavandaria_preco_peca', 15, 2)->nullable()->comment('legado: lav_price_piece');
            $table->decimal('lavandaria_preco_kg', 15, 2)->nullable()->comment('legado: lav_price_kg');
            $table->string('unidade_fe', 10)->nullable()->comment('legado: fe_unidade');
            $table->text('tipo_operacao_fe')->nullable()->comment('legado: fe_tipo_operacao · sem valores reais: tipo a confirmar no código legado');
            $table->text('codigo_isencao_fe')->nullable()->comment('legado: fe_isencao · sem valores reais: tipo a confirmar no código legado');
            $table->decimal('custo_medio', 18, 6)->nullable()->comment('Custo médio ponderado (Kz), actualizado nas entradas de stock');
            $table->decimal('stock_minimo', 12, 3)->nullable()->comment('Stock mínimo (alerta de ruptura; o legado usava ≤ 5 fixo)');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
            $table->timestampTz('eliminado_em')->nullable();
        });
        DB::statement('CREATE UNIQUE INDEX uq_produtos_empresa_id_codigo ON produtos (empresa_id, codigo) WHERE eliminado_em IS NULL');
        DB::statement('CREATE INDEX ix_produtos_categoria_produto_id ON produtos (categoria_produto_id)');

        // product_categories (legado) -> categorias_produtos · 23 linhas reais no backup · eliminação lógica
        Schema::create('categorias_produtos', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->string('nome', 255)->nullable()->comment('legado: name');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
            $table->timestampTz('eliminado_em')->nullable();
        });
        DB::statement('CREATE UNIQUE INDEX uq_categorias_produtos_empresa_id_nome ON categorias_produtos (empresa_id, nome) WHERE eliminado_em IS NULL');

        // warehouse_stock (legado) -> stock_armazem · 16 linhas reais no backup
        Schema::create('stock_armazem', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->bigInteger('armazem_id')->nullable()->comment('legado: warehouse_id');
            $table->bigInteger('produto_id')->nullable()->comment('legado: product_id');
            $table->decimal('quantidade_stock', 12, 3)->nullable()->comment('legado: stock_qty · tipos mistos: inteiro=12, decimal=4');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE UNIQUE INDEX uq_stock_armazem_armazem_id_produto_id ON stock_armazem (armazem_id, produto_id)');
        DB::statement('CREATE INDEX ix_stock_armazem_empresa_id ON stock_armazem (empresa_id)');
        DB::statement('CREATE INDEX ix_stock_armazem_produto_id ON stock_armazem (produto_id)');

        // inventory_movements (legado) -> movimentos_inventario · 102 linhas reais no backup
        Schema::create('movimentos_inventario', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->bigInteger('produto_id')->nullable()->comment('legado: product_id');
            $table->bigInteger('armazem_id')->nullable()->comment('legado: warehouse_id');
            $table->string('tipo', 20)->nullable()->comment('legado: type · código normalizado ∈ {ENTRADA, SAIDA, TRANSFERENCIA, AJUSTE}; texto original em tipo_original');
            $table->string('tipo_original', 100)->nullable()->comment('legado: type · texto exacto do legado');
            $table->decimal('quantidade', 12, 3)->nullable()->comment('legado: quantity · tipos mistos: inteiro=100, decimal=2');
            $table->timestampTz('data')->nullable()->comment('legado: date · tipos mistos: string_datahora=79, string_data=23');
            $table->bigInteger('terceiro_id')->nullable()->comment('legado: third_party_id');
            $table->string('referencia', 100)->nullable()->comment('legado: reference');
            $table->bigInteger('projeto_id')->nullable()->comment('legado: project_id');
            $table->text('codigo_projeto')->nullable()->comment('legado: project_code · sem valores reais: tipo a confirmar no código legado');
            $table->decimal('preco_unitario', 15, 2)->nullable()->comment('legado: unit_price · tipos mistos: inteiro=55, decimal=15');
            $table->string('sentido', 1)->nullable()->comment('E (entrada) ou S (saída) — os ajustes e transferências também têm sentido');
            $table->decimal('valor', 15, 2)->nullable()->comment('Quantidade × custo unitário (Kz)');
            $table->decimal('custo_medio_apos', 18, 6)->nullable()->comment('Custo médio ponderado do produto depois do movimento');
            $table->string('documento_tipo', 30)->nullable()->comment('Origem: RECECAO, VENDA, GUIA, TRANSFERENCIA, INVENTARIO, AJUSTE, MIGRACAO…');
            $table->bigInteger('documento_id')->nullable()->comment('Id do documento de origem');
            $table->bigInteger('armazem_contraparte_id')->nullable()->comment('Transferências: o outro armazém');
            $table->string('criado_por', 100)->nullable()->comment('Quem registou');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_movimentos_inventario_empresa_id ON movimentos_inventario (empresa_id)');
        DB::statement('CREATE INDEX ix_movimentos_inventario_produto_id ON movimentos_inventario (produto_id)');
        DB::statement('CREATE INDEX ix_movimentos_inventario_armazem_id ON movimentos_inventario (armazem_id)');
        DB::statement('CREATE INDEX ix_movimentos_inventario_terceiro_id ON movimentos_inventario (terceiro_id)');
        DB::statement('CREATE INDEX ix_movimentos_inventario_projeto_id ON movimentos_inventario (projeto_id)');
        DB::statement('CREATE INDEX ix_movimentos_inventario_armazem_contraparte_id ON movimentos_inventario (armazem_contraparte_id)');
        DB::statement('CREATE INDEX ix_movimentos_inventario_empresa_id_produto_id_data ON movimentos_inventario (empresa_id, produto_id, data)');
        DB::statement('ALTER TABLE movimentos_inventario ADD CONSTRAINT ck_movimentos_inventario_tipo CHECK (tipo IS NULL OR tipo IN (\'ENTRADA\',\'SAIDA\',\'TRANSFERENCIA\',\'AJUSTE\'))');

        // delivery_notes (legado) -> guias_saida · 11 linhas reais no backup
        Schema::create('guias_saida', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->string('numero_documento', 50)->nullable()->comment('legado: doc_number');
            $table->date('data')->nullable()->comment('legado: date');
            $table->string('tipo', 20)->nullable()->comment('legado: type · código normalizado ∈ {VENDA, BACK_TO_BACK, CONSUMO}; texto original em tipo_original');
            $table->string('tipo_original', 100)->nullable()->comment('legado: type · texto exacto do legado');
            $table->bigInteger('terceiro_id')->nullable()->comment('legado: entity_id');
            $table->bigInteger('armazem_id')->nullable()->comment('legado: warehouse_id');
            $table->text('area_rececao')->nullable()->comment('legado: receiving_area · sem valores reais: tipo a confirmar no código legado');
            $table->string('estado', 20)->nullable()->comment('legado: status · código normalizado ∈ {CONCLUIDO, FATURADA, ANULADA}; texto original em estado_original');
            $table->string('estado_original', 100)->nullable()->comment('legado: status · texto exacto do legado');
            $table->bigInteger('venda_relacionada_id')->nullable()->comment('legado: related_sale_id');
            $table->boolean('contabilizado')->nullable()->comment('legado: is_posted');
            $table->bigInteger('projeto_id')->nullable()->comment('legado: project_id');
            $table->text('codigo_projeto')->nullable()->comment('legado: project_code · sem valores reais: tipo a confirmar no código legado');
            $table->text('observacoes')->nullable()->comment('Observações');
            $table->string('criado_por', 100)->nullable()->comment('Quem emitiu');
            $table->string('numero_lan_contabilizacao', 30)->nullable()->comment('Lançamento do consumo (D custo / C inventário)');
            $table->timestampTz('anulado_em')->nullable()->comment('Anulação (o legado apagava a guia)');
            $table->text('motivo_anulacao')->nullable()->comment('Motivo da anulação');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE UNIQUE INDEX uq_guias_saida_empresa_id_numero_documento ON guias_saida (empresa_id, numero_documento)');
        DB::statement('CREATE INDEX ix_guias_saida_terceiro_id ON guias_saida (terceiro_id)');
        DB::statement('CREATE INDEX ix_guias_saida_armazem_id ON guias_saida (armazem_id)');
        DB::statement('CREATE INDEX ix_guias_saida_venda_relacionada_id ON guias_saida (venda_relacionada_id)');
        DB::statement('CREATE INDEX ix_guias_saida_projeto_id ON guias_saida (projeto_id)');
        DB::statement('ALTER TABLE guias_saida ADD CONSTRAINT ck_guias_saida_tipo CHECK (tipo IS NULL OR tipo IN (\'VENDA\',\'BACK_TO_BACK\',\'CONSUMO\'))');
        DB::statement('ALTER TABLE guias_saida ADD CONSTRAINT ck_guias_saida_estado CHECK (estado IS NULL OR estado IN (\'CONCLUIDO\',\'FATURADA\',\'ANULADA\'))');

        // delivery_items (legado) -> itens_guia_saida · 47 linhas reais no backup
        Schema::create('itens_guia_saida', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('tenant (derivado no ETL)');
            $table->bigInteger('produto_id')->nullable()->comment('legado: product_id');
            $table->decimal('quantidade', 12, 3)->nullable()->comment('legado: quantity · tipos mistos: inteiro=45, decimal=2');
            $table->bigInteger('projeto_id')->nullable()->comment('legado: project_id');
            $table->text('codigo_projeto')->nullable()->comment('legado: project_code · sem valores reais: tipo a confirmar no código legado');
            $table->decimal('cambial_q1', 12, 3)->nullable()->comment('legado: fx_q1');
            $table->decimal('cambial_v1', 15, 2)->nullable()->comment('legado: fx_v1 · tipos mistos: decimal=1, inteiro=2');
            $table->decimal('cambial_q2', 12, 3)->nullable()->comment('legado: fx_q2');
            $table->decimal('cambial_v2', 15, 2)->nullable()->comment('legado: fx_v2');
            $table->decimal('valor_kz', 15, 2)->nullable()->comment('legado: value_kz · tipos mistos: decimal=1, inteiro=2');
            $table->decimal('custo_unitario_kz', 15, 2)->nullable()->comment('legado: unit_cost_kz · tipos mistos: decimal=1, inteiro=2');
            $table->bigInteger('item_compra_id')->nullable()->comment('Recepção: linha da encomenda recebida');
            $table->bigInteger('guia_saida_id')->nullable()->comment('documento-pai do tipo GUIA_SAIDA');
            $table->bigInteger('rececao_compra_id')->nullable()->comment('documento-pai do tipo RECECAO_COMPRA');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_itens_guia_saida_empresa_id ON itens_guia_saida (empresa_id)');
        DB::statement('CREATE INDEX ix_itens_guia_saida_produto_id ON itens_guia_saida (produto_id)');
        DB::statement('CREATE INDEX ix_itens_guia_saida_projeto_id ON itens_guia_saida (projeto_id)');
        DB::statement('CREATE INDEX ix_itens_guia_saida_item_compra_id ON itens_guia_saida (item_compra_id)');
        DB::statement('CREATE INDEX ix_itens_guia_saida_guia_saida_id ON itens_guia_saida (guia_saida_id)');
        DB::statement('CREATE INDEX ix_itens_guia_saida_rececao_compra_id ON itens_guia_saida (rececao_compra_id)');
        DB::statement('ALTER TABLE itens_guia_saida ADD CONSTRAINT ck_itens_guia_saida_documento_origem CHECK ((guia_saida_id IS NOT NULL)::int + (rececao_compra_id IS NOT NULL)::int <= 1)');

        // inventory_sessions (legado) -> sessoes_inventario · 4 linhas reais no backup
        Schema::create('sessoes_inventario', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->bigInteger('armazem_id')->nullable()->comment('legado: warehouse_id');
            $table->date('data')->nullable()->comment('legado: date');
            $table->text('descricao')->nullable()->comment('legado: description');
            $table->string('estado', 20)->nullable()->comment('legado: status · código normalizado ∈ {EM_CONTAGEM, REVISAO, CONCLUIDA, ANULADA}; texto original em estado_original');
            $table->string('estado_original', 100)->nullable()->comment('legado: status · texto exacto do legado');
            $table->string('tipo', 10)->nullable()->comment('legado: type');
            $table->string('aprovado_por', 100)->nullable()->comment('Quem aprovou a regularização');
            $table->timestampTz('aprovado_em')->nullable()->comment('Aprovação');
            $table->string('numero_lan_contabilizacao', 30)->nullable()->comment('Lançamento da regularização (diário SQ)');
            $table->text('motivo_anulacao')->nullable()->comment('Motivo da anulação (o legado apagava a sessão)');
            $table->string('iniciado_por', 100)->nullable()->comment('Quem abriu a contagem');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_sessoes_inventario_empresa_id ON sessoes_inventario (empresa_id)');
        DB::statement('CREATE INDEX ix_sessoes_inventario_armazem_id ON sessoes_inventario (armazem_id)');
        DB::statement('ALTER TABLE sessoes_inventario ADD CONSTRAINT ck_sessoes_inventario_estado CHECK (estado IS NULL OR estado IN (\'EM_CONTAGEM\',\'REVISAO\',\'CONCLUIDA\',\'ANULADA\'))');

        // inventory_session_lines (legado) -> linhas_sessao_inventario · 6 linhas reais no backup
        Schema::create('linhas_sessao_inventario', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('derivada de sessao_inventario_id -> sessoes_inventario');
            $table->bigInteger('sessao_inventario_id')->nullable()->comment('legado: session_id');
            $table->bigInteger('produto_id')->nullable()->comment('legado: product_id');
            $table->decimal('quantidade_sistema', 12, 3)->nullable()->comment('legado: system_quantity · tipos mistos: inteiro=4, decimal=2');
            $table->decimal('quantidade_contada', 12, 3)->nullable()->comment('legado: counted_quantity · tipos mistos: inteiro=4, decimal=2');
            $table->decimal('diferenca', 12, 3)->nullable()->comment('legado: difference · tipo forçado (inferido: numeric(15,2))');
            $table->text('observacoes')->nullable()->comment('legado: notes · sem valores reais: tipo a confirmar no código legado');
            $table->text('justificacao')->nullable()->comment('legado: justification');
            $table->decimal('custo_personalizado', 15, 2)->nullable()->comment('legado: custom_cost');
            $table->decimal('custo_unitario', 18, 6)->nullable()->comment('Custo usado na valorização da diferença');
            $table->decimal('valor_diferenca', 15, 2)->nullable()->comment('Diferença × custo (Kz)');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_linhas_sessao_inventario_empresa_id ON linhas_sessao_inventario (empresa_id)');
        DB::statement('CREATE INDEX ix_linhas_sessao_inventario_sessao_inventario_id ON linhas_sessao_inventario (sessao_inventario_id)');
        DB::statement('CREATE INDEX ix_linhas_sessao_inventario_produto_id ON linhas_sessao_inventario (produto_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('linhas_sessao_inventario');
        Schema::dropIfExists('sessoes_inventario');
        Schema::dropIfExists('itens_guia_saida');
        Schema::dropIfExists('guias_saida');
        Schema::dropIfExists('movimentos_inventario');
        Schema::dropIfExists('stock_armazem');
        Schema::dropIfExists('categorias_produtos');
        Schema::dropIfExists('produtos');
        Schema::dropIfExists('armazens');
    }
};
