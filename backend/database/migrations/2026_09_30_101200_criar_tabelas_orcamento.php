<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo Orçamento — GERADO por ferramentas/gerador/gerar_esquema.mjs a partir de docs/dicionario/mapa_de_para.json.
 * Não editar à mão: alterar glossario.mjs / esquema_extra.mjs e regenerar.
 * As chaves estrangeiras são criadas em 2026_09_30_900000_criar_chaves_estrangeiras.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        // orc_rubrics (legado) -> rubricas_orcamentais · 75 linhas reais no backup · eliminação lógica
        Schema::create('rubricas_orcamentais', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: orc_company_id');
            $table->string('tipo', 20)->nullable()->comment('legado: tipo · código normalizado ∈ {EXPLORACAO, TESOURARIA}; texto original em tipo_original');
            $table->string('tipo_original', 100)->nullable()->comment('legado: tipo · texto exacto do legado');
            $table->string('codigo', 50)->nullable()->comment('legado: codigo');
            $table->string('nome', 255)->nullable()->comment('legado: nome');
            $table->string('natureza', 20)->nullable()->comment('legado: natureza · código normalizado ∈ {PROVEITO, CUSTO, RECEBIMENTO, PAGAMENTO}; texto original em natureza_original');
            $table->string('natureza_original', 100)->nullable()->comment('legado: natureza · texto exacto do legado');
            $table->string('grupo', 50)->nullable()->comment('legado: grupo');
            $table->jsonb('contas')->nullable()->comment('legado: contas');
            $table->integer('ordem')->nullable()->comment('legado: ordem');
            $table->boolean('ativo')->nullable()->comment('legado: activo');
            $table->text('descricao')->nullable()->comment('legado: descricao · sem valores reais: tipo a confirmar no código legado');
            $table->jsonb('controlo')->nullable()->comment('legado: controlo · do código legado js/modules/orcamento/orcamento_dados.js:103');
            $table->string('indutor', 255)->nullable()->comment('legado: driver · do código legado js/modules/orcamento/orcamento_dados.js:105');
            $table->decimal('cambial_pct', 9, 4)->nullable()->comment('legado: cambial_pct · do código legado js/modules/orcamento/orcamento_dados.js:106');
            $table->decimal('variavel_pct', 9, 4)->nullable()->comment('legado: variavel_pct · do código legado js/modules/orcamento/orcamento_dados.js:107');
            $table->timestampTz('atualizado_em')->nullable()->useCurrent()->comment('legado: actualizado_em');
            $table->timestampTz('criado_em')->nullable()->useCurrent()->comment('legado: criado_em');
            $table->timestampTz('eliminado_em')->nullable();
        });
        DB::statement('CREATE UNIQUE INDEX uq_rubricas_orcamentais_empresa_id_codigo ON rubricas_orcamentais (empresa_id, codigo) WHERE eliminado_em IS NULL');
        DB::statement('ALTER TABLE rubricas_orcamentais ADD CONSTRAINT ck_rubricas_orcamentais_tipo CHECK (tipo IS NULL OR tipo IN (\'EXPLORACAO\',\'TESOURARIA\'))');
        DB::statement('ALTER TABLE rubricas_orcamentais ADD CONSTRAINT ck_rubricas_orcamentais_natureza CHECK (natureza IS NULL OR natureza IN (\'PROVEITO\',\'CUSTO\',\'RECEBIMENTO\',\'PAGAMENTO\'))');

        // orc_budgets (legado) -> orcamentos_anuais · 8 linhas reais no backup
        Schema::create('orcamentos_anuais', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: orc_company_id');
            $table->integer('ano')->nullable()->comment('legado: ano');
            $table->string('tipo', 20)->nullable()->comment('legado: tipo · código normalizado ∈ {EXPLORACAO, TESOURARIA}; texto original em tipo_original');
            $table->string('tipo_original', 100)->nullable()->comment('legado: tipo · texto exacto do legado');
            $table->bigInteger('unidade_negocio_id')->nullable()->comment('legado: business_unit_id');
            $table->bigInteger('centro_custo_id')->nullable()->comment('legado: cost_center_id');
            $table->string('nome', 255)->nullable()->comment('legado: nome');
            $table->text('descricao')->nullable()->comment('legado: descricao · sem valores reais: tipo a confirmar no código legado');
            $table->integer('versao')->nullable()->comment('legado: versao');
            $table->bigInteger('versao_origem_id')->nullable()->comment('legado: versao_origem_id');
            $table->string('estado', 20)->nullable()->comment('legado: status · código normalizado ∈ {RASCUNHO, SUBMETIDO, APROVADO, SUBSTITUIDO}; texto original em estado_original');
            $table->string('estado_original', 100)->nullable()->comment('legado: status · texto exacto do legado');
            $table->string('criado_por', 100)->nullable()->comment('legado: criado_por');
            $table->jsonb('rejeicoes')->nullable()->comment('legado: rejeicoes');
            $table->string('atualizado_por', 100)->nullable()->comment('legado: actualizado_por');
            $table->string('abordagem', 20)->nullable()->comment('legado: abordagem');
            $table->string('dimensao_filhos', 10)->nullable()->comment('legado: dimensao_filhos');
            $table->bigInteger('projeto_id')->nullable()->comment('legado: project_id');
            $table->string('metodo', 20)->nullable()->comment('legado: metodo');
            $table->string('origem', 30)->nullable()->comment('legado: origem · sem valores reais: tipo a confirmar no código legado; tipo forçado (inferido: text)');
            $table->decimal('crescimento_proveitos_pct', 9, 4)->nullable()->comment('legado: crescimento_proveitos_pct');
            $table->decimal('crescimento_custos_pct', 9, 4)->nullable()->comment('legado: crescimento_custos_pct');
            $table->decimal('inflacao_pct', 9, 4)->nullable()->comment('legado: inflacao_pct');
            $table->string('responsavel', 100)->nullable()->comment('legado: responsavel · sem valores reais: tipo a confirmar no código legado; tipo forçado (inferido: text)');
            $table->bigInteger('orcamento_pai_id')->nullable()->comment('legado: pai_id');
            $table->decimal('saldo_inicial', 15, 2)->nullable()->comment('legado: saldo_inicial · do código legado js/modules/orcamento/orcamento_dados.js:234');
            $table->string('submetido_por', 255)->nullable()->comment('legado: submetido_por · do código legado js/modules/orcamento/orcamento_dados.js:250');
            $table->timestampTz('submetido_em')->nullable()->comment('legado: submetido_em · do código legado js/modules/orcamento/orcamento_dados.js:250');
            $table->bigInteger('substituido_por_id')->nullable()->comment('legado: substituido_por_id · do código legado js/modules/orcamento/orcamento_dados.js:259');
            $table->timestampTz('substituido_em')->nullable()->comment('legado: substituido_em · do código legado js/modules/orcamento/orcamento_dados.js:259');
            $table->string('aprovado_por', 255)->nullable()->comment('legado: aprovado_por · do código legado js/modules/orcamento/orcamento_dados.js:260');
            $table->timestampTz('aprovado_em')->nullable()->comment('legado: aprovado_em · do código legado js/modules/orcamento/orcamento_dados.js:260');
            $table->date('prazo_contributo')->nullable()->comment('legado: prazo_contributo · do código legado js/modules/orcamento/orcamento_planeamento.js:134');
            $table->timestampTz('consolidado_em')->nullable()->comment('legado: consolidado_em · do código legado js/modules/orcamento/orcamento_planeamento.js:162');
            $table->string('consolidado_por', 255)->nullable()->comment('legado: consolidado_por · do código legado js/modules/orcamento/orcamento_planeamento.js:162');
            $table->jsonb('consolidado_ids')->nullable()->comment('legado: consolidado_ids · do código legado js/modules/orcamento/orcamento_planeamento.js:162');
            $table->timestampTz('criado_em')->nullable()->useCurrent()->comment('legado: criado_em');
            $table->timestampTz('atualizado_em')->nullable()->useCurrent()->comment('legado: actualizado_em');
        });
        DB::statement('CREATE INDEX ix_orcamentos_anuais_empresa_id ON orcamentos_anuais (empresa_id)');
        DB::statement('CREATE INDEX ix_orcamentos_anuais_unidade_negocio_id ON orcamentos_anuais (unidade_negocio_id)');
        DB::statement('CREATE INDEX ix_orcamentos_anuais_centro_custo_id ON orcamentos_anuais (centro_custo_id)');
        DB::statement('CREATE INDEX ix_orcamentos_anuais_versao_origem_id ON orcamentos_anuais (versao_origem_id)');
        DB::statement('CREATE INDEX ix_orcamentos_anuais_projeto_id ON orcamentos_anuais (projeto_id)');
        DB::statement('CREATE INDEX ix_orcamentos_anuais_orcamento_pai_id ON orcamentos_anuais (orcamento_pai_id)');
        DB::statement('CREATE INDEX ix_orcamentos_anuais_substituido_por_id ON orcamentos_anuais (substituido_por_id)');
        DB::statement('ALTER TABLE orcamentos_anuais ADD CONSTRAINT ck_orcamentos_anuais_tipo CHECK (tipo IS NULL OR tipo IN (\'EXPLORACAO\',\'TESOURARIA\'))');
        DB::statement('ALTER TABLE orcamentos_anuais ADD CONSTRAINT ck_orcamentos_anuais_estado CHECK (estado IS NULL OR estado IN (\'RASCUNHO\',\'SUBMETIDO\',\'APROVADO\',\'SUBSTITUIDO\'))');

        // orc_lines (legado) -> linhas_orcamento · 6 linhas reais no backup
        Schema::create('linhas_orcamento', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: orc_company_id');
            $table->bigInteger('orcamento_anual_id')->nullable()->comment('legado: budget_id');
            $table->bigInteger('rubrica_orcamental_id')->nullable()->comment('legado: rubric_id');
            $table->jsonb('valores')->nullable()->comment('legado: valores');
            $table->decimal('total', 15, 2)->nullable()->comment('legado: total · tipos mistos: inteiro=5, decimal=1');
            $table->text('notas')->nullable()->comment('legado: notas');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_linhas_orcamento_empresa_id ON linhas_orcamento (empresa_id)');
        DB::statement('CREATE INDEX ix_linhas_orcamento_orcamento_anual_id ON linhas_orcamento (orcamento_anual_id)');
        DB::statement('CREATE INDEX ix_linhas_orcamento_rubrica_orcamental_id ON linhas_orcamento (rubrica_orcamental_id)');

        // orc_forecasts (legado) -> previsoes_orcamentais · 0 linhas reais no backup
        Schema::create('previsoes_orcamentais', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: orc_company_id · do código legado js/modules/orcamento/orcamento_planeamento.js:234');
            $table->string('tipo', 20)->nullable()->comment('legado: tipo · do código legado js/modules/orcamento/orcamento_planeamento.js:226');
            $table->bigInteger('unidade_negocio_id')->nullable()->comment('legado: business_unit_id · do código legado js/modules/orcamento/orcamento_planeamento.js:226');
            $table->bigInteger('centro_custo_id')->nullable()->comment('legado: cost_center_id · do código legado js/modules/orcamento/orcamento_planeamento.js:226');
            $table->bigInteger('projeto_id')->nullable()->comment('legado: project_id · do código legado js/modules/orcamento/orcamento_planeamento.js:226');
            $table->string('nome', 255)->nullable()->comment('legado: nome · do código legado js/modules/orcamento/orcamento_planeamento.js:234');
            $table->string('mes_referencia', 7)->nullable()->comment('legado: mes_ref · do código legado js/modules/orcamento/orcamento_planeamento.js:234');
            $table->integer('revisao')->nullable()->comment('legado: revisao · do código legado js/modules/orcamento/orcamento_planeamento.js:234');
            $table->string('estado', 20)->nullable()->comment('legado: status · do código legado js/modules/orcamento/orcamento_planeamento.js:234');
            $table->string('metodo', 20)->nullable()->comment('legado: metodo · do código legado js/modules/orcamento/orcamento_planeamento.js:234');
            $table->decimal('crescimento_pct', 9, 4)->nullable()->comment('legado: crescimento_pct · do código legado js/modules/orcamento/orcamento_planeamento.js:234');
            $table->text('notas')->nullable()->comment('legado: notas · do código legado js/modules/orcamento/orcamento_planeamento.js:234');
            $table->string('criado_por', 100)->nullable()->comment('legado: criado_por · do código legado js/modules/orcamento/orcamento_planeamento.js:234');
            $table->bigInteger('revisao_origem_id')->nullable()->comment('legado: revisao_origem_id · do código legado js/modules/orcamento/orcamento_planeamento.js:255');
            $table->string('atualizado_por', 100)->nullable()->comment('legado: actualizado_por · do código legado js/modules/orcamento/orcamento_planeamento.js:272');
            $table->string('publicado_por', 100)->nullable()->comment('legado: publicado_por · do código legado js/modules/orcamento/orcamento_planeamento.js:278');
            $table->timestampTz('publicado_em')->nullable()->comment('legado: publicado_em · do código legado js/modules/orcamento/orcamento_planeamento.js:278');
            $table->timestampTz('criado_em')->nullable()->useCurrent()->comment('legado: criado_em · do código legado js/modules/orcamento/orcamento_planeamento.js:234');
            $table->timestampTz('atualizado_em')->nullable()->useCurrent()->comment('legado: actualizado_em · do código legado js/modules/orcamento/orcamento_planeamento.js:272');
        });
        DB::statement('CREATE INDEX ix_previsoes_orcamentais_empresa_id ON previsoes_orcamentais (empresa_id)');
        DB::statement('CREATE INDEX ix_previsoes_orcamentais_unidade_negocio_id ON previsoes_orcamentais (unidade_negocio_id)');
        DB::statement('CREATE INDEX ix_previsoes_orcamentais_centro_custo_id ON previsoes_orcamentais (centro_custo_id)');
        DB::statement('CREATE INDEX ix_previsoes_orcamentais_projeto_id ON previsoes_orcamentais (projeto_id)');
        DB::statement('CREATE INDEX ix_previsoes_orcamentais_revisao_origem_id ON previsoes_orcamentais (revisao_origem_id)');

        // orc_forecast_lines (legado) -> linhas_previsao_orcamental · 0 linhas reais no backup
        Schema::create('linhas_previsao_orcamental', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: orc_company_id · do código legado js/modules/orcamento/orcamento_planeamento.js:235');
            $table->bigInteger('previsao_orcamental_id')->nullable()->comment('legado: forecast_id · do código legado js/modules/orcamento/orcamento_planeamento.js:235');
            $table->bigInteger('rubrica_orcamental_id')->nullable()->comment('legado: rubric_id · do código legado js/modules/orcamento/orcamento_planeamento.js:235');
            $table->jsonb('valores')->nullable()->comment('legado: valores · do código legado js/modules/orcamento/orcamento_planeamento.js:235');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_linhas_previsao_orcamental_empresa_id ON linhas_previsao_orcamental (empresa_id)');
        DB::statement('CREATE INDEX ix_linhas_previsao_orcamental_previsao_orcamental_id ON linhas_previsao_orcamental (previsao_orcamental_id)');
        DB::statement('CREATE INDEX ix_linhas_previsao_orcamental_rubrica_orcamental_id ON linhas_previsao_orcamental (rubrica_orcamental_id)');

        // orc_scenarios (legado) -> cenarios_orcamentais · 0 linhas reais no backup
        Schema::create('cenarios_orcamentais', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: orc_company_id · do código legado js/modules/orcamento/orcamento_planeamento.js:386');
            $table->bigInteger('orcamento_anual_id')->nullable()->comment('legado: budget_id · do código legado js/modules/orcamento/orcamento_planeamento.js:386');
            $table->string('nome', 255)->nullable()->comment('legado: nome · do código legado js/modules/orcamento/orcamento_planeamento.js:386');
            $table->string('tipo', 20)->nullable()->comment('legado: tipo · do código legado js/modules/orcamento/orcamento_planeamento.js:386');
            $table->jsonb('variaveis')->nullable()->comment('legado: variaveis · do código legado js/modules/orcamento/orcamento_planeamento.js:386');
            $table->jsonb('ajustes')->nullable()->comment('legado: ajustes · do código legado js/modules/orcamento/orcamento_planeamento.js:386');
            $table->text('notas')->nullable()->comment('legado: notas · do código legado js/modules/orcamento/orcamento_planeamento.js:386');
            $table->string('atualizado_por', 255)->nullable()->comment('legado: actualizado_por · do código legado js/modules/orcamento/orcamento_planeamento.js:386');
            $table->string('criado_por', 255)->nullable()->comment('legado: criado_por · do código legado js/modules/orcamento/orcamento_planeamento.js:388');
            $table->timestampTz('atualizado_em')->nullable()->useCurrent()->comment('legado: actualizado_em · do código legado js/modules/orcamento/orcamento_planeamento.js:386');
            $table->timestampTz('criado_em')->nullable()->useCurrent()->comment('legado: criado_em · do código legado js/modules/orcamento/orcamento_planeamento.js:388');
        });
        DB::statement('CREATE INDEX ix_cenarios_orcamentais_empresa_id ON cenarios_orcamentais (empresa_id)');
        DB::statement('CREATE INDEX ix_cenarios_orcamentais_orcamento_anual_id ON cenarios_orcamentais (orcamento_anual_id)');

        // orc_excess_requests (legado) -> pedidos_extrapolacao_orcamento · 0 linhas reais no backup
        Schema::create('pedidos_extrapolacao_orcamento', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: orc_company_id · do código legado js/modules/orcamento/orcamento_planeamento.js:537');
            $table->string('estado', 20)->nullable()->comment('legado: status · do código legado js/modules/orcamento/orcamento_planeamento.js:537');
            $table->bigInteger('rubrica_orcamental_id')->nullable()->comment('legado: rubric_id · do código legado js/modules/orcamento/orcamento_planeamento.js:537');
            $table->bigInteger('orcamento_anual_id')->nullable()->comment('legado: budget_id · do código legado js/modules/orcamento/orcamento_planeamento.js:537');
            $table->string('origem', 30)->nullable()->comment('legado: origem · do código legado js/modules/orcamento/orcamento_planeamento.js:537');
            $table->string('documento', 100)->nullable()->comment('legado: doc · do código legado js/modules/orcamento/orcamento_planeamento.js:537');
            $table->string('chave_documento', 150)->nullable()->comment('legado: chave_doc · do código legado js/modules/orcamento/orcamento_planeamento.js:537');
            $table->date('data_documento')->nullable()->comment('legado: data_doc · do código legado js/modules/orcamento/orcamento_planeamento.js:537');
            $table->decimal('valor', 15, 2)->nullable()->comment('legado: valor · do código legado js/modules/orcamento/orcamento_planeamento.js:537');
            $table->decimal('valor_orcado', 15, 2)->nullable()->comment('legado: orcado · do código legado js/modules/orcamento/orcamento_planeamento.js:537');
            $table->decimal('valor_consumido', 15, 2)->nullable()->comment('legado: consumido · do código legado js/modules/orcamento/orcamento_planeamento.js:537');
            $table->decimal('percentagem', 9, 4)->nullable()->comment('legado: pct · do código legado js/modules/orcamento/orcamento_planeamento.js:537');
            $table->decimal('valor_excesso', 15, 2)->nullable()->comment('legado: excesso · do código legado js/modules/orcamento/orcamento_planeamento.js:537');
            $table->text('motivo')->nullable()->comment('legado: motivo · do código legado js/modules/orcamento/orcamento_planeamento.js:537');
            $table->string('pedido_por', 100)->nullable()->comment('legado: pedido_por · do código legado js/modules/orcamento/orcamento_planeamento.js:537');
            $table->timestampTz('pedido_em')->nullable()->comment('legado: pedido_em · do código legado js/modules/orcamento/orcamento_planeamento.js:537');
            $table->string('decidido_por', 100)->nullable()->comment('legado: decidido_por · do código legado js/modules/orcamento/orcamento_planeamento.js:545');
            $table->timestampTz('decidido_em')->nullable()->comment('legado: decidido_em · do código legado js/modules/orcamento/orcamento_planeamento.js:545');
            $table->text('nota_decisao')->nullable()->comment('legado: nota_decisao · do código legado js/modules/orcamento/orcamento_planeamento.js:545');
            $table->boolean('autoaprovado')->nullable()->comment('legado: autoaprovado · do código legado js/modules/orcamento/orcamento_planeamento.js:545');
            $table->timestampTz('utilizado_em')->nullable()->comment('legado: utilizado_em · do código legado js/modules/orcamento/orcamento_planeamento_ui.js:112');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_pedidos_extrapolacao_orcamento_empresa_id ON pedidos_extrapolacao_orcamento (empresa_id)');
        DB::statement('CREATE INDEX ix_pedidos_extrapolacao_orcamento_rubrica_orcamental_id ON pedidos_extrapolacao_orcamento (rubrica_orcamental_id)');
        DB::statement('CREATE INDEX ix_pedidos_extrapolacao_orcamento_orcamento_anual_id ON pedidos_extrapolacao_orcamento (orcamento_anual_id)');

        // orc_alert_log (legado) -> logs_alertas_orcamentais · 0 linhas reais no backup
        Schema::create('logs_alertas_orcamentais', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: orc_company_id · do código legado js/modules/orcamento/orcamento_planeamento.js:527');
            $table->timestampTz('em')->nullable()->comment('legado: em · do código legado js/modules/orcamento/orcamento_planeamento.js:527');
            $table->string('por', 100)->nullable()->comment('legado: por · do código legado js/modules/orcamento/orcamento_planeamento.js:527');
            $table->string('origem', 30)->nullable()->comment('legado: origem · do código legado js/modules/orcamento/orcamento_planeamento.js:527');
            $table->string('documento', 100)->nullable()->comment('legado: doc · do código legado js/modules/orcamento/orcamento_planeamento.js:527');
            $table->bigInteger('rubrica_orcamental_id')->nullable()->comment('legado: rubric_id · do código legado js/modules/orcamento/orcamento_planeamento.js:527');
            $table->bigInteger('orcamento_anual_id')->nullable()->comment('legado: budget_id · do código legado js/modules/orcamento/orcamento_planeamento.js:527');
            $table->decimal('percentagem', 9, 4)->nullable()->comment('legado: pct · do código legado js/modules/orcamento/orcamento_planeamento.js:527');
            $table->string('estado', 20)->nullable()->comment('legado: estado · do código legado js/modules/orcamento/orcamento_planeamento.js:527');
            $table->string('acao', 30)->nullable()->comment('legado: accao · do código legado js/modules/orcamento/orcamento_planeamento.js:527');
            $table->decimal('valor', 15, 2)->nullable()->comment('legado: valor · do código legado js/modules/orcamento/orcamento_planeamento.js:527');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_logs_alertas_orcamentais_empresa_id ON logs_alertas_orcamentais (empresa_id)');
        DB::statement('CREATE INDEX ix_logs_alertas_orcamentais_rubrica_orcamental_id ON logs_alertas_orcamentais (rubrica_orcamental_id)');
        DB::statement('CREATE INDEX ix_logs_alertas_orcamentais_orcamento_anual_id ON logs_alertas_orcamentais (orcamento_anual_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('logs_alertas_orcamentais');
        Schema::dropIfExists('pedidos_extrapolacao_orcamento');
        Schema::dropIfExists('cenarios_orcamentais');
        Schema::dropIfExists('linhas_previsao_orcamental');
        Schema::dropIfExists('previsoes_orcamentais');
        Schema::dropIfExists('linhas_orcamento');
        Schema::dropIfExists('orcamentos_anuais');
        Schema::dropIfExists('rubricas_orcamentais');
    }
};
