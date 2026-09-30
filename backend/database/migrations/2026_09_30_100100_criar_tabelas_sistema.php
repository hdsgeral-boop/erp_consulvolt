<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo Sistema — GERADO por ferramentas/gerador/gerar_esquema.mjs a partir de docs/dicionario/mapa_de_para.json.
 * Não editar à mão: alterar glossario.mjs / esquema_extra.mjs e regenerar.
 * As chaves estrangeiras são criadas em 2026_09_30_900000_criar_chaves_estrangeiras.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        // system_config (legado) -> configuracoes_sistema · 4 linhas reais no backup
        Schema::create('configuracoes_sistema', function (Blueprint $table) {
            $table->id();
            $table->string('chave', 150)->nullable()->comment('legado: key');
            $table->text('valor')->nullable()->comment('legado: value · tipos mistos: string=3, inteiro=1');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });

        // business_units (legado) -> unidades_negocio · 16 linhas reais no backup · eliminação lógica
        Schema::create('unidades_negocio', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->string('codigo', 50)->nullable()->comment('legado: code');
            $table->string('nome', 255)->nullable()->comment('legado: name');
            $table->string('nome_abreviado', 255)->nullable()->comment('legado: short_name');
            $table->text('descricao')->nullable()->comment('legado: description');
            $table->bigInteger('unidade_negocio_pai_id')->nullable()->comment('legado: parent_id');
            $table->integer('ordem_sequencia')->nullable()->comment('legado: seq_order');
            $table->string('estado', 10)->nullable()->comment('legado: status');
            $table->date('valido_de')->nullable()->comment('legado: valid_from');
            $table->text('valido_ate')->nullable()->comment('legado: valid_to · sem valores reais: tipo a confirmar no código legado');
            $table->text('endereco')->nullable()->comment('legado: address · sem valores reais: tipo a confirmar no código legado');
            $table->text('cidade')->nullable()->comment('legado: city · sem valores reais: tipo a confirmar no código legado');
            $table->text('estado_fluxo')->nullable()->comment('legado: state · sem valores reais: tipo a confirmar no código legado');
            $table->text('codigo_postal')->nullable()->comment('legado: postal_code · sem valores reais: tipo a confirmar no código legado');
            $table->text('pais')->nullable()->comment('legado: country · sem valores reais: tipo a confirmar no código legado');
            $table->text('telefone')->nullable()->comment('legado: phone · sem valores reais: tipo a confirmar no código legado');
            $table->text('email')->nullable()->comment('legado: email · sem valores reais: tipo a confirmar no código legado');
            $table->text('fax')->nullable()->comment('legado: fax · sem valores reais: tipo a confirmar no código legado');
            $table->text('website')->nullable()->comment('legado: website · sem valores reais: tipo a confirmar no código legado');
            $table->text('codigo_moeda')->nullable()->comment('legado: currency · sem valores reais: tipo a confirmar no código legado');
            $table->decimal('bolsa_valores', 15, 2)->nullable()->comment('legado: stock_exchange');
            $table->text('simbolo_bolsa')->nullable()->comment('legado: ticker_symbol · sem valores reais: tipo a confirmar no código legado');
            $table->bigInteger('colaborador_gestor_id')->nullable()->comment('legado: manager_employee_id');
            $table->boolean('tem_vendas')->nullable()->comment('legado: has_sales');
            $table->boolean('tem_servico')->nullable()->comment('legado: has_service');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
            $table->timestampTz('eliminado_em')->nullable();
        });
        DB::statement('CREATE UNIQUE INDEX uq_unidades_negocio_empresa_id_codigo ON unidades_negocio (empresa_id, codigo) WHERE eliminado_em IS NULL');
        DB::statement('CREATE INDEX ix_unidades_negocio_unidade_negocio_pai_id ON unidades_negocio (unidade_negocio_pai_id)');
        DB::statement('CREATE INDEX ix_unidades_negocio_colaborador_gestor_id ON unidades_negocio (colaborador_gestor_id)');

        // cost_centers (legado) -> centros_custo · 38 linhas reais no backup · eliminação lógica
        Schema::create('centros_custo', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->string('codigo', 50)->nullable()->comment('legado: code');
            $table->text('descricao')->nullable()->comment('legado: description');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
            $table->timestampTz('eliminado_em')->nullable();
        });
        DB::statement('CREATE UNIQUE INDEX uq_centros_custo_empresa_id_codigo ON centros_custo (empresa_id, codigo) WHERE eliminado_em IS NULL');

        // currencies (legado) -> moedas · 5 linhas reais no backup · eliminação lógica
        Schema::create('moedas', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 50)->nullable()->comment('legado: code');
            $table->string('nome', 255)->nullable()->comment('legado: name');
            $table->string('simbolo', 10)->nullable()->comment('legado: symbol');
            $table->integer('casas_decimais')->nullable()->comment('legado: decimals');
            $table->boolean('ativo')->nullable()->comment('legado: is_active');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
            $table->timestampTz('eliminado_em')->nullable();
        });
        DB::statement('CREATE UNIQUE INDEX uq_moedas_codigo ON moedas (codigo) WHERE eliminado_em IS NULL');

        // exchange_rates (legado) -> taxas_cambio · 36 linhas reais no backup
        Schema::create('taxas_cambio', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->nullable()->comment('legado: scope_company_id');
            $table->string('codigo_moeda', 50)->nullable()->comment('legado: currency');
            $table->date('data_taxa')->nullable()->comment('legado: rate_date');
            $table->decimal('taxa', 18, 6)->nullable()->comment('legado: rate · tipo forçado (inferido: numeric(9,4))');
            $table->string('fonte_dados', 50)->nullable()->comment('legado: source · tipo forçado (inferido: varchar(10))');
            $table->decimal('taxa_compra_bai', 18, 6)->nullable()->comment('legado: bai_buy');
            $table->decimal('taxa_venda_bai', 18, 6)->nullable()->comment('legado: bai_sell');
            $table->string('criado_por', 100)->nullable()->comment('legado: created_by · tipo forçado (inferido: varchar(10))');
            $table->string('atualizado_por', 100)->nullable()->comment('legado: updated_by · tipo forçado (inferido: varchar(10))');
            $table->timestampTz('criado_em')->nullable()->useCurrent()->comment('legado: created_at');
            $table->timestampTz('atualizado_em')->nullable()->useCurrent()->comment('legado: updated_at');
        });
        DB::statement('CREATE UNIQUE INDEX uq_taxas_cambio_empresa_id_codigo_moeda_data_taxa ON taxas_cambio (empresa_id, codigo_moeda, data_taxa)');

        // internal_rules (legado) -> regras_internas_ia · 1 linhas reais no backup
        Schema::create('regras_internas_ia', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->string('nome', 255)->nullable()->comment('legado: name');
            $table->text('palavras_chave')->nullable()->comment('legado: keywords');
            $table->text('modelo')->nullable()->comment('legado: template');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_regras_internas_ia_empresa_id ON regras_internas_ia (empresa_id)');

        // document_types (legado) -> tipos_documento · 1 linhas reais no backup
        Schema::create('tipos_documento', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->string('nome', 255)->nullable()->comment('legado: name');
            $table->string('contexto_modulo', 30)->nullable()->comment('legado: module_context · tipo forçado (inferido: varchar(10))');
            $table->text('descricao')->nullable()->comment('legado: description · sem valores reais: tipo a confirmar no código legado');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_tipos_documento_empresa_id ON tipos_documento (empresa_id)');

        // documents (legado) -> documentos_anexos · 1 linhas reais no backup
        Schema::create('documentos_anexos', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->bigInteger('tipo_documento_id')->nullable()->comment('legado: type_id');
            $table->string('tipo_entidade', 10)->nullable()->comment('legado: entity_type');
            $table->text('entidade_id')->nullable()->comment('legado: entity_id · sem valores reais: tipo a confirmar no código legado');
            $table->string('nome_ficheiro', 255)->nullable()->comment('legado: file_name');
            $table->text('conteudo_ficheiro')->nullable()->comment('legado: file_data');
            $table->string('tipo_mime', 30)->nullable()->comment('legado: mime_type');
            $table->timestampTz('data_carregamento')->nullable()->comment('legado: upload_date');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_documentos_anexos_empresa_id ON documentos_anexos (empresa_id)');
        DB::statement('CREATE INDEX ix_documentos_anexos_tipo_documento_id ON documentos_anexos (tipo_documento_id)');

        // ocorrencias_migracao (tabela nova) · 0 linhas reais no backup
        Schema::create('ocorrencias_migracao', function (Blueprint $table) {
            $table->id();
            $table->string('execucao', 40)->nullable()->comment('Identificador da execução do ETL');
            $table->string('tabela_legado', 100)->nullable();
            $table->string('id_legado', 100)->nullable();
            $table->string('tabela_destino', 100)->nullable();
            $table->string('coluna', 100)->nullable();
            $table->string('regra', 40)->nullable()->comment('QUARENTENA, ANULAR_FK, DIARIO_RECUPERACAO, CODIGO_LEGADO, SEMANTICA, NORMALIZACAO, ...');
            $table->string('gravidade', 10)->nullable()->comment('INFO, AVISO, ERRO');
            $table->text('valor_original')->nullable();
            $table->text('valor_final')->nullable();
            $table->text('descricao')->nullable();
            $table->jsonb('dados_originais')->nullable();
            $table->bigInteger('empresa_legado_id')->nullable();
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_ocorrencias_migracao_execucao_tabela_legado ON ocorrencias_migracao (execucao, tabela_legado)');
        DB::statement('CREATE INDEX ix_ocorrencias_migracao_regra ON ocorrencias_migracao (regra)');
        DB::statement('CREATE INDEX ix_ocorrencias_migracao_empresa_legado_id ON ocorrencias_migracao (empresa_legado_id)');

        // quarentena_migracao (tabela nova) · 0 linhas reais no backup
        Schema::create('quarentena_migracao', function (Blueprint $table) {
            $table->id();
            $table->string('execucao', 40)->nullable();
            $table->string('tabela_legado', 100)->nullable();
            $table->string('id_legado', 100)->nullable();
            $table->text('motivo')->nullable();
            $table->jsonb('dados_originais')->nullable();
            $table->bigInteger('empresa_legado_id')->nullable();
            $table->timestampTz('resolvido_em')->nullable();
            $table->string('resolvido_por', 100)->nullable();
            $table->text('resolucao')->nullable();
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_quarentena_migracao_tabela_legado_id_legado ON quarentena_migracao (tabela_legado, id_legado)');
    }

    public function down(): void
    {
        Schema::dropIfExists('quarentena_migracao');
        Schema::dropIfExists('ocorrencias_migracao');
        Schema::dropIfExists('documentos_anexos');
        Schema::dropIfExists('tipos_documento');
        Schema::dropIfExists('regras_internas_ia');
        Schema::dropIfExists('taxas_cambio');
        Schema::dropIfExists('moedas');
        Schema::dropIfExists('centros_custo');
        Schema::dropIfExists('unidades_negocio');
        Schema::dropIfExists('configuracoes_sistema');
    }
};
