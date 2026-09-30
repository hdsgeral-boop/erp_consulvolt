<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo POS — GERADO por ferramentas/gerador/gerar_esquema.mjs a partir de docs/dicionario/mapa_de_para.json.
 * Não editar à mão: alterar glossario.mjs / esquema_extra.mjs e regenerar.
 * As chaves estrangeiras são criadas em 2026_09_30_900000_criar_chaves_estrangeiras.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        // pos_terminals (legado) -> terminais_pos · 8 linhas reais no backup · eliminação lógica
        Schema::create('terminais_pos', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: pos_company_id');
            $table->string('codigo', 50)->nullable()->comment('legado: code');
            $table->string('nome', 255)->nullable()->comment('legado: name');
            $table->string('tipo', 20)->nullable()->comment('legado: type · código normalizado ∈ {LOJA, RESTAURANTE, LAVANDARIA, HOTELARIA}; texto original em tipo_original');
            $table->string('tipo_original', 100)->nullable()->comment('legado: type · texto exacto do legado');
            $table->bigInteger('unidade_negocio_id')->nullable()->comment('legado: business_unit_id');
            $table->bigInteger('centro_custo_id')->nullable()->comment('legado: cost_center_id');
            $table->bigInteger('armazem_id')->nullable()->comment('legado: warehouse_id');
            $table->bigInteger('cliente_padrao_id')->nullable()->comment('legado: default_customer_id');
            $table->decimal('fundo_maneio_padrao', 15, 2)->nullable()->comment('legado: default_float');
            $table->jsonb('meios_pagamento')->nullable()->comment('legado: payment_methods');
            $table->jsonb('contadores')->nullable()->comment('legado: counters');
            $table->jsonb('contadores_sessao')->nullable()->comment('legado: session_counters');
            $table->jsonb('contadores_z')->nullable()->comment('legado: z_counters');
            $table->boolean('ativo')->nullable()->comment('legado: is_active');
            $table->string('id_legado', 30)->nullable()->comment('legado: legacy_id');
            $table->string('criado_por', 100)->nullable()->comment('legado: created_by');
            $table->string('atualizado_por', 100)->nullable()->comment('legado: updated_by');
            $table->jsonb('lavandaria_contadores_os')->nullable()->comment('legado: lav_os_counters');
            $table->jsonb('lavandaria_contadores_rc')->nullable()->comment('legado: lav_rc_counters');
            $table->jsonb('lavandaria_contadores_ft')->nullable()->comment('legado: lav_ft_counters');
            $table->string('hotel_hora_entrada', 10)->nullable()->comment('legado: hotel_checkin_time');
            $table->string('hotel_hora_saida', 10)->nullable()->comment('legado: hotel_checkout_time');
            $table->integer('hotel_tolerancia_atraso_min')->nullable()->comment('legado: hotel_late_tolerance_min');
            $table->boolean('hotel_bloco_horas')->nullable()->comment('legado: hotel_hour_block');
            $table->string('hotel_bloco_horas_de', 10)->nullable()->comment('legado: hotel_hour_block_from');
            $table->string('hotel_bloco_horas_ate', 10)->nullable()->comment('legado: hotel_hour_block_to');
            $table->timestampTz('criado_em')->nullable()->useCurrent()->comment('legado: created_at');
            $table->timestampTz('atualizado_em')->nullable()->useCurrent()->comment('legado: updated_at');
            $table->timestampTz('eliminado_em')->nullable();
        });
        DB::statement('CREATE UNIQUE INDEX uq_terminais_pos_empresa_id_codigo ON terminais_pos (empresa_id, codigo) WHERE eliminado_em IS NULL');
        DB::statement('CREATE INDEX ix_terminais_pos_unidade_negocio_id ON terminais_pos (unidade_negocio_id)');
        DB::statement('CREATE INDEX ix_terminais_pos_centro_custo_id ON terminais_pos (centro_custo_id)');
        DB::statement('CREATE INDEX ix_terminais_pos_armazem_id ON terminais_pos (armazem_id)');
        DB::statement('CREATE INDEX ix_terminais_pos_cliente_padrao_id ON terminais_pos (cliente_padrao_id)');
        DB::statement('ALTER TABLE terminais_pos ADD CONSTRAINT ck_terminais_pos_tipo CHECK (tipo IS NULL OR tipo IN (\'LOJA\',\'RESTAURANTE\',\'LAVANDARIA\',\'HOTELARIA\'))');

        // pos_sessions (legado) -> sessoes_pos · 4 linhas reais no backup
        Schema::create('sessoes_pos', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: pos_company_id');
            $table->bigInteger('terminal_pos_id')->nullable()->comment('legado: terminal_id');
            $table->string('codigo_terminal', 50)->nullable()->comment('legado: terminal_code');
            $table->string('nome_terminal', 255)->nullable()->comment('legado: terminal_name');
            $table->string('codigo_sessao', 50)->nullable()->comment('legado: session_code');
            $table->string('estado', 20)->nullable()->comment('legado: status');
            $table->timestampTz('aberto_em')->nullable()->comment('legado: opened_at');
            $table->decimal('fundo_maneio_abertura', 15, 2)->nullable()->comment('legado: opening_float');
            $table->bigInteger('operador_id')->nullable()->comment('legado: operator_id');
            $table->string('nome_operador', 255)->nullable()->comment('legado: operator_name');
            $table->string('estado_contabilizacao', 20)->nullable()->comment('legado: posting_status');
            $table->string('estado_liquidacao', 20)->nullable()->comment('legado: settlement_status');
            $table->string('estado_desvio', 20)->nullable()->comment('legado: deviation_status · código normalizado ∈ {NAO_APLICAVEL, SEM_DESVIO, DELIBERADO, PENDENTE}; texto original em estado_desvio_original');
            $table->string('estado_desvio_original', 100)->nullable()->comment('legado: deviation_status · texto exacto do legado');
            $table->timestampTz('fechado_em')->nullable()->comment('legado: closed_at');
            $table->string('fechado_por', 100)->nullable()->comment('legado: closed_by');
            $table->string('numero_z', 50)->nullable()->comment('legado: z_number');
            $table->integer('numero_vendas')->nullable()->comment('legado: sales_count');
            $table->decimal('total_vendas', 15, 2)->nullable()->comment('legado: total_sales');
            $table->jsonb('totais_por_metodo')->nullable()->comment('legado: totals_by_method');
            $table->jsonb('transferencias')->nullable()->comment('legado: transfers');
            $table->decimal('vendas_numerario', 15, 2)->nullable()->comment('legado: cash_sales');
            $table->decimal('numerario_esperado', 15, 2)->nullable()->comment('legado: cash_expected');
            $table->decimal('numerario_contado', 15, 2)->nullable()->comment('legado: cash_counted');
            $table->jsonb('contagens_numerario')->nullable()->comment('legado: cash_counts');
            $table->decimal('desvio', 15, 2)->nullable()->comment('legado: deviation · tipo forçado (inferido: integer)');
            $table->jsonb('fechos_tpa')->nullable()->comment('legado: tpa_closes');
            $table->text('justificacao')->nullable()->comment('legado: justification');
            $table->jsonb('lans_contabilizacao')->nullable()->comment('legado: posting_lans');
            $table->bigInteger('diario_contabilizacao_id')->nullable()->comment('legado: posting_journal_id');
            $table->timestampTz('contabilizado_em')->nullable()->comment('legado: posted_at');
            $table->string('contabilizado_por', 100)->nullable()->comment('legado: posted_by');
            $table->jsonb('deliberacao')->nullable()->comment('legado: deliberation');
            $table->timestampTz('descontabilizado_em')->nullable()->comment('legado: unposted_at · do código legado js/pos_prestacao.js:348');
            $table->string('descontabilizado_por', 255)->nullable()->comment('legado: unposted_by · do código legado js/pos_prestacao.js:348');
            $table->jsonb('deliberacao_cancelada')->nullable()->comment('legado: deliberation_cancelled · do código legado js/pos_prestacao.js:487');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE UNIQUE INDEX uq_sessoes_pos_terminal_pos_id ON sessoes_pos (terminal_pos_id) WHERE estado = \'ABERTA\'');
        DB::statement('CREATE INDEX ix_sessoes_pos_empresa_id ON sessoes_pos (empresa_id)');
        DB::statement('CREATE INDEX ix_sessoes_pos_terminal_pos_id ON sessoes_pos (terminal_pos_id)');
        DB::statement('CREATE INDEX ix_sessoes_pos_diario_contabilizacao_id ON sessoes_pos (diario_contabilizacao_id)');
        DB::statement('ALTER TABLE sessoes_pos ADD CONSTRAINT ck_sessoes_pos_estado_desvio CHECK (estado_desvio IS NULL OR estado_desvio IN (\'NAO_APLICAVEL\',\'SEM_DESVIO\',\'DELIBERADO\',\'PENDENTE\'))');

        // pos_settlements (legado) -> liquidacoes_pos · 6 linhas reais no backup
        Schema::create('liquidacoes_pos', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: pos_company_id');
            $table->string('estado', 20)->nullable()->comment('legado: status');
            $table->string('criado_por', 100)->nullable()->comment('legado: created_by');
            $table->bigInteger('sessao_pos_id')->nullable()->comment('legado: session_id');
            $table->string('numero_z', 50)->nullable()->comment('legado: z_number');
            $table->string('chave_item', 60)->nullable()->comment('legado: item_key · tipo forçado (inferido: varchar(30))');
            $table->string('natureza_registo', 20)->nullable()->comment('legado: kind');
            $table->string('meio_pagamento_codigo', 40)->nullable()->comment('legado: pm_id · tipo forçado (inferido: varchar(10))');
            $table->date('data')->nullable()->comment('legado: date');
            $table->decimal('montante_bruto', 15, 2)->nullable()->comment('legado: amount_gross');
            $table->decimal('comissao', 15, 2)->nullable()->comment('legado: commission');
            $table->decimal('montante_liquido', 15, 2)->nullable()->comment('legado: amount_net');
            $table->string('alvo', 20)->nullable()->comment('legado: target');
            $table->string('conta_destino', 20)->nullable()->comment('legado: target_account');
            $table->string('conta_transitoria', 20)->nullable()->comment('legado: transit_account');
            $table->bigInteger('sessao_caixa_id')->nullable()->comment('legado: cash_session_id');
            $table->bigInteger('movimento_caixa_id')->nullable()->comment('legado: cash_line_id');
            $table->string('conta_comissao', 20)->nullable()->comment('legado: commission_account');
            $table->bigInteger('documento_tesouraria_id')->nullable()->comment('legado: treasury_doc_id');
            $table->string('referencia_lote', 50)->nullable()->comment('legado: batch_ref');
            $table->string('numero_documento', 50)->nullable()->comment('legado: doc_number');
            $table->string('referencia', 100)->nullable()->comment('legado: reference · tipos mistos: string_inteiro=2, string=1; tipo forçado (inferido: varchar(20))');
            $table->boolean('comissao_deduzida')->nullable()->comment('legado: commission_deducted · do código legado js/pos_prestacao.js:761');
            $table->bigInteger('documento_comissao_id')->nullable()->comment('legado: commission_doc_id · do código legado js/pos_prestacao.js:761');
            $table->timestampTz('cancelado_em')->nullable()->comment('legado: cancelled_at · do código legado js/pos_prestacao.js:790');
            $table->string('cancelado_por', 255)->nullable()->comment('legado: cancelled_by · do código legado js/pos_prestacao.js:790');
            $table->timestampTz('criado_em')->nullable()->useCurrent()->comment('legado: created_at');
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE UNIQUE INDEX uq_liquidacoes_pos_sessao_pos_id_chave_item ON liquidacoes_pos (sessao_pos_id, chave_item) WHERE estado = \'REGISTADO\'');
        DB::statement('CREATE INDEX ix_liquidacoes_pos_empresa_id ON liquidacoes_pos (empresa_id)');
        DB::statement('CREATE INDEX ix_liquidacoes_pos_sessao_pos_id ON liquidacoes_pos (sessao_pos_id)');
        DB::statement('CREATE INDEX ix_liquidacoes_pos_sessao_caixa_id ON liquidacoes_pos (sessao_caixa_id)');
        DB::statement('CREATE INDEX ix_liquidacoes_pos_movimento_caixa_id ON liquidacoes_pos (movimento_caixa_id)');
        DB::statement('CREATE INDEX ix_liquidacoes_pos_documento_tesouraria_id ON liquidacoes_pos (documento_tesouraria_id)');
        DB::statement('CREATE INDEX ix_liquidacoes_pos_documento_comissao_id ON liquidacoes_pos (documento_comissao_id)');

        // pos_settings (legado) -> configuracoes_pos · 1 linhas reais no backup
        Schema::create('configuracoes_pos', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: pos_company_id');
            $table->string('conta_sobra', 20)->nullable()->comment('legado: account_surplus');
            $table->string('conta_quebra', 20)->nullable()->comment('legado: account_shortage');
            $table->string('conta_operador', 20)->nullable()->comment('legado: account_operator');
            $table->decimal('tolerancia_desvio', 15, 2)->nullable()->comment('legado: deviation_tolerance · tipo forçado (inferido: integer)');
            $table->string('codigo_diario', 50)->nullable()->comment('legado: journal_code');
            $table->string('atualizado_por', 100)->nullable()->comment('legado: updated_by');
            $table->timestampTz('atualizado_em')->nullable()->useCurrent()->comment('legado: updated_at');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE UNIQUE INDEX uq_configuracoes_pos_empresa_id ON configuracoes_pos (empresa_id)');

        // hotel_stays (legado) -> estadias_hotel · 8 linhas reais no backup
        Schema::create('estadias_hotel', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: hotel_company_id');
            $table->bigInteger('terminal_pos_id')->nullable()->comment('legado: terminal_id');
            $table->string('codigo_terminal', 50)->nullable()->comment('legado: terminal_code');
            $table->bigInteger('sessao_pos_id')->nullable()->comment('legado: session_id');
            $table->bigInteger('produto_quarto_id')->nullable()->comment('legado: room_product_id');
            $table->string('nome_quarto', 255)->nullable()->comment('legado: room_name');
            $table->string('estado', 20)->nullable()->comment('legado: status');
            $table->bigInteger('cliente_hospede_id')->nullable()->comment('legado: guest_customer_id');
            $table->string('nome_hospede', 255)->nullable()->comment('legado: guest_name');
            $table->integer('numero_hospedes')->nullable()->comment('legado: guests_count');
            $table->string('modo', 10)->nullable()->comment('legado: mode');
            $table->timestampTz('entrada_em')->nullable()->comment('legado: checkin_at');
            $table->timestampTz('saida_prevista_em')->nullable()->comment('legado: planned_checkout_at');
            $table->decimal('quantidade', 12, 3)->nullable()->comment('legado: quantity');
            $table->decimal('preco_unitario', 15, 2)->nullable()->comment('legado: unit_price');
            $table->decimal('taxa_imposto', 9, 4)->nullable()->comment('legado: tax_rate');
            $table->text('observacoes')->nullable()->comment('legado: notes · sem valores reais: tipo a confirmar no código legado');
            $table->jsonb('itens')->nullable()->comment('legado: items');
            $table->jsonb('historico_alteracoes')->nullable()->comment('legado: history');
            $table->string('criado_por', 100)->nullable()->comment('legado: created_by');
            $table->string('atualizado_por', 100)->nullable()->comment('legado: updated_by');
            $table->timestampTz('saida_em')->nullable()->comment('legado: checkout_at');
            $table->decimal('quantidade_final', 12, 3)->nullable()->comment('legado: final_quantity');
            $table->text('opcao_atraso')->nullable()->comment('legado: late_choice · sem valores reais: tipo a confirmar no código legado');
            $table->decimal('percentagem_desconto', 9, 4)->nullable()->comment('legado: discount_pct');
            $table->bigInteger('venda_id')->nullable()->comment('legado: sale_id');
            $table->string('numero_venda', 50)->nullable()->comment('legado: sale_number');
            $table->bigInteger('sessao_fecho_id')->nullable()->comment('legado: closed_session_id');
            $table->timestampTz('fechado_em')->nullable()->comment('legado: closed_at');
            $table->string('fechado_por', 100)->nullable()->comment('legado: closed_by');
            $table->text('motivo_cancelamento')->nullable()->comment('legado: cancel_reason · do código legado js/hotelaria.js:488');
            $table->timestampTz('criado_em')->nullable()->useCurrent()->comment('legado: created_at');
            $table->timestampTz('atualizado_em')->nullable()->useCurrent()->comment('legado: updated_at');
        });
        DB::statement('CREATE UNIQUE INDEX uq_estadias_hotel_produto_quarto_id ON estadias_hotel (produto_quarto_id) WHERE estado = \'ABERTA\'');
        DB::statement('CREATE INDEX ix_estadias_hotel_empresa_id ON estadias_hotel (empresa_id)');
        DB::statement('CREATE INDEX ix_estadias_hotel_terminal_pos_id ON estadias_hotel (terminal_pos_id)');
        DB::statement('CREATE INDEX ix_estadias_hotel_sessao_pos_id ON estadias_hotel (sessao_pos_id)');
        DB::statement('CREATE INDEX ix_estadias_hotel_produto_quarto_id ON estadias_hotel (produto_quarto_id)');
        DB::statement('CREATE INDEX ix_estadias_hotel_cliente_hospede_id ON estadias_hotel (cliente_hospede_id)');
        DB::statement('CREATE INDEX ix_estadias_hotel_venda_id ON estadias_hotel (venda_id)');
        DB::statement('CREATE INDEX ix_estadias_hotel_sessao_fecho_id ON estadias_hotel (sessao_fecho_id)');

        // lav_orders (legado) -> pedidos_lavandaria · 5 linhas reais no backup
        Schema::create('pedidos_lavandaria', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: lav_company_id');
            $table->string('numero_encomenda', 50)->nullable()->comment('legado: order_number');
            $table->bigInteger('terminal_pos_id')->nullable()->comment('legado: terminal_id');
            $table->string('codigo_terminal', 50)->nullable()->comment('legado: terminal_code');
            $table->bigInteger('cliente_id')->nullable()->comment('legado: customer_id');
            $table->timestampTz('recebido_em')->nullable()->comment('legado: received_at');
            $table->string('recebido_por', 100)->nullable()->comment('legado: received_by');
            $table->bigInteger('sessao_rececao_id')->nullable()->comment('legado: reception_session_id');
            $table->string('modo_faturacao', 20)->nullable()->comment('legado: invoice_mode');
            $table->boolean('urgente')->nullable()->comment('legado: urgent');
            $table->timestampTz('data_prometida')->nullable()->comment('legado: promised_date');
            $table->text('observacoes')->nullable()->comment('legado: notes · sem valores reais: tipo a confirmar no código legado');
            $table->jsonb('recolha')->nullable()->comment('legado: pickup');
            $table->jsonb('entrega')->nullable()->comment('legado: delivery');
            $table->jsonb('extras')->nullable()->comment('legado: extras');
            $table->jsonb('historico_alteracoes')->nullable()->comment('legado: history');
            $table->jsonb('itens')->nullable()->comment('legado: items');
            $table->string('estado', 20)->nullable()->comment('legado: status');
            $table->bigInteger('colaborador_atribuido_id')->nullable()->comment('legado: assigned_employee_id');
            $table->string('nome_atribuido', 255)->nullable()->comment('legado: assigned_name');
            $table->timestampTz('atribuido_em')->nullable()->comment('legado: assigned_at');
            $table->string('atribuido_por', 100)->nullable()->comment('legado: assigned_by');
            $table->text('nota_atribuicao')->nullable()->comment('legado: assignment_note · sem valores reais: tipo a confirmar no código legado');
            $table->jsonb('atribuicoes')->nullable()->comment('legado: assignments');
            $table->timestampTz('entregue_em')->nullable()->comment('legado: delivered_at');
            $table->text('motivo_cancelamento')->nullable()->comment('legado: cancel_reason · do código legado js/lavandaria.js:1068');
            $table->text('faturas_ids_legado')->nullable()->comment('legado: invoice_ids · lista de ids do legado; normalizada em pedidos_lavandaria_faturas');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_pedidos_lavandaria_empresa_id ON pedidos_lavandaria (empresa_id)');
        DB::statement('CREATE INDEX ix_pedidos_lavandaria_terminal_pos_id ON pedidos_lavandaria (terminal_pos_id)');
        DB::statement('CREATE INDEX ix_pedidos_lavandaria_cliente_id ON pedidos_lavandaria (cliente_id)');
        DB::statement('CREATE INDEX ix_pedidos_lavandaria_sessao_rececao_id ON pedidos_lavandaria (sessao_rececao_id)');
        DB::statement('CREATE INDEX ix_pedidos_lavandaria_colaborador_atribuido_id ON pedidos_lavandaria (colaborador_atribuido_id)');

        // lav_payments (legado) -> pagamentos_lavandaria · 5 linhas reais no backup
        Schema::create('pagamentos_lavandaria', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: lav_company_id');
            $table->bigInteger('pedido_lavandaria_id')->nullable()->comment('legado: order_id');
            $table->string('numero_encomenda', 50)->nullable()->comment('legado: order_number');
            $table->bigInteger('sessao_pos_id')->nullable()->comment('legado: session_id');
            $table->bigInteger('terminal_pos_id')->nullable()->comment('legado: terminal_id');
            $table->bigInteger('cliente_id')->nullable()->comment('legado: customer_id');
            $table->date('data')->nullable()->comment('legado: date');
            $table->decimal('montante', 15, 2)->nullable()->comment('legado: amount');
            $table->decimal('troco', 15, 2)->nullable()->comment('legado: change');
            $table->jsonb('pos_pagamentos')->nullable()->comment('legado: pos_payments');
            $table->string('numero_recibo', 50)->nullable()->comment('legado: receipt_number');
            $table->string('natureza_registo', 20)->nullable()->comment('legado: kind');
            $table->string('estado', 20)->nullable()->comment('legado: status');
            $table->string('criado_por', 100)->nullable()->comment('legado: created_by');
            $table->bigInteger('venda_id')->nullable()->comment('legado: sale_id · do código legado js/lavandaria.js:274');
            $table->jsonb('lans_contabilizacao')->nullable()->comment('legado: posting_lans · do código legado js/lavandaria.js:2470');
            $table->timestampTz('criado_em')->nullable()->useCurrent()->comment('legado: created_at');
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_pagamentos_lavandaria_empresa_id ON pagamentos_lavandaria (empresa_id)');
        DB::statement('CREATE INDEX ix_pagamentos_lavandaria_pedido_lavandaria_id ON pagamentos_lavandaria (pedido_lavandaria_id)');
        DB::statement('CREATE INDEX ix_pagamentos_lavandaria_sessao_pos_id ON pagamentos_lavandaria (sessao_pos_id)');
        DB::statement('CREATE INDEX ix_pagamentos_lavandaria_terminal_pos_id ON pagamentos_lavandaria (terminal_pos_id)');
        DB::statement('CREATE INDEX ix_pagamentos_lavandaria_cliente_id ON pagamentos_lavandaria (cliente_id)');
        DB::statement('CREATE INDEX ix_pagamentos_lavandaria_venda_id ON pagamentos_lavandaria (venda_id)');

        // lav_claims (legado) -> reclamacoes_lavandaria · 0 linhas reais no backup
        Schema::create('reclamacoes_lavandaria', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: lav_company_id · do código legado js/lavandaria.js:1055');
            $table->bigInteger('pedido_lavandaria_id')->nullable()->comment('legado: order_id · do código legado js/lavandaria.js:1055');
            $table->string('numero_encomenda', 50)->nullable()->comment('legado: order_number · do código legado js/lavandaria.js:1055');
            $table->string('linha_pedido_id', 255)->nullable()->comment('legado: line_id · do código legado js/lavandaria.js:1055');
            $table->string('nome_item', 255)->nullable()->comment('legado: item_name · do código legado js/lavandaria.js:1055');
            $table->text('descricao_peca')->nullable()->comment('legado: piece_desc · do código legado js/lavandaria.js:1055');
            $table->text('estado_entrada')->nullable()->comment('legado: entry_state · do código legado js/lavandaria.js:1055');
            $table->bigInteger('cliente_id')->nullable()->comment('legado: customer_id · do código legado js/lavandaria.js:1055');
            $table->text('descricao')->nullable()->comment('legado: description · do código legado js/lavandaria.js:1055');
            $table->decimal('valor_declarado', 15, 2)->nullable()->comment('legado: declared_value · do código legado js/lavandaria.js:1055');
            $table->string('estado', 255)->nullable()->comment('legado: status · do código legado js/lavandaria.js:1055');
            $table->string('criado_por', 255)->nullable()->comment('legado: created_by · do código legado js/lavandaria.js:1055');
            $table->string('referencia_comprovativo', 255)->nullable()->comment('legado: proof_ref · do código legado js/lavandaria.js:1535');
            $table->date('data_comprovativo')->nullable()->comment('legado: proof_date · do código legado js/lavandaria.js:1535');
            $table->decimal('valor_comprovativo', 15, 2)->nullable()->comment('legado: proof_amount · do código legado js/lavandaria.js:1535');
            $table->text('nota_decisao')->nullable()->comment('legado: decision_note · do código legado js/lavandaria.js:1535');
            $table->timestampTz('decidido_em')->nullable()->comment('legado: decided_at · do código legado js/lavandaria.js:1535');
            $table->string('decidido_por', 255)->nullable()->comment('legado: decided_by · do código legado js/lavandaria.js:1535');
            $table->decimal('valor_compensacao', 15, 2)->nullable()->comment('legado: compensation · do código legado js/lavandaria.js:1536');
            $table->bigInteger('documento_tesouraria_id')->nullable()->comment('legado: treasury_doc_id · do código legado js/lavandaria.js:1570');
            $table->timestampTz('pago_em')->nullable()->comment('legado: paid_at · do código legado js/lavandaria.js:1570');
            $table->string('pago_por', 100)->nullable()->comment('legado: paid_by · do código legado js/lavandaria.js:1570');
            $table->timestampTz('criado_em')->nullable()->useCurrent()->comment('legado: created_at · do código legado js/lavandaria.js:1055');
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_reclamacoes_lavandaria_empresa_id ON reclamacoes_lavandaria (empresa_id)');
        DB::statement('CREATE INDEX ix_reclamacoes_lavandaria_pedido_lavandaria_id ON reclamacoes_lavandaria (pedido_lavandaria_id)');
        DB::statement('CREATE INDEX ix_reclamacoes_lavandaria_cliente_id ON reclamacoes_lavandaria (cliente_id)');
        DB::statement('CREATE INDEX ix_reclamacoes_lavandaria_documento_tesouraria_id ON reclamacoes_lavandaria (documento_tesouraria_id)');

        // lav_settings (legado) -> configuracoes_lavandaria · 0 linhas reais no backup
        Schema::create('configuracoes_lavandaria', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: lav_company_id · do código legado js/lavandaria.js:1473');
            $table->boolean('taxa_armazenagem_ativa')->nullable()->comment('legado: storage_fee_enabled · do código legado js/lavandaria.js:1473');
            $table->integer('dias_armazenagem_gratis')->nullable()->comment('legado: storage_free_days · do código legado js/lavandaria.js:1473');
            $table->decimal('percentagem_armazenagem_dia', 9, 4)->nullable()->comment('legado: storage_fee_pct_day · do código legado js/lavandaria.js:1474');
            $table->decimal('percentagem_adiantamento', 9, 4)->nullable()->comment('legado: cf_advance_pct · do código legado js/lavandaria.js:1474');
            $table->decimal('percentagem_urgencia', 9, 4)->nullable()->comment('legado: urgency_pct · do código legado js/lavandaria.js:1475');
            $table->decimal('fator_prazo_urgencia', 6, 4)->nullable()->comment('legado: urgency_lead_factor · do código legado js/lavandaria.js:1475');
            $table->decimal('valor_taxa_recolha', 9, 4)->nullable()->comment('legado: pickup_fee · do código legado js/lavandaria.js:1476');
            $table->decimal('valor_taxa_entrega', 9, 4)->nullable()->comment('legado: delivery_fee · do código legado js/lavandaria.js:1476');
            $table->integer('dias_reclamacao')->nullable()->comment('legado: claim_days · do código legado js/lavandaria.js:1476');
            $table->string('conta_extras', 255)->nullable()->comment('legado: extras_account · do código legado js/lavandaria.js:1477');
            $table->decimal('taxa_extras', 9, 4)->nullable()->comment('legado: extras_tax_rate · do código legado js/lavandaria.js:1477');
            $table->string('conta_compensacao', 255)->nullable()->comment('legado: compensation_account · do código legado js/lavandaria.js:1478');
            $table->boolean('faturar_no_adiantamento')->nullable()->comment('legado: invoice_on_advance · do código legado js/lavandaria.js:1478');
            $table->string('atualizado_por', 255)->nullable()->comment('legado: updated_by · do código legado js/lavandaria.js:1478');
            $table->timestampTz('atualizado_em')->nullable()->useCurrent()->comment('legado: updated_at · do código legado js/lavandaria.js:1478');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_configuracoes_lavandaria_empresa_id ON configuracoes_lavandaria (empresa_id)');

        // lav_pieces (legado) -> pecas_lavandaria · 2 linhas reais no backup
        Schema::create('pecas_lavandaria', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: lav_company_id');
            $table->string('codigo', 50)->nullable()->comment('legado: code');
            $table->string('criado_por', 100)->nullable()->comment('legado: created_by');
            $table->string('nome', 255)->nullable()->comment('legado: name');
            $table->string('tecido', 100)->nullable()->comment('legado: fabric · tipo forçado (inferido: varchar(20))');
            $table->string('cor', 50)->nullable()->comment('legado: color · tipo forçado (inferido: varchar(10))');
            $table->string('unidade', 10)->nullable()->comment('legado: unit');
            $table->boolean('ativo')->nullable()->comment('legado: is_active');
            $table->string('atualizado_por', 100)->nullable()->comment('legado: updated_by');
            $table->decimal('preco', 15, 2)->nullable()->comment('legado: price');
            $table->jsonb('precos_servico')->nullable()->comment('legado: service_prices');
            $table->timestampTz('criado_em')->nullable()->useCurrent()->comment('legado: created_at');
            $table->timestampTz('atualizado_em')->nullable()->useCurrent()->comment('legado: updated_at');
        });
        DB::statement('CREATE INDEX ix_pecas_lavandaria_empresa_id ON pecas_lavandaria (empresa_id)');

        // pedidos_lavandaria_faturas (pivô)
        Schema::create('pedidos_lavandaria_faturas', function (Blueprint $table) {
            $table->bigInteger('empresa_id');
            $table->bigInteger('pedido_lavandaria_id');
            $table->bigInteger('venda_id');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->primary(['pedido_lavandaria_id', 'venda_id']);
        });
        DB::statement('CREATE INDEX ix_pedidos_lavandaria_faturas_empresa_id ON pedidos_lavandaria_faturas (empresa_id)');
        DB::statement('CREATE INDEX ix_pedidos_lavandaria_faturas_venda_id ON pedidos_lavandaria_faturas (venda_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('pedidos_lavandaria_faturas');
        Schema::dropIfExists('pecas_lavandaria');
        Schema::dropIfExists('configuracoes_lavandaria');
        Schema::dropIfExists('reclamacoes_lavandaria');
        Schema::dropIfExists('pagamentos_lavandaria');
        Schema::dropIfExists('pedidos_lavandaria');
        Schema::dropIfExists('estadias_hotel');
        Schema::dropIfExists('configuracoes_pos');
        Schema::dropIfExists('liquidacoes_pos');
        Schema::dropIfExists('sessoes_pos');
        Schema::dropIfExists('terminais_pos');
    }
};
