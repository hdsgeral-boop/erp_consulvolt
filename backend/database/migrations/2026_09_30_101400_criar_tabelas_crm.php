<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo CRM — GERADO por ferramentas/gerador/gerar_esquema.mjs a partir de docs/dicionario/mapa_de_para.json.
 * Não editar à mão: alterar glossario.mjs / esquema_extra.mjs e regenerar.
 * As chaves estrangeiras são criadas em 2026_09_30_900000_criar_chaves_estrangeiras.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        // crm_settings (legado) -> configuracoes_crm · 0 linhas reais no backup
        Schema::create('configuracoes_crm', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: crm_company_id · do código legado js/modules/crm/crm_dados.js:53');
            $table->text('motivos_perda')->nullable()->comment('legado: motivos_perda · do código legado js/modules/crm/crm_dados.js:53');
            $table->string('origens', 255)->nullable()->comment('legado: origens · do código legado js/modules/crm/crm_dados.js:53');
            $table->integer('dias_sem_atividade')->nullable()->comment('legado: dias_sem_actividade · do código legado js/modules/crm/crm_dados.js:53');
            $table->integer('prazo_pagamento_dias')->nullable()->comment('legado: prazo_pagamento_dias · do código legado js/modules/crm/crm_dados.js:53');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_configuracoes_crm_empresa_id ON configuracoes_crm (empresa_id)');

        // crm_pipelines (legado) -> funis_vendas_crm · 2 linhas reais no backup
        Schema::create('funis_vendas_crm', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: crm_company_id');
            $table->string('nome', 255)->nullable()->comment('legado: nome');
            $table->integer('ordem')->nullable()->comment('legado: ordem');
            $table->boolean('ativo')->nullable()->comment('legado: activo');
            $table->jsonb('etapas')->nullable()->comment('legado: etapas');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_funis_vendas_crm_empresa_id ON funis_vendas_crm (empresa_id)');

        // crm_accounts (legado) -> contas_crm · 5 linhas reais no backup · eliminação lógica
        Schema::create('contas_crm', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: crm_company_id');
            $table->string('tipo', 20)->nullable()->comment('legado: tipo');
            $table->bigInteger('terceiro_id')->nullable()->comment('legado: third_party_id');
            $table->string('nome', 255)->nullable()->comment('legado: nome');
            $table->string('nif', 30)->nullable()->comment('legado: nif');
            $table->string('email', 150)->nullable()->comment('legado: email');
            $table->string('telefone', 50)->nullable()->comment('legado: telefone · tipos mistos: string=1, string_inteiro=1');
            $table->text('morada')->nullable()->comment('legado: morada · sem valores reais: tipo a confirmar no código legado');
            $table->string('origem', 22)->nullable()->comment('legado: origem · código normalizado ∈ {RECOMENDACAO, CLIENTE_EXISTENTE, SITE, CAMPANHA, OUTRO}; texto original em origem_original');
            $table->string('origem_original', 100)->nullable()->comment('legado: origem · texto exacto do legado');
            $table->string('responsavel', 10)->nullable()->comment('legado: responsavel');
            $table->string('criado_por', 10)->nullable()->comment('legado: criado_por');
            $table->text('setor')->nullable()->comment('legado: sector · sem valores reais: tipo a confirmar no código legado');
            $table->text('website')->nullable()->comment('legado: website · sem valores reais: tipo a confirmar no código legado');
            $table->text('notas')->nullable()->comment('legado: notas · sem valores reais: tipo a confirmar no código legado');
            $table->timestampTz('criado_em')->nullable()->useCurrent()->comment('legado: criado_em');
            $table->timestampTz('atualizado_em')->nullable()->useCurrent()->comment('legado: actualizado_em');
            $table->timestampTz('eliminado_em')->nullable();
        });
        DB::statement('CREATE INDEX ix_contas_crm_empresa_id ON contas_crm (empresa_id)');
        DB::statement('CREATE INDEX ix_contas_crm_terceiro_id ON contas_crm (terceiro_id)');
        DB::statement('ALTER TABLE contas_crm ADD CONSTRAINT ck_contas_crm_origem CHECK (origem IS NULL OR origem IN (\'RECOMENDACAO\',\'CLIENTE_EXISTENTE\',\'SITE\',\'CAMPANHA\',\'OUTRO\'))');

        // crm_contacts (legado) -> contactos_crm · 4 linhas reais no backup · eliminação lógica
        Schema::create('contactos_crm', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: crm_company_id');
            $table->bigInteger('conta_crm_id')->nullable()->comment('legado: account_id');
            $table->string('nome', 255)->nullable()->comment('legado: nome');
            $table->string('cargo', 10)->nullable()->comment('legado: cargo');
            $table->string('email', 150)->nullable()->comment('legado: email');
            $table->string('telefone', 50)->nullable()->comment('legado: telefone · tipos mistos: string=2, string_inteiro=1');
            $table->boolean('principal')->nullable()->comment('legado: principal');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
            $table->timestampTz('eliminado_em')->nullable();
        });
        DB::statement('CREATE INDEX ix_contactos_crm_empresa_id ON contactos_crm (empresa_id)');
        DB::statement('CREATE INDEX ix_contactos_crm_conta_crm_id ON contactos_crm (conta_crm_id)');

        // crm_opportunities (legado) -> oportunidades_venda_crm · 5 linhas reais no backup
        Schema::create('oportunidades_venda_crm', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: crm_company_id');
            $table->bigInteger('funil_vendas_crm_id')->nullable()->comment('legado: pipeline_id');
            $table->bigInteger('conta_crm_id')->nullable()->comment('legado: account_id');
            $table->bigInteger('contacto_crm_id')->nullable()->comment('legado: contact_id');
            $table->string('titulo', 255)->nullable()->comment('legado: titulo');
            $table->decimal('valor', 15, 2)->nullable()->comment('legado: valor');
            $table->decimal('probabilidade', 9, 4)->nullable()->comment('legado: probabilidade');
            $table->date('data_fecho_prevista')->nullable()->comment('legado: data_fecho_prevista');
            $table->string('responsavel', 10)->nullable()->comment('legado: responsavel');
            $table->string('origem', 22)->nullable()->comment('legado: origem · código normalizado ∈ {RECOMENDACAO, CLIENTE_EXISTENTE, SITE, CAMPANHA, OUTRO}; texto original em origem_original');
            $table->string('origem_original', 100)->nullable()->comment('legado: origem · texto exacto do legado');
            $table->text('notas')->nullable()->comment('legado: notas');
            $table->jsonb('itens')->nullable()->comment('legado: itens');
            $table->string('etapa_codigo', 20)->nullable()->comment('legado: etapa_id');
            $table->string('estado', 20)->nullable()->comment('legado: estado');
            $table->jsonb('historico')->nullable()->comment('legado: historico');
            $table->timestampTz('etapa_desde')->nullable()->comment('legado: etapa_desde');
            $table->jsonb('vendas')->nullable()->comment('legado: vendas');
            $table->string('criado_por', 10)->nullable()->comment('legado: criado_por');
            $table->timestampTz('fechado_em')->nullable()->comment('legado: fechada_em');
            $table->text('motivo_perda')->nullable()->comment('legado: motivo_perda');
            $table->text('concorrente')->nullable()->comment('legado: concorrente · sem valores reais: tipo a confirmar no código legado');
            $table->text('notas_perda')->nullable()->comment('legado: notas_perda · sem valores reais: tipo a confirmar no código legado');
            $table->timestampTz('ultima_atividade_em')->nullable()->comment('legado: ultima_actividade_em');
            $table->timestampTz('atualizado_em')->nullable()->useCurrent()->comment('legado: actualizado_em');
            $table->timestampTz('criado_em')->nullable()->useCurrent()->comment('legado: criada_em');
        });
        DB::statement('CREATE INDEX ix_oportunidades_venda_crm_empresa_id ON oportunidades_venda_crm (empresa_id)');
        DB::statement('CREATE INDEX ix_oportunidades_venda_crm_funil_vendas_crm_id ON oportunidades_venda_crm (funil_vendas_crm_id)');
        DB::statement('CREATE INDEX ix_oportunidades_venda_crm_conta_crm_id ON oportunidades_venda_crm (conta_crm_id)');
        DB::statement('CREATE INDEX ix_oportunidades_venda_crm_contacto_crm_id ON oportunidades_venda_crm (contacto_crm_id)');
        DB::statement('ALTER TABLE oportunidades_venda_crm ADD CONSTRAINT ck_oportunidades_venda_crm_origem CHECK (origem IS NULL OR origem IN (\'RECOMENDACAO\',\'CLIENTE_EXISTENTE\',\'SITE\',\'CAMPANHA\',\'OUTRO\'))');

        // crm_activities (legado) -> atividades_comerciais_crm · 45 linhas reais no backup
        Schema::create('atividades_comerciais_crm', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: crm_company_id');
            $table->bigInteger('oportunidade_crm_id')->nullable()->comment('legado: opportunity_id');
            $table->bigInteger('conta_crm_id')->nullable()->comment('legado: account_id');
            $table->bigInteger('contacto_crm_id')->nullable()->comment('legado: contact_id');
            $table->string('tipo', 20)->nullable()->comment('legado: tipo');
            $table->string('titulo', 255)->nullable()->comment('legado: titulo');
            $table->text('descricao')->nullable()->comment('legado: descricao');
            $table->date('data_prevista')->nullable()->comment('legado: data_prevista');
            $table->boolean('concluida')->nullable()->comment('legado: concluida');
            $table->string('responsavel', 10)->nullable()->comment('legado: responsavel');
            $table->boolean('automatica')->nullable()->comment('legado: automatica');
            $table->bigInteger('modelo_email_crm_id')->nullable()->comment('legado: modelo_id');
            $table->string('criado_por', 20)->nullable()->comment('legado: criado_por');
            $table->timestampTz('concluida_em')->nullable()->comment('legado: concluida_em');
            $table->string('resultado', 50)->nullable()->comment('legado: resultado');
            $table->string('concluida_por', 10)->nullable()->comment('legado: concluida_por');
            $table->bigInteger('sequencia_campanha_id')->nullable()->comment('legado: sequencia_id · do código legado js/modules/crm/crm_dados.js:222');
            $table->timestampTz('criado_em')->nullable()->useCurrent()->comment('legado: criado_em');
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_atividades_comerciais_crm_empresa_id ON atividades_comerciais_crm (empresa_id)');
        DB::statement('CREATE INDEX ix_atividades_comerciais_crm_oportunidade_crm_id ON atividades_comerciais_crm (oportunidade_crm_id)');
        DB::statement('CREATE INDEX ix_atividades_comerciais_crm_conta_crm_id ON atividades_comerciais_crm (conta_crm_id)');
        DB::statement('CREATE INDEX ix_atividades_comerciais_crm_contacto_crm_id ON atividades_comerciais_crm (contacto_crm_id)');
        DB::statement('CREATE INDEX ix_atividades_comerciais_crm_modelo_email_crm_id ON atividades_comerciais_crm (modelo_email_crm_id)');
        DB::statement('CREATE INDEX ix_atividades_comerciais_crm_sequencia_campanha_id ON atividades_comerciais_crm (sequencia_campanha_id)');

        // crm_templates (legado) -> modelos_email_crm · 6 linhas reais no backup
        Schema::create('modelos_email_crm', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: crm_company_id');
            $table->string('nome', 255)->nullable()->comment('legado: nome');
            $table->string('assunto', 100)->nullable()->comment('legado: assunto');
            $table->text('corpo')->nullable()->comment('legado: corpo');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_modelos_email_crm_empresa_id ON modelos_email_crm (empresa_id)');

        // crm_sequences (legado) -> sequencias_campanhas_crm · 0 linhas reais no backup
        Schema::create('sequencias_campanhas_crm', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: crm_company_id · do código legado js/modules/crm/crm_dados.js:368');
            $table->string('nome', 255)->nullable()->comment('legado: nome · do código legado js/modules/crm/crm_dados.js:368');
            $table->bigInteger('funil_vendas_crm_id')->nullable()->comment('legado: pipeline_id · do código legado js/modules/crm/crm_dados.js:368');
            $table->string('etapa_codigo', 255)->nullable()->comment('legado: etapa_id · do código legado js/modules/crm/crm_dados.js:368');
            $table->boolean('ativo')->nullable()->comment('legado: activo · do código legado js/modules/crm/crm_dados.js:368');
            $table->string('passos', 255)->nullable()->comment('legado: passos · do código legado js/modules/crm/crm_dados.js:368');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_sequencias_campanhas_crm_empresa_id ON sequencias_campanhas_crm (empresa_id)');
        DB::statement('CREATE INDEX ix_sequencias_campanhas_crm_funil_vendas_crm_id ON sequencias_campanhas_crm (funil_vendas_crm_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('sequencias_campanhas_crm');
        Schema::dropIfExists('modelos_email_crm');
        Schema::dropIfExists('atividades_comerciais_crm');
        Schema::dropIfExists('oportunidades_venda_crm');
        Schema::dropIfExists('contactos_crm');
        Schema::dropIfExists('contas_crm');
        Schema::dropIfExists('funis_vendas_crm');
        Schema::dropIfExists('configuracoes_crm');
    }
};
