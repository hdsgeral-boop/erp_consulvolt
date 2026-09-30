<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo Acréscimos — GERADO por ferramentas/gerador/gerar_esquema.mjs a partir de docs/dicionario/mapa_de_para.json.
 * Não editar à mão: alterar glossario.mjs / esquema_extra.mjs e regenerar.
 * As chaves estrangeiras são criadas em 2026_09_30_900000_criar_chaves_estrangeiras.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ad_settings (legado) -> configuracoes_acrescimos_diferimentos · 2 linhas reais no backup
        Schema::create('configuracoes_acrescimos_diferimentos', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: ad_company_id');
            $table->jsonb('contas')->nullable()->comment('legado: contas');
            $table->bigInteger('diario_id')->nullable()->comment('legado: journal_id');
            $table->integer('prazo_documento_dias')->nullable()->comment('legado: prazo_documento_dias');
            $table->string('atualizado_por', 100)->nullable()->comment('legado: actualizado_por');
            $table->timestampTz('atualizado_em')->nullable()->useCurrent()->comment('legado: actualizado_em');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_configuracoes_acrescimos_diferimentos_empresa_id ON configuracoes_acrescimos_diferimentos (empresa_id)');
        DB::statement('CREATE INDEX ix_configuracoes_acrescimos_diferimentos_diario_id ON configuracoes_acrescimos_diferimentos (diario_id)');

        // ad_items (legado) -> itens_acrescimos_diferimentos · 2 linhas reais no backup
        Schema::create('itens_acrescimos_diferimentos', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: ad_company_id');
            $table->string('tipo', 20)->nullable()->comment('legado: tipo');
            $table->string('natureza', 30)->nullable()->comment('legado: natureza · tipo forçado (inferido: varchar(10))');
            $table->text('descricao')->nullable()->comment('legado: descricao');
            $table->decimal('valor', 15, 2)->nullable()->comment('legado: valor');
            $table->string('conta_resultado', 20)->nullable()->comment('legado: conta_resultado');
            $table->string('conta_balanco', 20)->nullable()->comment('legado: conta_balanco');
            $table->date('data_inicio')->nullable()->comment('legado: data_inicio');
            $table->date('data_fim')->nullable()->comment('legado: data_fim');
            $table->string('reparticao', 10)->nullable()->comment('legado: reparticao · tipo forçado (inferido: varchar(10))');
            $table->date('data_documento')->nullable()->comment('legado: data_documento');
            $table->date('data_limite')->nullable()->comment('legado: data_limite · sem valores reais: tipo a confirmar no código legado; tipo forçado (inferido: text)');
            $table->boolean('documento_em_balanco')->nullable()->comment('legado: documento_em_balanco');
            $table->bigInteger('terceiro_id')->nullable()->comment('legado: third_party_id');
            $table->bigInteger('unidade_negocio_id')->nullable()->comment('legado: business_unit_id');
            $table->bigInteger('centro_custo_id')->nullable()->comment('legado: cost_center_id');
            $table->bigInteger('projeto_id')->nullable()->comment('legado: project_id');
            $table->jsonb('origem')->nullable()->comment('legado: origem');
            $table->text('notas')->nullable()->comment('legado: notas · sem valores reais: tipo a confirmar no código legado');
            $table->string('atualizado_por', 100)->nullable()->comment('legado: actualizado_por');
            $table->string('estado', 20)->nullable()->comment('legado: estado');
            $table->string('criado_por', 100)->nullable()->comment('legado: criado_por');
            $table->jsonb('regularizacao')->nullable()->comment('legado: regularizacao · do código legado js/modules/acrescimos/ad_dados.js:188');
            $table->jsonb('termino')->nullable()->comment('legado: termino · do código legado js/modules/acrescimos/ad_dados.js:201');
            $table->timestampTz('atualizado_em')->nullable()->useCurrent()->comment('legado: actualizado_em');
            $table->timestampTz('criado_em')->nullable()->useCurrent()->comment('legado: criado_em');
        });
        DB::statement('CREATE INDEX ix_itens_acrescimos_diferimentos_empresa_id ON itens_acrescimos_diferimentos (empresa_id)');
        DB::statement('CREATE INDEX ix_itens_acrescimos_diferimentos_terceiro_id ON itens_acrescimos_diferimentos (terceiro_id)');
        DB::statement('CREATE INDEX ix_itens_acrescimos_diferimentos_unidade_negocio_id ON itens_acrescimos_diferimentos (unidade_negocio_id)');
        DB::statement('CREATE INDEX ix_itens_acrescimos_diferimentos_centro_custo_id ON itens_acrescimos_diferimentos (centro_custo_id)');
        DB::statement('CREATE INDEX ix_itens_acrescimos_diferimentos_projeto_id ON itens_acrescimos_diferimentos (projeto_id)');

        // ad_postings (legado) -> periodos_lancamento_acrescimos · 8 linhas reais no backup
        Schema::create('periodos_lancamento_acrescimos', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: ad_company_id');
            $table->bigInteger('item_acrescimo_diferimento_id')->nullable()->comment('legado: item_id');
            $table->string('periodo', 20)->nullable()->comment('legado: periodo');
            $table->string('tipo', 30)->nullable()->comment('legado: tipo');
            $table->decimal('valor', 15, 2)->nullable()->comment('legado: valor · tipos mistos: inteiro=6, decimal=2');
            $table->bigInteger('diario_id')->nullable()->comment('legado: journal_id');
            $table->string('numero_lan', 50)->nullable()->comment('legado: lan_number');
            $table->string('numero_documento', 50)->nullable()->comment('legado: doc_number');
            $table->date('data_documento')->nullable()->comment('legado: doc_date');
            $table->string('estado', 20)->nullable()->comment('legado: estado');
            $table->string('por', 100)->nullable()->comment('legado: por · tipo forçado (inferido: varchar(10))');
            $table->timestampTz('em')->nullable()->comment('legado: em');
            $table->decimal('diferenca', 15, 2)->nullable()->comment('legado: diferenca');
            $table->string('anulado_por', 255)->nullable()->comment('legado: anulado_por · do código legado js/modules/acrescimos/ad_dados.js:344');
            $table->timestampTz('anulado_em')->nullable()->comment('legado: anulado_em · do código legado js/modules/acrescimos/ad_dados.js:344');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE UNIQUE INDEX uq_periodos_lancamento_acrescimos_item_acr_tipo_periodo ON periodos_lancamento_acrescimos (item_acrescimo_diferimento_id, tipo, periodo) WHERE estado = \'CONTABILIZADO\'');
        DB::statement('CREATE INDEX ix_periodos_lancamento_acrescimos_empresa_id ON periodos_lancamento_acrescimos (empresa_id)');
        DB::statement('CREATE INDEX ix_periodos_lancamento_acrescimos_item_acrescimo_diferimento_id ON periodos_lancamento_acrescimos (item_acrescimo_diferimento_id)');
        DB::statement('CREATE INDEX ix_periodos_lancamento_acrescimos_diario_id ON periodos_lancamento_acrescimos (diario_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('periodos_lancamento_acrescimos');
        Schema::dropIfExists('itens_acrescimos_diferimentos');
        Schema::dropIfExists('configuracoes_acrescimos_diferimentos');
    }
};
