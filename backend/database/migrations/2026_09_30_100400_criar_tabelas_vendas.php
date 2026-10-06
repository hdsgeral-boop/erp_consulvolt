<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo Vendas — GERADO por ferramentas/gerador/gerar_esquema.mjs a partir de docs/dicionario/mapa_de_para.json.
 * Não editar à mão: alterar glossario.mjs / esquema_extra.mjs e regenerar.
 * As chaves estrangeiras são criadas em 2026_09_30_900000_criar_chaves_estrangeiras.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        // sales (legado) -> vendas · 102 linhas reais no backup
        Schema::create('vendas', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->bigInteger('cliente_id')->nullable()->comment('legado: customer_id');
            $table->string('tipo_documento', 20)->nullable()->comment('legado: doc_type · código normalizado ∈ {FT, FR, NC, ND, PF, OR, NE, GR, GD}; texto original em tipo_documento_original');
            $table->string('tipo_documento_original', 100)->nullable()->comment('legado: doc_type · texto exacto do legado');
            $table->string('numero_documento', 50)->nullable()->comment('legado: doc_number');
            $table->timestampTz('data_emissao')->nullable()->comment('legado: date · tipos mistos: string_datahora=47, string_data=55');
            $table->decimal('total_liquido', 15, 2)->nullable()->comment('legado: total_net · tipos mistos: inteiro=57, decimal=43');
            $table->decimal('total_imposto', 15, 2)->nullable()->comment('legado: total_tax · tipos mistos: inteiro=29, decimal=73');
            $table->decimal('total_bruto', 15, 2)->nullable()->comment('legado: total_gross · tipos mistos: inteiro=70, decimal=32');
            $table->date('data_entrega')->nullable()->comment('legado: delivery_date · sem valores reais: tipo a confirmar no código legado; tipo forçado (inferido: text)');
            $table->decimal('valor_pago', 15, 2)->nullable()->comment('legado: paid_amount');
            $table->decimal('valor_pendente', 15, 2)->nullable()->comment('legado: pending_amount · tipos mistos: inteiro=32, decimal=30');
            $table->boolean('contabilizado')->nullable()->comment('legado: is_posted · tipos mistos: boolean=70, inteiro=1');
            $table->string('estado', 20)->nullable()->comment('legado: status · código normalizado ∈ {PENDENTE, PARCIAL, PAGO, CONCLUIDO, ANULADO}; texto original em estado_original');
            $table->string('estado_original', 100)->nullable()->comment('legado: status · texto exacto do legado');
            $table->bigInteger('projeto_id')->nullable()->comment('legado: project_id');
            $table->text('codigo_projeto')->nullable()->comment('legado: project_code · sem valores reais: tipo a confirmar no código legado');
            $table->text('local_entrega')->nullable()->comment('legado: delivery_location · sem valores reais: tipo a confirmar no código legado');
            $table->text('observacoes')->nullable()->comment('legado: notes');
            $table->jsonb('contas_pagamento')->nullable()->comment('legado: payment_accounts');
            $table->text('condicoes_pagamento')->nullable()->comment('legado: payment_terms');
            $table->boolean('ocultar_meios_pagamento')->nullable()->comment('legado: hide_payment_methods');
            $table->bigInteger('unidade_negocio_id')->nullable()->comment('legado: business_unit_id');
            $table->bigInteger('centro_custo_id')->nullable()->comment('legado: cost_center_id');
            $table->decimal('desconto', 15, 2)->nullable()->comment('legado: discount');
            $table->bigInteger('sessao_pos_id')->nullable()->comment('legado: pos_session_id');
            $table->string('meio_pagamento', 20)->nullable()->comment('legado: payment_method · código normalizado ∈ {NUMERARIO, TPA, TRANSFERENCIA, CONTA_CORRENTE, MISTO}; texto original em meio_pagamento_original');
            $table->string('meio_pagamento_original', 100)->nullable()->comment('legado: payment_method · texto exacto do legado');
            $table->string('nome_tabela', 255)->nullable()->comment('legado: table_name');
            $table->bigInteger('terceiro_id')->nullable()->comment('legado: third_party_id · tipos Dexie: {"undef":2}');
            $table->date('data_vencimento')->nullable()->comment('legado: due_date');
            $table->decimal('subtotal', 15, 2)->nullable()->comment('legado: subtotal');
            $table->decimal('total_desconto', 15, 2)->nullable()->comment('legado: total_discount');
            $table->decimal('montante_total', 15, 2)->nullable()->comment('legado: total_amount');
            $table->jsonb('linhas')->nullable()->comment('legado: lines');
            $table->string('codigo_moeda', 50)->nullable()->comment('legado: currency');
            $table->decimal('taxa_cambio', 18, 6)->nullable()->comment('legado: exchange_rate');
            $table->bigInteger('taxa_cambio_id')->nullable()->comment('legado: exchange_rate_id');
            $table->boolean('taxa_cambio_manual')->nullable()->comment('legado: exchange_rate_manual');
            $table->decimal('total_liquido_moeda', 15, 2)->nullable()->comment('legado: total_net_currency');
            $table->decimal('total_imposto_moeda', 15, 2)->nullable()->comment('legado: total_tax_currency · tipos mistos: inteiro=5, decimal=2');
            $table->decimal('total_bruto_moeda', 15, 2)->nullable()->comment('legado: total_gross_currency · tipos mistos: inteiro=5, decimal=2');
            $table->bigInteger('terminal_pos_id')->nullable()->comment('legado: pos_terminal_id');
            $table->string('codigo_terminal_pos', 50)->nullable()->comment('legado: pos_terminal_code');
            $table->jsonb('pos_pagamentos')->nullable()->comment('legado: pos_payments');
            $table->decimal('pos_troco', 15, 2)->nullable()->comment('legado: pos_change');
            $table->string('pos_operador', 100)->nullable()->comment('legado: pos_operator');
            $table->jsonb('pos_lans_contabilizacao')->nullable()->comment('legado: pos_posting_lans');
            $table->bigInteger('pedido_lavandaria_id')->nullable()->comment('legado: lav_order_id');
            $table->string('numero_pedido_lavandaria', 50)->nullable()->comment('legado: lav_order_number');
            $table->decimal('montante_pago', 15, 2)->nullable()->comment('legado: amount_paid');
            $table->date('valido_ate')->nullable()->comment('legado: valid_until');
            $table->integer('dias_validade')->nullable()->comment('legado: validity_days');
            $table->string('modo_pagamento', 20)->nullable()->comment('legado: payment_mode · código normalizado ∈ {PRONTO, PRAZO, MARCOS}; texto original em modo_pagamento_original');
            $table->string('modo_pagamento_original', 100)->nullable()->comment('legado: payment_mode · texto exacto do legado');
            $table->jsonb('plano_pagamentos')->nullable()->comment('legado: payment_schedule');
            $table->bigInteger('oportunidade_crm_id')->nullable()->comment('legado: crm_opportunity_id');
            $table->jsonb('fe_documento')->nullable()->comment('legado: fe_documento · do código legado js/facturacao_agt.js:447');
            $table->jsonb('fe_erros')->nullable()->comment('legado: fe_erros · do código legado js/facturacao_agt.js:447');
            $table->jsonb('fe_avisos')->nullable()->comment('legado: fe_avisos · do código legado js/facturacao_agt.js:447');
            $table->string('fe_estado', 20)->nullable()->comment('legado: fe_estado · do código legado js/facturacao_agt.js:448');
            $table->timestampTz('fe_validado_em')->nullable()->comment('legado: fe_validado_em · do código legado js/facturacao_agt.js:448');
            $table->timestampTz('fe_selado_em')->nullable()->comment('legado: fe_selado_em · do código legado js/facturacao_agt.js:451');
            $table->jsonb('fe_envio')->nullable()->comment('legado: fe_envio · do código legado js/facturacao_agt_envio.js:82');
            $table->boolean('fe_regime')->nullable()->comment('legado: fe_regime · do código legado js/facturacao_agt.js:210');
            $table->string('fe_tipo', 5)->nullable()->comment('legado: fe_tipo · do código legado js/facturacao_agt.js:210');
            $table->bigInteger('serie_faturacao_eletronica_id')->nullable()->comment('legado: fe_serie_id · do código legado js/facturacao_agt.js:210');
            $table->string('fe_serie', 50)->nullable()->comment('legado: fe_serie · do código legado js/facturacao_agt.js:210');
            $table->integer('fe_numero')->nullable()->comment('legado: fe_numero · do código legado js/facturacao_agt.js:210');
            $table->string('fe_estabelecimento', 20)->nullable()->comment('legado: fe_estabelecimento · do código legado js/facturacao_agt.js:210');
            $table->timestampTz('fe_data_entrada_sistema')->nullable()->comment('legado: fe_system_entry · do código legado js/facturacao_agt.js:210');
            $table->text('motivo_nota_credito')->nullable()->comment('legado: nc_motivo · do código legado js/ui_sales.js:1856');
            $table->string('sessao_pos_legado_codigo', 50)->nullable()->comment('Código \'POS_SESS_<epoch>\' do legado (sem FK)');
            $table->string('numero_lan_contabilizacao', 30)->nullable()->comment('N.º do lançamento contabilístico gerado pela contabilização do documento');
            $table->text('saft_hash')->nullable()->comment('Assinatura RSA-SHA1 (base64) de "data;data entrada;n.º;total bruto;hash anterior"');
            $table->string('saft_hash_controlo', 10)->nullable()->comment('Versão da chave usada na assinatura (HashControl); 0 = não assinado');
            $table->bigInteger('armazem_id')->nullable()->comment('Armazém de onde sai (ou para onde volta) a mercadoria');
            $table->boolean('devolucao_mercadoria')->nullable()->comment('NC: a mercadoria volta ao stock (as NC de correcção de preço não mexem no stock)');
            $table->decimal('arredondamento_agt', 15, 2)->nullable()->comment('POS: arredondamento AGT (valor cobrado − desconto − total do documento), separado do desconto');
            $table->text('estadias_hotel_ids_legado')->nullable()->comment('legado: hotel_stay_ids · lista de ids do legado; normalizada em vendas_estadias_hotel');
            $table->text('documentos_relacionados_legado')->nullable()->comment('legado: related_doc_id · lista de ids do legado; normalizada em vendas_documentos_relacionados');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE UNIQUE INDEX uq_vendas_empresa_id_tipo_documento_numero_documento ON vendas (empresa_id, tipo_documento, numero_documento) WHERE tipo_documento IN (\'FT\',\'FR\',\'NC\',\'ND\')');
        DB::statement('CREATE UNIQUE INDEX uq_vendas_empresa_id_tipo_documento_numero_documento_2 ON vendas (empresa_id, tipo_documento, numero_documento) WHERE tipo_documento IN (\'NE\',\'GR\',\'GD\')');
        DB::statement('CREATE UNIQUE INDEX uq_vendas_empresa_id_tipo_documento_numero_documento_3 ON vendas (empresa_id, tipo_documento, numero_documento) WHERE tipo_documento IN (\'OR\',\'PF\') AND serie_faturacao_eletronica_id IS NOT NULL');
        DB::statement('CREATE INDEX ix_vendas_empresa_id ON vendas (empresa_id)');
        DB::statement('CREATE INDEX ix_vendas_cliente_id ON vendas (cliente_id)');
        DB::statement('CREATE INDEX ix_vendas_projeto_id ON vendas (projeto_id)');
        DB::statement('CREATE INDEX ix_vendas_unidade_negocio_id ON vendas (unidade_negocio_id)');
        DB::statement('CREATE INDEX ix_vendas_centro_custo_id ON vendas (centro_custo_id)');
        DB::statement('CREATE INDEX ix_vendas_sessao_pos_id ON vendas (sessao_pos_id)');
        DB::statement('CREATE INDEX ix_vendas_terceiro_id ON vendas (terceiro_id)');
        DB::statement('CREATE INDEX ix_vendas_taxa_cambio_id ON vendas (taxa_cambio_id)');
        DB::statement('CREATE INDEX ix_vendas_terminal_pos_id ON vendas (terminal_pos_id)');
        DB::statement('CREATE INDEX ix_vendas_pedido_lavandaria_id ON vendas (pedido_lavandaria_id)');
        DB::statement('CREATE INDEX ix_vendas_oportunidade_crm_id ON vendas (oportunidade_crm_id)');
        DB::statement('CREATE INDEX ix_vendas_serie_faturacao_eletronica_id ON vendas (serie_faturacao_eletronica_id)');
        DB::statement('CREATE INDEX ix_vendas_armazem_id ON vendas (armazem_id)');
        DB::statement('CREATE INDEX ix_vendas_empresa_id_data_emissao ON vendas (empresa_id, data_emissao)');
        DB::statement('CREATE INDEX ix_vendas_empresa_id_estado ON vendas (empresa_id, estado)');
        DB::statement('ALTER TABLE vendas ADD CONSTRAINT ck_vendas_tipo_documento CHECK (tipo_documento IS NULL OR tipo_documento IN (\'FT\',\'FR\',\'NC\',\'ND\',\'PF\',\'OR\',\'NE\',\'GR\',\'GD\'))');
        DB::statement('ALTER TABLE vendas ADD CONSTRAINT ck_vendas_estado CHECK (estado IS NULL OR estado IN (\'PENDENTE\',\'PARCIAL\',\'PAGO\',\'CONCLUIDO\',\'ANULADO\'))');
        DB::statement('ALTER TABLE vendas ADD CONSTRAINT ck_vendas_meio_pagamento CHECK (meio_pagamento IS NULL OR meio_pagamento IN (\'NUMERARIO\',\'TPA\',\'TRANSFERENCIA\',\'CONTA_CORRENTE\',\'MISTO\'))');
        DB::statement('ALTER TABLE vendas ADD CONSTRAINT ck_vendas_modo_pagamento CHECK (modo_pagamento IS NULL OR modo_pagamento IN (\'PRONTO\',\'PRAZO\',\'MARCOS\'))');

        // sale_items (legado) -> itens_venda · 95 linhas reais no backup
        Schema::create('itens_venda', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('derivada de venda_id -> vendas');
            $table->bigInteger('produto_id')->nullable()->comment('legado: product_id');
            $table->decimal('quantidade', 12, 3)->nullable()->comment('legado: quantity · tipos mistos: inteiro=93, decimal=2');
            $table->decimal('preco_unitario', 15, 2)->nullable()->comment('legado: unit_price · tipos mistos: inteiro=84, decimal=11');
            $table->decimal('taxa_imposto', 9, 4)->nullable()->comment('legado: tax_rate');
            $table->decimal('total', 15, 2)->nullable()->comment('legado: total · tipos mistos: inteiro=82, decimal=13');
            $table->bigInteger('venda_id')->nullable()->comment('legado: sale_id');
            $table->bigInteger('projeto_id')->nullable()->comment('legado: project_id');
            $table->text('codigo_projeto')->nullable()->comment('legado: project_code · sem valores reais: tipo a confirmar no código legado');
            $table->text('observacoes')->nullable()->comment('legado: notes · tipo forçado (inferido: integer)');
            $table->decimal('quantidade_faturada', 12, 3)->nullable()->comment('legado: billed_qty');
            $table->bigInteger('pedido_compra_id')->nullable()->comment('legado: purchase_request_id');
            $table->decimal('quantidade_entregue', 12, 3)->nullable()->comment('legado: delivered_qty');
            $table->text('descricao')->nullable()->comment('legado: description · tipo forçado (inferido: text)');
            $table->decimal('percentagem_desconto', 9, 4)->nullable()->comment('legado: discount_pct');
            $table->decimal('total_linha', 15, 2)->nullable()->comment('legado: line_total');
            $table->string('codigo_conta', 20)->nullable()->comment('legado: account_code');
            $table->decimal('preco_unitario_moeda', 15, 2)->nullable()->comment('legado: unit_price_currency · tipos mistos: inteiro=5, decimal=2');
            $table->decimal('total_moeda', 15, 2)->nullable()->comment('legado: total_currency · tipos mistos: inteiro=5, decimal=2');
            $table->decimal('imposto_moeda', 15, 2)->nullable()->comment('legado: tax_currency · tipos mistos: inteiro=5, decimal=2');
            $table->boolean('fe_selado')->nullable()->comment('legado: fe_selado · do código legado js/facturacao_agt.js:459');
            $table->decimal('custo_unitario_kz', 18, 6)->nullable()->comment('Custo médio da saída/entrada de stock da linha (base do CMV)');
            $table->decimal('quantidade_stock', 12, 3)->nullable()->comment('Quantidade que movimentou stock (0 numa FT gerada de uma GR)');
            $table->decimal('quantidade_devolvida', 12, 3)->nullable()->comment('GR: quantidade já devolvida por guias de devolução');
            $table->decimal('acerto_cmv_kz', 15, 2)->nullable()->comment('GD/NC com devolução sobre stock negativo: acerto do CMV (custo da devolução − custo médio das unidades a descoberto)');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_itens_venda_empresa_id ON itens_venda (empresa_id)');
        DB::statement('CREATE INDEX ix_itens_venda_produto_id ON itens_venda (produto_id)');
        DB::statement('CREATE INDEX ix_itens_venda_venda_id ON itens_venda (venda_id)');
        DB::statement('CREATE INDEX ix_itens_venda_projeto_id ON itens_venda (projeto_id)');
        DB::statement('CREATE INDEX ix_itens_venda_pedido_compra_id ON itens_venda (pedido_compra_id)');

        // receipts (legado) -> recibos_venda · 2 linhas reais no backup
        Schema::create('recibos_venda', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->bigInteger('cliente_id')->nullable()->comment('legado: customer_id');
            $table->bigInteger('unidade_negocio_id')->nullable()->comment('legado: business_unit_id');
            $table->bigInteger('centro_custo_id')->nullable()->comment('legado: cost_center_id');
            $table->string('numero_recibo', 50)->nullable()->comment('legado: receipt_number');
            $table->date('data')->nullable()->comment('legado: date');
            $table->decimal('montante_total', 15, 2)->nullable()->comment('legado: total_amount');
            $table->string('meio_pagamento', 20)->nullable()->comment('legado: payment_method');
            $table->bigInteger('banco_id')->nullable()->comment('legado: bank_id');
            $table->string('codigo_conta', 20)->nullable()->comment('legado: account_code');
            $table->string('referencia_pagamento', 50)->nullable()->comment('legado: payment_reference');
            $table->boolean('contabilizado')->nullable()->comment('legado: is_posted');
            $table->decimal('montante_total_moeda', 15, 2)->nullable()->comment('legado: total_amount_currency · do código legado js/ui_sales.js:1927');
            $table->string('referencia', 255)->nullable()->comment('legado: reference · do código legado js/ui_sales.js:3692');
            $table->bigInteger('projeto_id')->nullable()->comment('legado: project_id · do código legado js/db_v2.js:568');
            $table->string('codigo_projeto', 255)->nullable()->comment('legado: project_code · do código legado js/db_v2.js:569');
            $table->string('estado', 20)->nullable()->comment('EMITIDO ou ANULADO (nulo nos recibos do legado = EMITIDO)');
            $table->bigInteger('venda_origem_id')->nullable()->comment('Factura-recibo que gerou automaticamente o recibo');
            $table->bigInteger('serie_faturacao_eletronica_id')->nullable()->comment('Série de numeração do recibo');
            $table->string('numero_lan_contabilizacao', 30)->nullable()->comment('N.º do lançamento contabilístico do recibo');
            $table->timestampTz('anulado_em')->nullable()->comment('Data/hora da anulação');
            $table->text('motivo_anulacao')->nullable()->comment('Motivo da anulação');
            $table->string('tipo_recibo', 20)->nullable()->comment('NORMAL (nulo) ou ADIANTAMENTO (recibo sem factura, alocado depois)');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE UNIQUE INDEX uq_recibos_venda_empresa_id_numero_recibo ON recibos_venda (empresa_id, numero_recibo)');
        DB::statement('CREATE INDEX ix_recibos_venda_cliente_id ON recibos_venda (cliente_id)');
        DB::statement('CREATE INDEX ix_recibos_venda_unidade_negocio_id ON recibos_venda (unidade_negocio_id)');
        DB::statement('CREATE INDEX ix_recibos_venda_centro_custo_id ON recibos_venda (centro_custo_id)');
        DB::statement('CREATE INDEX ix_recibos_venda_banco_id ON recibos_venda (banco_id)');
        DB::statement('CREATE INDEX ix_recibos_venda_projeto_id ON recibos_venda (projeto_id)');
        DB::statement('CREATE INDEX ix_recibos_venda_venda_origem_id ON recibos_venda (venda_origem_id)');
        DB::statement('CREATE INDEX ix_recibos_venda_serie_faturacao_eletronica_id ON recibos_venda (serie_faturacao_eletronica_id)');

        // receipt_items (legado) -> itens_recibo_venda · 2 linhas reais no backup
        Schema::create('itens_recibo_venda', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('derivada de recibo_venda_id -> recibos_venda');
            $table->bigInteger('recibo_venda_id')->nullable()->comment('legado: receipt_id');
            $table->bigInteger('venda_id')->nullable()->comment('legado: sale_id');
            $table->decimal('montante_pago', 15, 2)->nullable()->comment('legado: amount_paid');
            $table->string('numero_lan_contabilizacao', 30)->nullable()->comment('Adiantamento: lançamento da alocação à factura (D adiantamentos / C cliente)');
            $table->date('data_alocacao')->nullable()->comment('Adiantamento: data da alocação à factura');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_itens_recibo_venda_empresa_id ON itens_recibo_venda (empresa_id)');
        DB::statement('CREATE INDEX ix_itens_recibo_venda_recibo_venda_id ON itens_recibo_venda (recibo_venda_id)');
        DB::statement('CREATE INDEX ix_itens_recibo_venda_venda_id ON itens_recibo_venda (venda_id)');

        // sales_accounting_config (legado) -> configuracoes_contabeis_vendas · 0 linhas reais no backup
        Schema::create('configuracoes_contabeis_vendas', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id · do código legado js/ui_sales.js:4800');
            $table->string('codigo_conta', 255)->nullable()->comment('legado: account_code · do código legado js/ui_sales.js:4798');
            $table->string('chave', 255)->nullable()->comment('legado: key · do código legado js/ui_sales.js:4800');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE UNIQUE INDEX uq_configuracoes_contabeis_vendas_empresa_id_chave ON configuracoes_contabeis_vendas (empresa_id, chave)');

        // fe_config (legado) -> configuracoes_faturacao_eletronica · 1 linhas reais no backup
        Schema::create('configuracoes_faturacao_eletronica', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: fe_company_id');
            $table->boolean('ativo')->nullable()->comment('legado: activo');
            $table->date('data_inicio')->nullable()->comment('legado: data_inicio');
            $table->jsonb('estabelecimentos')->nullable()->comment('legado: estabelecimentos');
            $table->string('pais_padrao', 10)->nullable()->comment('legado: pais_padrao');
            $table->text('isencao_padrao')->nullable()->comment('legado: isencao_padrao · sem valores reais: tipo a confirmar no código legado');
            $table->jsonb('software')->nullable()->comment('legado: software');
            $table->string('atualizado_por', 100)->nullable()->comment('legado: actualizado_por');
            $table->jsonb('servico')->nullable()->comment('legado: servico · do código legado js/facturacao_agt_envio.js:50');
            $table->timestampTz('atualizado_em')->nullable()->useCurrent()->comment('legado: actualizado_em');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE UNIQUE INDEX uq_configuracoes_faturacao_eletronica_empresa_id ON configuracoes_faturacao_eletronica (empresa_id)');

        // fe_series (legado) -> series_faturacao_eletronica · 0 linhas reais no backup
        Schema::create('series_faturacao_eletronica', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: fe_company_id · do código legado js/facturacao_agt.js:155');
            $table->string('codigo', 50)->nullable()->comment('legado: codigo · do código legado js/facturacao_agt.js:149');
            $table->string('tipo', 5)->nullable()->comment('legado: tipo · do código legado js/facturacao_agt.js:149');
            $table->integer('ano')->nullable()->comment('legado: ano · do código legado js/facturacao_agt.js:149');
            $table->string('origem', 30)->nullable()->comment('legado: origem · do código legado js/facturacao_agt.js:149');
            $table->string('origem_nome', 255)->nullable()->comment('legado: origem_nome · do código legado js/facturacao_agt.js:149');
            $table->string('estabelecimento', 20)->nullable()->comment('legado: estabelecimento · do código legado js/facturacao_agt.js:149');
            $table->boolean('contingencia')->nullable()->comment('legado: contingencia · do código legado js/facturacao_agt.js:149');
            $table->string('estado', 20)->nullable()->comment('legado: estado · do código legado js/facturacao_agt.js:149');
            $table->string('agt_codigo', 60)->nullable()->comment('legado: agt_codigo · do código legado js/facturacao_agt.js:149');
            $table->integer('proximo_numero')->nullable()->comment('legado: proximo · do código legado js/facturacao_agt.js:155');
            $table->date('ultima_data')->nullable()->comment('legado: ultima_data · do código legado js/facturacao_agt.js:155');
            $table->string('criado_por', 255)->nullable()->comment('legado: criado_por · do código legado js/facturacao_agt.js:155');
            $table->integer('agt_primeiro_numero')->nullable()->comment('legado: agt_primeiro · do código legado js/facturacao_agt_envio.js:262');
            $table->integer('agt_ultimo_numero')->nullable()->comment('legado: agt_ultimo · do código legado js/facturacao_agt_envio.js:262');
            $table->integer('agt_quantidade')->nullable()->comment('legado: agt_quantidade · do código legado js/facturacao_agt_envio.js:262');
            $table->timestampTz('agt_pedido_em')->nullable()->comment('legado: agt_pedido_em · do código legado js/facturacao_agt_envio.js:262');
            $table->timestampTz('criado_em')->nullable()->useCurrent()->comment('legado: criado_em · do código legado js/facturacao_agt.js:155');
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE UNIQUE INDEX uq_series_faturacao_eletronica_empresa_id_tipo_ano_codigo ON series_faturacao_eletronica (empresa_id, tipo, ano, codigo)');

        // vendas_estadias_hotel (pivô)
        Schema::create('vendas_estadias_hotel', function (Blueprint $table) {
            $table->bigInteger('empresa_id');
            $table->bigInteger('venda_id');
            $table->bigInteger('estadia_hotel_id');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->primary(['venda_id', 'estadia_hotel_id']);
        });
        DB::statement('CREATE INDEX ix_vendas_estadias_hotel_empresa_id ON vendas_estadias_hotel (empresa_id)');
        DB::statement('CREATE INDEX ix_vendas_estadias_hotel_estadia_hotel_id ON vendas_estadias_hotel (estadia_hotel_id)');

        // vendas_documentos_relacionados (pivô)
        Schema::create('vendas_documentos_relacionados', function (Blueprint $table) {
            $table->bigInteger('empresa_id');
            $table->bigInteger('venda_id');
            $table->bigInteger('venda_relacionada_id');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->primary(['venda_id', 'venda_relacionada_id']);
        });
        DB::statement('CREATE INDEX ix_vendas_documentos_relacionados_empresa_id ON vendas_documentos_relacionados (empresa_id)');
        DB::statement('CREATE INDEX ix_vendas_documentos_relacionados_venda_relacionada_id ON vendas_documentos_relacionados (venda_relacionada_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('vendas_documentos_relacionados');
        Schema::dropIfExists('vendas_estadias_hotel');
        Schema::dropIfExists('series_faturacao_eletronica');
        Schema::dropIfExists('configuracoes_faturacao_eletronica');
        Schema::dropIfExists('configuracoes_contabeis_vendas');
        Schema::dropIfExists('itens_recibo_venda');
        Schema::dropIfExists('recibos_venda');
        Schema::dropIfExists('itens_venda');
        Schema::dropIfExists('vendas');
    }
};
