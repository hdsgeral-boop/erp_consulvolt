<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo Compras — GERADO por ferramentas/gerador/gerar_esquema.mjs a partir de docs/dicionario/mapa_de_para.json.
 * Não editar à mão: alterar glossario.mjs / esquema_extra.mjs e regenerar.
 * As chaves estrangeiras são criadas em 2026_09_30_900000_criar_chaves_estrangeiras.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        // purchase_requests (legado) -> pedidos_compra · 21 linhas reais no backup
        Schema::create('pedidos_compra', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->string('nome_requerente', 255)->nullable()->comment('legado: requester_name');
            $table->timestampTz('data')->nullable()->comment('legado: date · tipos mistos: string_data=14, string_datahora=7');
            $table->string('estado', 20)->nullable()->comment('legado: status · código normalizado ∈ {PENDENTE, APROVADO, REJEITADO, ADJUDICADO, FECHADO, ANULADO}; texto original em estado_original');
            $table->string('estado_original', 100)->nullable()->comment('legado: status · texto exacto do legado');
            $table->bigInteger('venda_origem_id')->nullable()->comment('legado: source_sale_id');
            $table->bigInteger('projeto_id')->nullable()->comment('legado: project_id');
            $table->text('codigo_projeto')->nullable()->comment('legado: project_code · sem valores reais: tipo a confirmar no código legado');
            $table->text('descricao')->nullable()->comment('legado: description');
            $table->date('data_entrega')->nullable()->comment('legado: delivery_date');
            $table->text('observacoes')->nullable()->comment('legado: notes · sem valores reais: tipo a confirmar no código legado');
            $table->jsonb('deliberacao')->nullable()->comment('legado: deliberacao');
            $table->bigInteger('unidade_negocio_id')->nullable()->comment('legado: business_unit_id');
            $table->bigInteger('centro_custo_id')->nullable()->comment('legado: cost_center_id');
            $table->date('data_prevista')->nullable()->comment('legado: expected_date');
            $table->string('criado_por', 100)->nullable()->comment('legado: criado_por');
            $table->bigInteger('colaborador_requerente_id')->nullable()->comment('legado: requester_employee_id');
            $table->string('numero_pedido', 50)->nullable()->comment('N.º do pedido "PC <série>/<n>" (o legado só mostrava o id)');
            $table->timestampTz('anulado_em')->nullable()->comment('Data/hora da anulação');
            $table->text('motivo_anulacao')->nullable()->comment('Motivo da anulação');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_pedidos_compra_empresa_id ON pedidos_compra (empresa_id)');
        DB::statement('CREATE INDEX ix_pedidos_compra_venda_origem_id ON pedidos_compra (venda_origem_id)');
        DB::statement('CREATE INDEX ix_pedidos_compra_projeto_id ON pedidos_compra (projeto_id)');
        DB::statement('CREATE INDEX ix_pedidos_compra_unidade_negocio_id ON pedidos_compra (unidade_negocio_id)');
        DB::statement('CREATE INDEX ix_pedidos_compra_centro_custo_id ON pedidos_compra (centro_custo_id)');
        DB::statement('CREATE INDEX ix_pedidos_compra_colaborador_requerente_id ON pedidos_compra (colaborador_requerente_id)');
        DB::statement('ALTER TABLE pedidos_compra ADD CONSTRAINT ck_pedidos_compra_estado CHECK (estado IS NULL OR estado IN (\'PENDENTE\',\'APROVADO\',\'REJEITADO\',\'ADJUDICADO\',\'FECHADO\',\'ANULADO\'))');

        // purchase_quotes (legado) -> cotacoes_compra · 23 linhas reais no backup
        Schema::create('cotacoes_compra', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->bigInteger('pedido_compra_id')->nullable()->comment('legado: request_id');
            $table->bigInteger('fornecedor_id')->nullable()->comment('legado: supplier_id');
            $table->string('referencia', 50)->nullable()->comment('legado: reference');
            $table->decimal('montante_total', 15, 2)->nullable()->comment('legado: total_amount · tipos mistos: inteiro=21, decimal=2');
            $table->date('data')->nullable()->comment('legado: date');
            $table->date('data_entrega')->nullable()->comment('legado: delivery_date');
            $table->string('estado', 25)->nullable()->comment('legado: status · código normalizado ∈ {PROPOSTA, PROPOSTA_ADJUDICACAO, ADJUDICADO, RECUSADA, ANULADA}; texto original em estado_original');
            $table->string('estado_original', 100)->nullable()->comment('legado: status · texto exacto do legado');
            $table->bigInteger('unidade_negocio_id')->nullable()->comment('legado: business_unit_id');
            $table->bigInteger('centro_custo_id')->nullable()->comment('legado: cost_center_id');
            $table->string('codigo_moeda', 50)->nullable()->comment('legado: currency');
            $table->decimal('taxa_cambio', 18, 6)->nullable()->comment('legado: exchange_rate');
            $table->bigInteger('taxa_cambio_id')->nullable()->comment('legado: exchange_rate_id');
            $table->boolean('taxa_cambio_manual')->nullable()->comment('legado: exchange_rate_manual');
            $table->decimal('montante_total_moeda', 15, 2)->nullable()->comment('legado: total_amount_currency');
            $table->decimal('total_imposto', 15, 2)->nullable()->comment('legado: total_tax · do código legado js/ui_compras_v2.js:1448');
            $table->decimal('total_com_imposto', 15, 2)->nullable()->comment('legado: total_with_tax · do código legado js/ui_compras_v2.js:1449');
            $table->string('numero_proposta', 50)->nullable()->comment('N.º interno "PP <série>/<n>" (a referência é a do fornecedor)');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_cotacoes_compra_empresa_id ON cotacoes_compra (empresa_id)');
        DB::statement('CREATE INDEX ix_cotacoes_compra_pedido_compra_id ON cotacoes_compra (pedido_compra_id)');
        DB::statement('CREATE INDEX ix_cotacoes_compra_fornecedor_id ON cotacoes_compra (fornecedor_id)');
        DB::statement('CREATE INDEX ix_cotacoes_compra_unidade_negocio_id ON cotacoes_compra (unidade_negocio_id)');
        DB::statement('CREATE INDEX ix_cotacoes_compra_centro_custo_id ON cotacoes_compra (centro_custo_id)');
        DB::statement('CREATE INDEX ix_cotacoes_compra_taxa_cambio_id ON cotacoes_compra (taxa_cambio_id)');
        DB::statement('ALTER TABLE cotacoes_compra ADD CONSTRAINT ck_cotacoes_compra_estado CHECK (estado IS NULL OR estado IN (\'PROPOSTA\',\'PROPOSTA_ADJUDICACAO\',\'ADJUDICADO\',\'RECUSADA\',\'ANULADA\'))');

        // purchase_orders (legado) -> encomendas_compra · 17 linhas reais no backup
        Schema::create('encomendas_compra', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->bigInteger('pedido_compra_id')->nullable()->comment('legado: request_id');
            $table->bigInteger('cotacao_compra_id')->nullable()->comment('legado: quote_id');
            $table->bigInteger('fornecedor_id')->nullable()->comment('legado: supplier_id');
            $table->string('numero_encomenda', 50)->nullable()->comment('legado: order_number');
            $table->timestampTz('data')->nullable()->comment('legado: date · tipos mistos: string_datahora=16, string_data=1');
            $table->string('estado', 21)->nullable()->comment('legado: status · código normalizado ∈ {EM_PROCESSAMENTO, PARCIAL, RECEBIDO, ANULADA}; texto original em estado_original');
            $table->string('estado_original', 100)->nullable()->comment('legado: status · texto exacto do legado');
            $table->boolean('contabilizado')->nullable()->comment('legado: is_posted');
            $table->bigInteger('venda_origem_id')->nullable()->comment('legado: source_sale_id · tipos Dexie: {"undef":2}');
            $table->bigInteger('projeto_id')->nullable()->comment('legado: project_id');
            $table->text('codigo_projeto')->nullable()->comment('legado: project_code · sem valores reais: tipo a confirmar no código legado');
            $table->bigInteger('contrato_fornecedor_id')->nullable()->comment('legado: contract_id');
            $table->bigInteger('unidade_negocio_id')->nullable()->comment('legado: business_unit_id');
            $table->bigInteger('centro_custo_id')->nullable()->comment('legado: cost_center_id');
            $table->string('codigo_moeda', 50)->nullable()->comment('legado: currency');
            $table->decimal('taxa_cambio', 18, 6)->nullable()->comment('legado: exchange_rate');
            $table->bigInteger('taxa_cambio_id')->nullable()->comment('legado: exchange_rate_id');
            $table->boolean('taxa_cambio_manual')->nullable()->comment('legado: exchange_rate_manual');
            $table->decimal('montante_total', 15, 2)->nullable()->comment('legado: total_amount');
            $table->decimal('montante_total_moeda', 15, 2)->nullable()->comment('legado: total_amount_currency');
            $table->decimal('total_imposto', 15, 2)->nullable()->comment('legado: total_tax · do código legado js/ui_compras_v2.js:2156');
            $table->decimal('total_com_imposto', 15, 2)->nullable()->comment('legado: total_with_tax · do código legado js/ui_compras_v2.js:2156');
            $table->date('data_entrega_prevista')->nullable()->comment('Prazo de entrega da proposta adjudicada');
            $table->timestampTz('anulado_em')->nullable()->comment('Data/hora da anulação');
            $table->text('motivo_anulacao')->nullable()->comment('Motivo da anulação');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE UNIQUE INDEX uq_encomendas_compra_empresa_id_numero_encomenda ON encomendas_compra (empresa_id, numero_encomenda)');
        DB::statement('CREATE INDEX ix_encomendas_compra_pedido_compra_id ON encomendas_compra (pedido_compra_id)');
        DB::statement('CREATE INDEX ix_encomendas_compra_cotacao_compra_id ON encomendas_compra (cotacao_compra_id)');
        DB::statement('CREATE INDEX ix_encomendas_compra_fornecedor_id ON encomendas_compra (fornecedor_id)');
        DB::statement('CREATE INDEX ix_encomendas_compra_venda_origem_id ON encomendas_compra (venda_origem_id)');
        DB::statement('CREATE INDEX ix_encomendas_compra_projeto_id ON encomendas_compra (projeto_id)');
        DB::statement('CREATE INDEX ix_encomendas_compra_contrato_fornecedor_id ON encomendas_compra (contrato_fornecedor_id)');
        DB::statement('CREATE INDEX ix_encomendas_compra_unidade_negocio_id ON encomendas_compra (unidade_negocio_id)');
        DB::statement('CREATE INDEX ix_encomendas_compra_centro_custo_id ON encomendas_compra (centro_custo_id)');
        DB::statement('CREATE INDEX ix_encomendas_compra_taxa_cambio_id ON encomendas_compra (taxa_cambio_id)');
        DB::statement('ALTER TABLE encomendas_compra ADD CONSTRAINT ck_encomendas_compra_estado CHECK (estado IS NULL OR estado IN (\'EM_PROCESSAMENTO\',\'PARCIAL\',\'RECEBIDO\',\'ANULADA\'))');

        // purchase_deliveries (legado) -> rececoes_compra · 22 linhas reais no backup
        Schema::create('rececoes_compra', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->bigInteger('encomenda_compra_id')->nullable()->comment('legado: order_id');
            $table->string('numero_entrega', 50)->nullable()->comment('legado: delivery_number');
            $table->date('data')->nullable()->comment('legado: date');
            $table->string('estado', 20)->nullable()->comment('legado: status · código normalizado ∈ {RECEBIDO, VALIDADO, ANULADO}; texto original em estado_original');
            $table->string('estado_original', 100)->nullable()->comment('legado: status · texto exacto do legado');
            $table->boolean('contabilizado')->nullable()->comment('legado: is_posted');
            $table->boolean('validado')->nullable()->comment('legado: is_validated');
            $table->bigInteger('armazem_id')->nullable()->comment('legado: warehouse_id');
            $table->bigInteger('unidade_negocio_id')->nullable()->comment('legado: business_unit_id');
            $table->bigInteger('centro_custo_id')->nullable()->comment('legado: cost_center_id');
            $table->string('codigo_moeda', 50)->nullable()->comment('legado: currency');
            $table->decimal('taxa_cambio', 18, 6)->nullable()->comment('legado: exchange_rate');
            $table->bigInteger('taxa_cambio_id')->nullable()->comment('legado: exchange_rate_id');
            $table->boolean('taxa_cambio_manual')->nullable()->comment('legado: exchange_rate_manual');
            $table->decimal('valor_total_kz', 15, 2)->nullable()->comment('legado: total_value_kz · tipos mistos: inteiro=2, decimal=1');
            $table->string('numero_rececao', 50)->nullable()->comment('N.º interno "RCP <série>/<n>" (numero_entrega é a guia do fornecedor)');
            $table->string('numero_lan_contabilizacao', 30)->nullable()->comment('N.º do lançamento da validação (entrada em armazém)');
            $table->timestampTz('validado_em')->nullable()->comment('Data/hora da validação no armazém');
            $table->string('validado_por', 100)->nullable()->comment('Utilizador que validou');
            $table->timestampTz('anulado_em')->nullable()->comment('Data/hora da anulação');
            $table->text('motivo_anulacao')->nullable()->comment('Motivo da anulação');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_rececoes_compra_empresa_id ON rececoes_compra (empresa_id)');
        DB::statement('CREATE INDEX ix_rececoes_compra_encomenda_compra_id ON rececoes_compra (encomenda_compra_id)');
        DB::statement('CREATE INDEX ix_rececoes_compra_armazem_id ON rececoes_compra (armazem_id)');
        DB::statement('CREATE INDEX ix_rececoes_compra_unidade_negocio_id ON rececoes_compra (unidade_negocio_id)');
        DB::statement('CREATE INDEX ix_rececoes_compra_centro_custo_id ON rececoes_compra (centro_custo_id)');
        DB::statement('CREATE INDEX ix_rececoes_compra_taxa_cambio_id ON rececoes_compra (taxa_cambio_id)');
        DB::statement('ALTER TABLE rececoes_compra ADD CONSTRAINT ck_rececoes_compra_estado CHECK (estado IS NULL OR estado IN (\'RECEBIDO\',\'VALIDADO\',\'ANULADO\'))');

        // purchase_invoices (legado) -> faturas_compra · 25 linhas reais no backup
        Schema::create('faturas_compra', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->bigInteger('encomenda_compra_id')->nullable()->comment('legado: order_id');
            $table->bigInteger('fornecedor_id')->nullable()->comment('legado: supplier_id');
            $table->string('numero_fatura', 50)->nullable()->comment('legado: invoice_number');
            $table->date('data')->nullable()->comment('legado: date');
            $table->decimal('montante_total', 15, 2)->nullable()->comment('legado: total_amount · tipos mistos: inteiro=20, decimal=5');
            $table->decimal('total_imposto', 15, 2)->nullable()->comment('legado: total_tax · tipos mistos: inteiro=8, decimal=13');
            $table->string('estado', 20)->nullable()->comment('legado: status · código normalizado ∈ {PENDENTE, PARCIAL, PAGO, ANULADA}; texto original em estado_original');
            $table->string('estado_original', 100)->nullable()->comment('legado: status · texto exacto do legado');
            $table->boolean('contabilizado')->nullable()->comment('legado: is_posted · tipos mistos: boolean=24, inteiro=1');
            $table->jsonb('itens')->nullable()->comment('legado: items');
            $table->bigInteger('projeto_id')->nullable()->comment('legado: project_id');
            $table->text('codigo_projeto')->nullable()->comment('legado: project_code · sem valores reais: tipo a confirmar no código legado');
            $table->bigInteger('unidade_negocio_id')->nullable()->comment('legado: business_unit_id');
            $table->bigInteger('centro_custo_id')->nullable()->comment('legado: cost_center_id');
            $table->string('codigo_moeda', 50)->nullable()->comment('legado: currency');
            $table->decimal('taxa_cambio', 18, 6)->nullable()->comment('legado: exchange_rate');
            $table->bigInteger('taxa_cambio_id')->nullable()->comment('legado: exchange_rate_id');
            $table->boolean('taxa_cambio_manual')->nullable()->comment('legado: exchange_rate_manual');
            $table->decimal('montante_total_moeda', 15, 2)->nullable()->comment('legado: total_amount_currency');
            $table->decimal('total_imposto_moeda', 15, 2)->nullable()->comment('legado: total_tax_currency');
            $table->date('data_vencimento')->nullable()->comment('Data de vencimento');
            $table->string('numero_lan_contabilizacao', 30)->nullable()->comment('N.º do lançamento contabilístico da factura');
            $table->timestampTz('anulado_em')->nullable()->comment('Data/hora da anulação');
            $table->text('motivo_anulacao')->nullable()->comment('Motivo da anulação');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_faturas_compra_empresa_id ON faturas_compra (empresa_id)');
        DB::statement('CREATE INDEX ix_faturas_compra_encomenda_compra_id ON faturas_compra (encomenda_compra_id)');
        DB::statement('CREATE INDEX ix_faturas_compra_fornecedor_id ON faturas_compra (fornecedor_id)');
        DB::statement('CREATE INDEX ix_faturas_compra_projeto_id ON faturas_compra (projeto_id)');
        DB::statement('CREATE INDEX ix_faturas_compra_unidade_negocio_id ON faturas_compra (unidade_negocio_id)');
        DB::statement('CREATE INDEX ix_faturas_compra_centro_custo_id ON faturas_compra (centro_custo_id)');
        DB::statement('CREATE INDEX ix_faturas_compra_taxa_cambio_id ON faturas_compra (taxa_cambio_id)');
        DB::statement('CREATE INDEX ix_faturas_compra_empresa_id_fornecedor_id_numero_fatura ON faturas_compra (empresa_id, fornecedor_id, numero_fatura)');
        DB::statement('ALTER TABLE faturas_compra ADD CONSTRAINT ck_faturas_compra_estado CHECK (estado IS NULL OR estado IN (\'PENDENTE\',\'PARCIAL\',\'PAGO\',\'ANULADA\'))');

        // purchase_items (legado) -> itens_compra · 90 linhas reais no backup
        Schema::create('itens_compra', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->string('tipo_documento_origem', 20)->nullable()->comment('legado: parent_type · código normalizado ∈ {PEDIDO, COTACAO, ENCOMENDA, FATURA}; texto original em tipo_documento_origem_original');
            $table->string('tipo_documento_origem_original', 100)->nullable()->comment('legado: parent_type · texto exacto do legado');
            $table->bigInteger('produto_id')->nullable()->comment('legado: product_id');
            $table->decimal('quantidade', 12, 3)->nullable()->comment('legado: quantity');
            $table->decimal('preco_unitario', 15, 2)->nullable()->comment('legado: unit_price · tipos mistos: inteiro=83, decimal=4');
            $table->bigInteger('projeto_id')->nullable()->comment('legado: project_id');
            $table->text('codigo_projeto')->nullable()->comment('legado: project_code · sem valores reais: tipo a confirmar no código legado');
            $table->decimal('quantidade_recebida', 12, 3)->nullable()->comment('legado: received_qty');
            $table->decimal('quantidade_faturada', 12, 3)->nullable()->comment('legado: invoiced_qty');
            $table->bigInteger('encomenda_compra_id')->nullable()->comment('legado: order_id · tipo_documento_origem = ENCOMENDA');
            $table->text('descricao')->nullable()->comment('legado: description');
            $table->decimal('total', 15, 2)->nullable()->comment('legado: total');
            $table->bigInteger('tarefa_projeto_id')->nullable()->comment('legado: task_id');
            $table->decimal('preco_unitario_moeda', 15, 2)->nullable()->comment('legado: unit_price_currency');
            $table->decimal('total_moeda', 15, 2)->nullable()->comment('legado: total_currency');
            $table->decimal('total_kz', 15, 2)->nullable()->comment('legado: total_kz · tipos mistos: inteiro=2, decimal=2');
            $table->decimal('cambial_recebido_por_faturar_qtd', 12, 3)->nullable()->comment('legado: fx_rec_por_faturar_qty');
            $table->decimal('cambial_recebido_por_faturar_kz', 15, 2)->nullable()->comment('legado: fx_rec_por_faturar_kz');
            $table->decimal('cambial_faturado_por_receber_qtd', 12, 3)->nullable()->comment('legado: fx_fat_por_receber_qty');
            $table->decimal('cambial_faturado_por_receber_kz', 15, 2)->nullable()->comment('legado: fx_fat_por_receber_kz');
            $table->decimal('taxa_imposto', 9, 4)->nullable()->comment('legado: tax_rate · do código legado js/ui_compras_v2.js:1243');
            $table->bigInteger('item_encomenda_id')->nullable()->comment('Linha da encomenda que esta linha de factura factura');
            $table->decimal('valor_recebido_kz', 15, 2)->nullable()->comment('Encomenda: valor em Kz acumulado das quantidades recebidas');
            $table->decimal('valor_transitoria_kz', 15, 2)->nullable()->comment('Factura: valor debitado na conta transitória de compras (328)');
            $table->decimal('imposto_kz', 15, 2)->nullable()->comment('IVA da linha em Kz (o legado guardava tax_kz nas linhas embutidas da factura)');
            $table->decimal('imposto_moeda', 15, 2)->nullable()->comment('IVA da linha na moeda do documento');
            $table->bigInteger('pedido_compra_id')->nullable()->comment('tipo_documento_origem = PEDIDO');
            $table->bigInteger('cotacao_compra_id')->nullable()->comment('tipo_documento_origem = COTACAO');
            $table->bigInteger('fatura_compra_id')->nullable()->comment('tipo_documento_origem = FATURA');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_itens_compra_empresa_id ON itens_compra (empresa_id)');
        DB::statement('CREATE INDEX ix_itens_compra_produto_id ON itens_compra (produto_id)');
        DB::statement('CREATE INDEX ix_itens_compra_projeto_id ON itens_compra (projeto_id)');
        DB::statement('CREATE INDEX ix_itens_compra_encomenda_compra_id ON itens_compra (encomenda_compra_id)');
        DB::statement('CREATE INDEX ix_itens_compra_tarefa_projeto_id ON itens_compra (tarefa_projeto_id)');
        DB::statement('CREATE INDEX ix_itens_compra_item_encomenda_id ON itens_compra (item_encomenda_id)');
        DB::statement('CREATE INDEX ix_itens_compra_pedido_compra_id ON itens_compra (pedido_compra_id)');
        DB::statement('CREATE INDEX ix_itens_compra_cotacao_compra_id ON itens_compra (cotacao_compra_id)');
        DB::statement('CREATE INDEX ix_itens_compra_fatura_compra_id ON itens_compra (fatura_compra_id)');
        DB::statement('ALTER TABLE itens_compra ADD CONSTRAINT ck_itens_compra_documento_origem CHECK ((pedido_compra_id IS NOT NULL)::int + (cotacao_compra_id IS NOT NULL)::int + (encomenda_compra_id IS NOT NULL)::int + (fatura_compra_id IS NOT NULL)::int <= 1)');
        DB::statement('ALTER TABLE itens_compra ADD CONSTRAINT ck_itens_compra_tipo_documento_origem CHECK (tipo_documento_origem IS NULL OR tipo_documento_origem IN (\'PEDIDO\',\'COTACAO\',\'ENCOMENDA\',\'FATURA\'))');

        // purchase_catalog (legado) -> catalogo_fornecedores · 70 linhas reais no backup · eliminação lógica
        Schema::create('catalogo_fornecedores', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->bigInteger('produto_id')->nullable()->comment('legado: product_id');
            $table->string('codigo', 50)->nullable()->comment('legado: code');
            $table->string('nome', 255)->nullable()->comment('legado: name');
            $table->decimal('preco_unitario', 15, 2)->nullable()->comment('legado: unit_price · tipos mistos: inteiro=69, decimal=1');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
            $table->timestampTz('eliminado_em')->nullable();
        });
        DB::statement('CREATE INDEX ix_catalogo_fornecedores_empresa_id ON catalogo_fornecedores (empresa_id)');
        DB::statement('CREATE INDEX ix_catalogo_fornecedores_produto_id ON catalogo_fornecedores (produto_id)');

        // purchase_contracts (legado) -> contratos_fornecedores · 1 linhas reais no backup
        Schema::create('contratos_fornecedores', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->bigInteger('fornecedor_id')->nullable()->comment('legado: supplier_id');
            $table->bigInteger('encomenda_compra_id')->nullable()->comment('legado: order_id');
            $table->string('referencia', 50)->nullable()->comment('legado: reference');
            $table->text('descricao')->nullable()->comment('legado: description');
            $table->date('data_inicio')->nullable()->comment('legado: start_date');
            $table->date('data_fim')->nullable()->comment('legado: end_date');
            $table->decimal('valor_total', 15, 2)->nullable()->comment('legado: total_value');
            $table->string('estado', 20)->nullable()->comment('legado: status · código normalizado ∈ {ATIVO, EXPIRADO, CANCELADO}; texto original em estado_original');
            $table->string('estado_original', 100)->nullable()->comment('legado: status · texto exacto do legado');
            $table->timestampTz('cancelado_em')->nullable()->comment('Data/hora do cancelamento');
            $table->text('motivo_cancelamento')->nullable()->comment('Motivo do cancelamento');
            $table->text('encomendas_ids_legado')->nullable()->comment('legado: order_ids · lista de ids do legado; normalizada em contratos_fornecedores_encomendas');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_contratos_fornecedores_empresa_id ON contratos_fornecedores (empresa_id)');
        DB::statement('CREATE INDEX ix_contratos_fornecedores_fornecedor_id ON contratos_fornecedores (fornecedor_id)');
        DB::statement('CREATE INDEX ix_contratos_fornecedores_encomenda_compra_id ON contratos_fornecedores (encomenda_compra_id)');
        DB::statement('ALTER TABLE contratos_fornecedores ADD CONSTRAINT ck_contratos_fornecedores_estado CHECK (estado IS NULL OR estado IN (\'ATIVO\',\'EXPIRADO\',\'CANCELADO\'))');

        // purchase_contract_milestones (legado) -> marcos_contrato_fornecedor · 0 linhas reais no backup
        Schema::create('marcos_contrato_fornecedor', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('tenant (derivado no ETL)');
            $table->bigInteger('contrato_fornecedor_id')->nullable()->comment('legado: contract_id · do código legado js/ui_compras_v2.js:4708');
            $table->string('titulo', 255)->nullable()->comment('legado: title · do código legado js/ui_compras_v2.js:4708');
            $table->date('data_prevista')->nullable()->comment('legado: expected_date · do código legado js/ui_compras_v2.js:4708');
            $table->decimal('montante', 15, 2)->nullable()->comment('legado: amount · do código legado js/ui_compras_v2.js:4708');
            $table->string('estado', 255)->nullable()->comment('legado: status · do código legado js/ui_compras_v2.js:4708');
            $table->bigInteger('fatura_compra_id')->nullable()->comment('Factura do fornecedor que factura o marco');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_marcos_contrato_fornecedor_empresa_id ON marcos_contrato_fornecedor (empresa_id)');
        DB::statement('CREATE INDEX ix_marcos_contrato_fornecedor_contrato_fornecedor_id ON marcos_contrato_fornecedor (contrato_fornecedor_id)');
        DB::statement('CREATE INDEX ix_marcos_contrato_fornecedor_fatura_compra_id ON marcos_contrato_fornecedor (fatura_compra_id)');

        // pa_settings (legado) -> configuracoes_deliberacao_compras · 2 linhas reais no backup
        Schema::create('configuracoes_deliberacao_compras', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: pa_company_id');
            $table->jsonb('niveis')->nullable()->comment('legado: niveis');
            $table->string('atualizado_por', 100)->nullable()->comment('legado: actualizado_por');
            $table->timestampTz('atualizado_em')->nullable()->useCurrent()->comment('legado: actualizado_em');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE UNIQUE INDEX uq_configuracoes_deliberacao_compras_empresa_id ON configuracoes_deliberacao_compras (empresa_id)');

        // configuracoes_contabeis_compras (tabela nova) · 0 linhas reais no backup
        Schema::create('configuracoes_contabeis_compras', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('tenant (derivado no ETL)');
            $table->string('chave', 150)->nullable()->comment('Chave da conta (ver ServicoConfigCompras::CHAVES)');
            $table->string('codigo_conta', 20)->nullable()->comment('Conta do plano');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE UNIQUE INDEX uq_configuracoes_contabeis_compras_empresa_id_chave ON configuracoes_contabeis_compras (empresa_id, chave)');

        // contratos_fornecedores_encomendas (pivô)
        Schema::create('contratos_fornecedores_encomendas', function (Blueprint $table) {
            $table->bigInteger('empresa_id');
            $table->bigInteger('contrato_fornecedor_id');
            $table->bigInteger('encomenda_compra_id');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->primary(['contrato_fornecedor_id', 'encomenda_compra_id']);
        });
        DB::statement('CREATE UNIQUE INDEX uq_contratos_fornecedores_encomendas_encomenda_compra_id ON contratos_fornecedores_encomendas (encomenda_compra_id)');
        DB::statement('CREATE INDEX ix_contratos_fornecedores_encomendas_empresa_id ON contratos_fornecedores_encomendas (empresa_id)');
        DB::statement('CREATE INDEX ix_contratos_fornecedores_encomendas_encomenda_compra_id ON contratos_fornecedores_encomendas (encomenda_compra_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('contratos_fornecedores_encomendas');
        Schema::dropIfExists('configuracoes_contabeis_compras');
        Schema::dropIfExists('configuracoes_deliberacao_compras');
        Schema::dropIfExists('marcos_contrato_fornecedor');
        Schema::dropIfExists('contratos_fornecedores');
        Schema::dropIfExists('catalogo_fornecedores');
        Schema::dropIfExists('itens_compra');
        Schema::dropIfExists('faturas_compra');
        Schema::dropIfExists('rececoes_compra');
        Schema::dropIfExists('encomendas_compra');
        Schema::dropIfExists('cotacoes_compra');
        Schema::dropIfExists('pedidos_compra');
    }
};
