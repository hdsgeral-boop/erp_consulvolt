<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo RH — GERADO por ferramentas/gerador/gerar_esquema.mjs a partir de docs/dicionario/mapa_de_para.json.
 * Não editar à mão: alterar glossario.mjs / esquema_extra.mjs e regenerar.
 * As chaves estrangeiras são criadas em 2026_09_30_900000_criar_chaves_estrangeiras.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        // employees (legado) -> colaboradores · 133 linhas reais no backup · eliminação lógica
        Schema::create('colaboradores', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->string('nome_completo', 255)->nullable()->comment('legado: name');
            $table->string('nif', 30)->nullable()->comment('legado: nif · tipos mistos: string=127, string_inteiro=6');
            $table->string('numero_inss', 50)->nullable()->comment('legado: inss · tipos mistos: string_inteiro=127, string=5');
            $table->bigInteger('cargo_funcao_id')->nullable()->comment('legado: role_id');
            $table->bigInteger('tipo_organizacao_id')->nullable()->comment('legado: org_type_id');
            $table->string('estado', 20)->nullable()->comment('legado: status · código normalizado ∈ {ACTIVO, INACTIVO, SUSPENSO}; texto original em estado_original');
            $table->string('estado_original', 100)->nullable()->comment('legado: status · texto exacto do legado');
            $table->integer('dias_uteis_mes')->nullable()->comment('legado: work_days');
            $table->boolean('reformado')->nullable()->comment('legado: is_retired');
            $table->bigInteger('unidade_negocio_id')->nullable()->comment('legado: business_unit_id');
            $table->bigInteger('centro_custo_id')->nullable()->comment('legado: cost_center_id');
            $table->boolean('avencado')->nullable()->comment('legado: is_avencado');
            $table->string('sexo', 10)->nullable()->comment('legado: sexo');
            $table->date('data_nascimento')->nullable()->comment('legado: data_nascimento');
            $table->string('estado_civil', 20)->nullable()->comment('legado: estado_civil · código normalizado ∈ {SOLTEIRO, CASADO, DIVORCIADO, VIUVO, UNIAO_FACTO}; texto original em estado_civil_original');
            $table->string('estado_civil_original', 100)->nullable()->comment('legado: estado_civil · texto exacto do legado');
            $table->string('nacionalidade', 20)->nullable()->comment('legado: nacionalidade');
            $table->string('naturalidade', 20)->nullable()->comment('legado: naturalidade');
            $table->string('provincia_naturalidade', 20)->nullable()->comment('legado: provincia_naturalidade');
            $table->string('documento_identificacao', 30)->nullable()->comment('legado: documento_identificacao');
            $table->date('documento_validade')->nullable()->comment('legado: documento_validade');
            $table->date('data_admissao')->nullable()->comment('legado: data_admissao');
            $table->text('endereco')->nullable()->comment('legado: endereco');
            $table->string('bairro', 150)->nullable()->comment('legado: bairro');
            $table->string('municipio', 150)->nullable()->comment('legado: municipio');
            $table->string('provincia', 150)->nullable()->comment('legado: provincia');
            $table->string('telefone', 50)->nullable()->comment('legado: telefone · tipos mistos: string=5, string_inteiro=10');
            $table->text('telefone_alternativo')->nullable()->comment('legado: telefone_alternativo · sem valores reais: tipo a confirmar no código legado');
            $table->text('email')->nullable()->comment('legado: email · sem valores reais: tipo a confirmar no código legado');
            $table->text('emergencia_nome')->nullable()->comment('legado: emergencia_nome · sem valores reais: tipo a confirmar no código legado');
            $table->text('emergencia_telefone')->nullable()->comment('legado: emergencia_telefone · sem valores reais: tipo a confirmar no código legado');
            $table->text('emergencia_parentesco')->nullable()->comment('legado: emergencia_parentesco · sem valores reais: tipo a confirmar no código legado');
            $table->text('habilitacao_maxima')->nullable()->comment('legado: habilitacao_maxima · sem valores reais: tipo a confirmar no código legado');
            $table->bigInteger('unidade_organica_id')->nullable()->comment('legado: org_unit_id');
            $table->bigInteger('posto_trabalho_id')->nullable()->comment('legado: org_position_id');
            $table->bigInteger('colaborador_gestor_id')->nullable()->comment('legado: manager_employee_id');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
            $table->timestampTz('eliminado_em')->nullable();
        });
        DB::statement('CREATE UNIQUE INDEX uq_colaboradores_empresa_id_nif ON colaboradores (empresa_id, nif) WHERE eliminado_em IS NULL');
        DB::statement('CREATE INDEX ix_colaboradores_cargo_funcao_id ON colaboradores (cargo_funcao_id)');
        DB::statement('CREATE INDEX ix_colaboradores_tipo_organizacao_id ON colaboradores (tipo_organizacao_id)');
        DB::statement('CREATE INDEX ix_colaboradores_unidade_negocio_id ON colaboradores (unidade_negocio_id)');
        DB::statement('CREATE INDEX ix_colaboradores_centro_custo_id ON colaboradores (centro_custo_id)');
        DB::statement('CREATE INDEX ix_colaboradores_unidade_organica_id ON colaboradores (unidade_organica_id)');
        DB::statement('CREATE INDEX ix_colaboradores_posto_trabalho_id ON colaboradores (posto_trabalho_id)');
        DB::statement('CREATE INDEX ix_colaboradores_colaborador_gestor_id ON colaboradores (colaborador_gestor_id)');
        DB::statement('ALTER TABLE colaboradores ADD CONSTRAINT ck_colaboradores_estado CHECK (estado IS NULL OR estado IN (\'ACTIVO\',\'INACTIVO\',\'SUSPENSO\'))');
        DB::statement('ALTER TABLE colaboradores ADD CONSTRAINT ck_colaboradores_estado_civil CHECK (estado_civil IS NULL OR estado_civil IN (\'SOLTEIRO\',\'CASADO\',\'DIVORCIADO\',\'VIUVO\',\'UNIAO_FACTO\'))');

        // roles (legado) -> cargos_funcoes · 76 linhas reais no backup · eliminação lógica
        Schema::create('cargos_funcoes', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->string('nome', 255)->nullable()->comment('legado: name');
            $table->text('descricao')->nullable()->comment('legado: description');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
            $table->timestampTz('eliminado_em')->nullable();
        });
        DB::statement('CREATE INDEX ix_cargos_funcoes_empresa_id ON cargos_funcoes (empresa_id)');
        DB::statement('CREATE INDEX ix_cargos_funcoes_empresa_id_nome ON cargos_funcoes (empresa_id, nome)');

        // org_types (legado) -> tipos_organizacao_rh · 28 linhas reais no backup · eliminação lógica
        Schema::create('tipos_organizacao_rh', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->string('nome', 255)->nullable()->comment('legado: name');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
            $table->timestampTz('eliminado_em')->nullable();
        });
        DB::statement('CREATE UNIQUE INDEX uq_tipos_organizacao_rh_empresa_id_nome ON tipos_organizacao_rh (empresa_id, nome) WHERE eliminado_em IS NULL');

        // infotypes (legado) -> infotipos_salariais · 175 linhas reais no backup · eliminação lógica
        Schema::create('infotipos_salariais', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->string('tipo', 20)->nullable()->comment('legado: type');
            $table->string('nome', 255)->nullable()->comment('legado: name');
            $table->boolean('sujeito_inss')->nullable()->comment('legado: inss');
            $table->string('irt', 30)->nullable()->comment('legado: irt · tipos mistos: boolean=149, string=26');
            $table->boolean('base_horaria')->nullable()->comment('legado: base_horaria · do código legado js/app_v2.js:9161');
            $table->string('calculo_horas', 10)->nullable()->comment('legado: calculo_horas · do código legado js/app_v2.js:9161');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
            $table->timestampTz('eliminado_em')->nullable();
        });
        DB::statement('CREATE INDEX ix_infotipos_salariais_empresa_id ON infotipos_salariais (empresa_id)');
        DB::statement('CREATE INDEX ix_infotipos_salariais_empresa_id_nome ON infotipos_salariais (empresa_id, nome)');

        // contracts (legado) -> contratos_trabalho · 89 linhas reais no backup
        Schema::create('contratos_trabalho', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->bigInteger('colaborador_id')->nullable()->comment('legado: employee_id');
            $table->jsonb('remuneracoes')->nullable()->comment('legado: remunerations');
            $table->integer('dias_contrato_mes')->nullable()->comment('legado: contract_days_month');
            $table->date('data_inicio')->nullable()->comment('legado: start_date');
            $table->date('data_fim')->nullable()->comment('legado: end_date');
            $table->string('estado', 10)->nullable()->comment('legado: status');
            $table->decimal('horas_por_dia', 12, 3)->nullable()->comment('legado: hours_per_day');
            $table->string('codigo_moeda', 50)->nullable()->comment('legado: currency');
            $table->jsonb('produtividade')->nullable()->comment('legado: produtividade');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_contratos_trabalho_empresa_id ON contratos_trabalho (empresa_id)');
        DB::statement('CREATE INDEX ix_contratos_trabalho_colaborador_id ON contratos_trabalho (colaborador_id)');

        // payroll_periods (legado) -> periodos_processamento_salarial · 47 linhas reais no backup
        Schema::create('periodos_processamento_salarial', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->string('mes_ano', 20)->nullable()->comment('legado: month_year');
            $table->string('estado', 20)->nullable()->comment('legado: status · código normalizado ∈ {ABERTO, FECHADO, VALIDADO}; texto original em estado_original');
            $table->string('estado_original', 100)->nullable()->comment('legado: status · texto exacto do legado');
            $table->boolean('contabilizado')->nullable()->comment('legado: is_posted');
            $table->timestampTz('fechado_em')->nullable()->comment('Encerramento do cálculo (fotografia dos resultados)');
            $table->string('fechado_por', 100)->nullable()->comment('Quem encerrou');
            $table->timestampTz('validado_em')->nullable()->comment('Validação');
            $table->string('validado_por', 100)->nullable()->comment('Quem validou');
            $table->string('numero_lan_contabilizacao', 30)->nullable()->comment('N.º do lançamento da integração no diário SAL');
            $table->string('modo_calculo', 10)->nullable()->comment('ATUAL (regras corrigidas) ou LEGADO (reprodução do motor antigo, períodos migrados)');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE UNIQUE INDEX uq_periodos_processamento_salarial_empresa_id_mes_ano ON periodos_processamento_salarial (empresa_id, mes_ano)');
        DB::statement('ALTER TABLE periodos_processamento_salarial ADD CONSTRAINT ck_periodos_processamento_salarial_estado CHECK (estado IS NULL OR estado IN (\'ABERTO\',\'FECHADO\',\'VALIDADO\'))');

        // payroll_entries (legado) -> linhas_folha_salarial · 1114 linhas reais no backup
        Schema::create('linhas_folha_salarial', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->bigInteger('periodo_processamento_salarial_id')->nullable()->comment('legado: period_id');
            $table->bigInteger('colaborador_id')->nullable()->comment('legado: employee_id');
            $table->bigInteger('infotipo_salarial_id')->nullable()->comment('legado: infotype_id');
            $table->decimal('valor', 15, 2)->nullable()->comment('legado: value · tipos mistos: inteiro=956, decimal=158');
            $table->decimal('dias_trabalhados', 12, 3)->nullable()->comment('legado: worked_days · tipos mistos: inteiro=533, decimal=3; tipos Dexie: {"undef":80}');
            $table->decimal('horas', 12, 3)->nullable()->comment('legado: hours · tipos mistos: decimal=11, inteiro=1');
            $table->boolean('origem_efetividade')->nullable()->comment('legado: origem_efectividade');
            $table->boolean('origem_produtividade')->nullable()->comment('legado: origem_produtividade');
            $table->bigInteger('periodo_produtividade_id')->nullable()->comment('legado: prod_period_id');
            $table->string('origem', 255)->nullable()->comment('legado: origem · do código legado js/modules/rh/aval360_dados.js:505');
            $table->bigInteger('bonificacao_avaliacao_id')->nullable()->comment('legado: eval_bonus_id · do código legado js/modules/rh/aval360_dados.js:505');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_linhas_folha_salarial_empresa_id ON linhas_folha_salarial (empresa_id)');
        DB::statement('CREATE INDEX ix_linhas_folha_salarial_periodo_processamento_salarial_id ON linhas_folha_salarial (periodo_processamento_salarial_id)');
        DB::statement('CREATE INDEX ix_linhas_folha_salarial_colaborador_id ON linhas_folha_salarial (colaborador_id)');
        DB::statement('CREATE INDEX ix_linhas_folha_salarial_infotipo_salarial_id ON linhas_folha_salarial (infotipo_salarial_id)');
        DB::statement('CREATE INDEX ix_linhas_folha_salarial_periodo_produtividade_id ON linhas_folha_salarial (periodo_produtividade_id)');
        DB::statement('CREATE INDEX ix_linhas_folha_salarial_bonificacao_avaliacao_id ON linhas_folha_salarial (bonificacao_avaliacao_id)');
        DB::statement('CREATE INDEX ix_linhas_folha_salarial_empresa__periodo__colabora ON linhas_folha_salarial (empresa_id, periodo_processamento_salarial_id, colaborador_id)');

        // accounting_mapos (legado) -> mapeamentos_contabeis_rh · 225 linhas reais no backup
        Schema::create('mapeamentos_contabeis_rh', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->bigInteger('infotipo_salarial_id')->nullable()->comment('legado: infotype_id');
            $table->bigInteger('tipo_organizacao_id')->nullable()->comment('legado: org_type_id · -1 no legado = coluna \'Avençado\' -> NULL + avencado=true');
            $table->boolean('avencado')->nullable()->comment('Coluna \'Avençado\' do mapeamento (legado: org_type_id = -1)');
            $table->string('numero_conta', 20)->nullable()->comment('legado: account_number');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_mapeamentos_contabeis_rh_empresa_id ON mapeamentos_contabeis_rh (empresa_id)');
        DB::statement('CREATE INDEX ix_mapeamentos_contabeis_rh_infotipo_salarial_id ON mapeamentos_contabeis_rh (infotipo_salarial_id)');
        DB::statement('CREATE INDEX ix_mapeamentos_contabeis_rh_tipo_organizacao_id ON mapeamentos_contabeis_rh (tipo_organizacao_id)');
        DB::statement('CREATE INDEX ix_mapeamentos_contabeis_rh_empresa__infotipo_tipo_org ON mapeamentos_contabeis_rh (empresa_id, infotipo_salarial_id, tipo_organizacao_id)');

        // system_accounting_mapos (legado) -> mapeamentos_contabeis_sistema_rh · 112 linhas reais no backup
        Schema::create('mapeamentos_contabeis_sistema_rh', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->string('codigo', 50)->nullable()->comment('legado: code');
            $table->bigInteger('tipo_organizacao_id')->nullable()->comment('legado: org_type_id · tipos Dexie: {"nan":6}; -1 no legado = coluna \'Avençado\' -> NULL + avencado=true');
            $table->boolean('avencado')->nullable()->comment('Coluna \'Avençado\' do mapeamento (legado: org_type_id = -1)');
            $table->string('numero_conta', 20)->nullable()->comment('legado: account_number');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_mapeamentos_contabeis_sistema_rh_empresa_id ON mapeamentos_contabeis_sistema_rh (empresa_id)');
        DB::statement('CREATE INDEX ix_mapeamentos_contabeis_sistema_rh_tipo_organizacao_id ON mapeamentos_contabeis_sistema_rh (tipo_organizacao_id)');

        // banks (legado) -> bancos · 13 linhas reais no backup · eliminação lógica
        Schema::create('bancos', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->string('nome', 255)->nullable()->comment('legado: name');
            $table->string('codigo', 50)->nullable()->comment('legado: code · tipos mistos: string_inteiro=2, string=7');
            $table->string('nif', 30)->nullable()->comment('legado: nif');
            $table->text('endereco')->nullable()->comment('legado: address');
            $table->string('codigo_conta', 20)->nullable()->comment('legado: account_code');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
            $table->timestampTz('eliminado_em')->nullable();
        });
        DB::statement('CREATE INDEX ix_bancos_empresa_id ON bancos (empresa_id)');

        // employee_bank_details (legado) -> coordenadas_bancarias_colaboradores · 44 linhas reais no backup
        Schema::create('coordenadas_bancarias_colaboradores', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->bigInteger('colaborador_id')->nullable()->comment('legado: employee_id');
            $table->bigInteger('banco_id')->nullable()->comment('legado: bank_id');
            $table->string('iban', 50)->nullable()->comment('legado: iban · tipos mistos: string_inteiro=8, string=36');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE UNIQUE INDEX uq_coordenadas_bancarias_colabora_empresa__colabora ON coordenadas_bancarias_colaboradores (empresa_id, colaborador_id)');
        DB::statement('CREATE UNIQUE INDEX uq_coordenadas_bancarias_colabora_empresa__colabora_iban ON coordenadas_bancarias_colaboradores (empresa_id, colaborador_id, iban)');
        DB::statement('CREATE INDEX ix_coordenadas_bancarias_colaboradores_colaborador_id ON coordenadas_bancarias_colaboradores (colaborador_id)');
        DB::statement('CREATE INDEX ix_coordenadas_bancarias_colaboradores_banco_id ON coordenadas_bancarias_colaboradores (banco_id)');

        // payment_letters (legado) -> cartas_pagamento_bancario · 0 linhas reais no backup
        Schema::create('cartas_pagamento_bancario', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id · do código legado js/db_v2.js:190');
            $table->string('mes_ano', 255)->nullable()->comment('legado: month_year · do código legado js/db_v2.js:190');
            $table->string('codigo_conta_bancaria', 255)->nullable()->comment('legado: bank_account_code · do código legado js/db_v2.js:190');
            $table->string('nome_assinatura', 255)->nullable()->comment('legado: signature_name · do código legado js/db_v2.js:190');
            $table->date('data')->nullable()->comment('legado: date · do código legado js/db_v2.js:190');
            $table->decimal('montante_total', 15, 2)->nullable()->comment('legado: total_amount · do código legado js/db_v2.js:190');
            $table->bigInteger('periodo_processamento_salarial_id')->nullable()->comment('Processamento salarial pago por esta carta');
            $table->bigInteger('documento_tesouraria_id')->nullable()->comment('Pagamento (PAG) gerado a partir da carta');
            $table->string('grupo', 20)->nullable()->comment('COLABORADORES, AVENCADOS ou TODOS');
            $table->string('criado_por', 100)->nullable()->comment('Quem emitiu a carta');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_cartas_pagamento_bancario_empresa_id ON cartas_pagamento_bancario (empresa_id)');
        DB::statement('CREATE INDEX ix_cartas_pagamento_bancario_periodo_processamento_salarial_id ON cartas_pagamento_bancario (periodo_processamento_salarial_id)');
        DB::statement('CREATE INDEX ix_cartas_pagamento_bancario_documento_tesouraria_id ON cartas_pagamento_bancario (documento_tesouraria_id)');

        // payment_letter_items (legado) -> itens_carta_pagamento · 0 linhas reais no backup
        Schema::create('itens_carta_pagamento', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('tenant (derivado no ETL)');
            $table->bigInteger('carta_pagamento_bancario_id')->nullable()->comment('legado: letter_id · do código legado js/db_v2.js:191');
            $table->bigInteger('colaborador_id')->nullable()->comment('legado: employee_id · do código legado js/db_v2.js:191');
            $table->decimal('montante', 15, 2)->nullable()->comment('legado: amount · do código legado js/db_v2.js:191');
            $table->string('iban', 255)->nullable()->comment('legado: iban · do código legado js/db_v2.js:191');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_itens_carta_pagamento_empresa_id ON itens_carta_pagamento (empresa_id)');
        DB::statement('CREATE INDEX ix_itens_carta_pagamento_carta_pagamento_bancario_id ON itens_carta_pagamento (carta_pagamento_bancario_id)');
        DB::statement('CREATE INDEX ix_itens_carta_pagamento_colaborador_id ON itens_carta_pagamento (colaborador_id)');

        // rh_dependents (legado) -> dependentes_colaboradores · 1 linhas reais no backup
        Schema::create('dependentes_colaboradores', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: rh_company_id');
            $table->bigInteger('colaborador_id')->nullable()->comment('legado: employee_id');
            $table->integer('ordem')->nullable()->comment('legado: ordem');
            $table->string('nome', 255)->nullable()->comment('legado: nome');
            $table->string('parentesco', 20)->nullable()->comment('legado: parentesco · código normalizado ∈ {FILHO, CONJUGE, PAI, MAE, OUTRO}; texto original em parentesco_original');
            $table->string('parentesco_original', 100)->nullable()->comment('legado: parentesco · texto exacto do legado');
            $table->date('data_nascimento')->nullable()->comment('legado: data_nascimento');
            $table->string('sexo', 10)->nullable()->comment('legado: sexo');
            $table->boolean('dependente_fiscal')->nullable()->comment('legado: dependente_fiscal');
            $table->string('origem', 30)->nullable()->comment('legado: origem');
            $table->timestampTz('atualizado_em')->nullable()->useCurrent()->comment('legado: updated_at');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_dependentes_colaboradores_empresa_id ON dependentes_colaboradores (empresa_id)');
        DB::statement('CREATE INDEX ix_dependentes_colaboradores_colaborador_id ON dependentes_colaboradores (colaborador_id)');
        DB::statement('ALTER TABLE dependentes_colaboradores ADD CONSTRAINT ck_dependentes_colaboradores_parentesco CHECK (parentesco IS NULL OR parentesco IN (\'FILHO\',\'CONJUGE\',\'PAI\',\'MAE\',\'OUTRO\'))');

        // rh_education (legado) -> habilitacoes_colaboradores · 0 linhas reais no backup
        Schema::create('habilitacoes_colaboradores', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: rh_company_id · do código legado js/modules/rh/ficha_colaborador.js:415');
            $table->bigInteger('colaborador_id')->nullable()->comment('legado: employee_id · do código legado js/modules/rh/ficha_colaborador.js:415');
            $table->string('ordem', 255)->nullable()->comment('legado: ordem · do código legado js/modules/rh/ficha_colaborador.js:415');
            $table->string('nivel', 255)->nullable()->comment('legado: nivel · do código legado js/modules/rh/ficha_colaborador.js:416');
            $table->string('curso', 255)->nullable()->comment('legado: curso · do código legado js/modules/rh/ficha_colaborador.js:416');
            $table->string('instituicao', 255)->nullable()->comment('legado: instituicao · do código legado js/modules/rh/ficha_colaborador.js:416');
            $table->string('ano_conclusao', 255)->nullable()->comment('legado: ano_conclusao · do código legado js/modules/rh/ficha_colaborador.js:417');
            $table->string('estado', 255)->nullable()->comment('legado: estado · do código legado js/modules/rh/ficha_colaborador.js:417');
            $table->timestampTz('atualizado_em')->nullable()->useCurrent()->comment('legado: updated_at · do código legado js/modules/rh/ficha_colaborador.js:417');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_habilitacoes_colaboradores_empresa_id ON habilitacoes_colaboradores (empresa_id)');
        DB::statement('CREATE INDEX ix_habilitacoes_colaboradores_colaborador_id ON habilitacoes_colaboradores (colaborador_id)');

        // rh_vacations (legado) -> plano_ferias_colaboradores · 4 linhas reais no backup
        Schema::create('plano_ferias_colaboradores', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: rh_company_id');
            $table->bigInteger('colaborador_id')->nullable()->comment('legado: employee_id');
            $table->integer('ano')->nullable()->comment('legado: ano');
            $table->date('data_inicio')->nullable()->comment('legado: data_inicio');
            $table->date('data_fim')->nullable()->comment('legado: data_fim');
            $table->integer('dias')->nullable()->comment('legado: dias');
            $table->integer('direito')->nullable()->comment('legado: direito');
            $table->string('estado', 20)->nullable()->comment('legado: status · código normalizado ∈ {PEDIDO, PLANEADO, APROVADO, GOZADO, CANCELADO}; texto original em estado_original');
            $table->string('estado_original', 100)->nullable()->comment('legado: status · texto exacto do legado');
            $table->text('observacoes')->nullable()->comment('legado: observacoes');
            $table->bigInteger('pedido_portal_colaborador_id')->nullable()->comment('legado: portal_request_id');
            $table->timestampTz('criado_em')->nullable()->useCurrent()->comment('legado: created_at');
            $table->timestampTz('atualizado_em')->nullable()->useCurrent()->comment('legado: updated_at');
        });
        DB::statement('CREATE INDEX ix_plano_ferias_colaboradores_empresa_id ON plano_ferias_colaboradores (empresa_id)');
        DB::statement('CREATE INDEX ix_plano_ferias_colaboradores_colaborador_id ON plano_ferias_colaboradores (colaborador_id)');
        DB::statement('CREATE INDEX ix_plano_ferias_colaboradores_pedido_portal_colaborador_id ON plano_ferias_colaboradores (pedido_portal_colaborador_id)');
        DB::statement('ALTER TABLE plano_ferias_colaboradores ADD CONSTRAINT ck_plano_ferias_colaboradores_estado CHECK (estado IS NULL OR estado IN (\'PEDIDO\',\'PLANEADO\',\'APROVADO\',\'GOZADO\',\'CANCELADO\'))');

        // rh_absences (legado) -> ausencias_faltas_colaboradores · 34 linhas reais no backup
        Schema::create('ausencias_faltas_colaboradores', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: rh_company_id');
            $table->bigInteger('colaborador_id')->nullable()->comment('legado: employee_id');
            $table->string('tipo', 40)->nullable()->comment('legado: tipo · sem valores reais: tipo a confirmar no código legado; tipo forçado (inferido: text)');
            $table->date('data_inicio')->nullable()->comment('legado: data_inicio');
            $table->date('data_fim')->nullable()->comment('legado: data_fim');
            $table->integer('dias_uteis')->nullable()->comment('legado: dias_uteis');
            $table->decimal('horas_falta', 12, 3)->nullable()->comment('legado: horas_falta');
            $table->string('ocorrencia', 50)->nullable()->comment('legado: ocorrencia');
            $table->string('estado', 20)->nullable()->comment('legado: estado · código normalizado ∈ {POR_JUSTIFICAR, PENDENTE_CHEFIA, PENDENTE_RH, APROVADO, RECUSADO, CANCELADO}; texto original em estado_original');
            $table->string('estado_original', 100)->nullable()->comment('legado: estado · texto exacto do legado');
            $table->boolean('detectada')->nullable()->comment('legado: detectada');
            $table->bigInteger('fecho_mensal_assiduidade_id')->nullable()->comment('legado: fecho_id');
            $table->string('mes', 20)->nullable()->comment('legado: mes');
            $table->string('criado_por', 100)->nullable()->comment('legado: criado_por');
            $table->integer('dias')->nullable()->comment('legado: dias');
            $table->decimal('horas', 12, 3)->nullable()->comment('legado: horas');
            $table->boolean('pendente_no_fecho')->nullable()->comment('legado: pendente_no_fecho');
            $table->text('motivo')->nullable()->comment('legado: motivo · do código legado js/modules/rh/ausencias.js:133');
            $table->text('documento_url')->nullable()->comment('legado: documento_url · do código legado js/modules/rh/ausencias.js:133');
            $table->string('remunerada', 20)->nullable()->comment('legado: remunerada · do código legado js/modules/rh/ausencias.js:134');
            $table->jsonb('avisos')->nullable()->comment('legado: avisos · do código legado js/modules/rh/ausencias.js:134');
            $table->bigInteger('pedido_portal_colaborador_id')->nullable()->comment('legado: portal_request_id · do código legado js/modules/rh/ausencias.js:135');
            $table->timestampTz('justificada_em')->nullable()->comment('legado: justificada_em · do código legado js/modules/rh/ausencias.js:154');
            $table->string('justificada_por', 255)->nullable()->comment('legado: justificada_por · do código legado js/modules/rh/ausencias.js:154');
            $table->timestampTz('decidido_em')->nullable()->comment('legado: decidido_em · do código legado js/modules/rh/ausencias.js:167');
            $table->string('decidido_por', 255)->nullable()->comment('legado: decidido_por · do código legado js/modules/rh/ausencias.js:167');
            $table->text('nota_decisao')->nullable()->comment('legado: nota_decisao · do código legado js/modules/rh/ausencias.js:167');
            $table->timestampTz('criado_em')->nullable()->useCurrent()->comment('legado: criado_em');
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_ausencias_faltas_colaboradores_empresa_id ON ausencias_faltas_colaboradores (empresa_id)');
        DB::statement('CREATE INDEX ix_ausencias_faltas_colaboradores_colaborador_id ON ausencias_faltas_colaboradores (colaborador_id)');
        DB::statement('CREATE INDEX ix_ausencias_faltas_colaboradores_fecho_mensal_assiduidade_id ON ausencias_faltas_colaboradores (fecho_mensal_assiduidade_id)');
        DB::statement('CREATE INDEX ix_ausencias_faltas_colaboradores_pedido_portal_colaborador_id ON ausencias_faltas_colaboradores (pedido_portal_colaborador_id)');
        DB::statement('ALTER TABLE ausencias_faltas_colaboradores ADD CONSTRAINT ck_ausencias_faltas_colaboradores_estado CHECK (estado IS NULL OR estado IN (\'POR_JUSTIFICAR\',\'PENDENTE_CHEFIA\',\'PENDENTE_RH\',\'APROVADO\',\'RECUSADO\',\'CANCELADO\'))');

        // rh_attendance (legado) -> efectividade_assiduidade · 190 linhas reais no backup
        Schema::create('efectividade_assiduidade', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: rh_company_id');
            $table->date('data')->nullable()->comment('legado: data');
            $table->bigInteger('colaborador_id')->nullable()->comment('legado: employee_id');
            $table->string('entrada', 10)->nullable()->comment('legado: entrada');
            $table->string('saida', 10)->nullable()->comment('legado: saida');
            $table->decimal('horas', 12, 3)->nullable()->comment('legado: horas · tipos mistos: inteiro=24, decimal=166');
            $table->string('origem', 20)->nullable()->comment('legado: origem · código normalizado ∈ {MANUAL, FICHEIRO, RELOGIO}; texto original em origem_original');
            $table->string('origem_original', 100)->nullable()->comment('legado: origem · texto exacto do legado');
            $table->string('fonte', 50)->nullable()->comment('legado: fonte');
            $table->text('observacoes')->nullable()->comment('legado: observacoes · sem valores reais: tipo a confirmar no código legado');
            $table->string('atualizado_por', 100)->nullable()->comment('legado: actualizado_por');
            $table->string('criado_por', 100)->nullable()->comment('legado: criado_por');
            $table->boolean('autorizado_extra')->nullable()->comment('legado: autorizado_extra · do código legado js/modules/rh/assiduidade.js:316');
            $table->timestampTz('atualizado_em')->nullable()->useCurrent()->comment('legado: actualizado_em');
            $table->timestampTz('criado_em')->nullable()->useCurrent()->comment('legado: criado_em');
        });
        DB::statement('CREATE UNIQUE INDEX uq_efectividade_assiduidade_empresa_id_colaborador_id_data ON efectividade_assiduidade (empresa_id, colaborador_id, data)');
        DB::statement('CREATE INDEX ix_efectividade_assiduidade_colaborador_id ON efectividade_assiduidade (colaborador_id)');
        DB::statement('ALTER TABLE efectividade_assiduidade ADD CONSTRAINT ck_efectividade_assiduidade_origem CHECK (origem IS NULL OR origem IN (\'MANUAL\',\'FICHEIRO\',\'RELOGIO\'))');

        // rh_attendance_config (legado) -> configuracoes_assiduidade · 0 linhas reais no backup
        Schema::create('configuracoes_assiduidade', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: rh_company_id · do código legado js/modules/rh/assiduidade.js:59');
            $table->jsonb('dias_uteis')->nullable()->comment('legado: dias_uteis · do código legado js/modules/rh/assiduidade.js:59');
            $table->integer('tolerancia_min')->nullable()->comment('legado: tolerancia_min · do código legado js/modules/rh/assiduidade.js:60');
            $table->integer('arredondamento_min')->nullable()->comment('legado: arredondamento_min · do código legado js/modules/rh/assiduidade.js:61');
            $table->integer('extras_min_minutos')->nullable()->comment('legado: extras_min_minutos · do código legado js/modules/rh/assiduidade.js:62');
            $table->decimal('minimo_dia_horas', 12, 3)->nullable()->comment('legado: minimo_dia_horas · do código legado js/modules/rh/assiduidade.js:63');
            $table->jsonb('feriados')->nullable()->comment('legado: feriados · do código legado js/modules/rh/assiduidade.js:64');
            $table->jsonb('relogio')->nullable()->comment('legado: relogio · do código legado js/modules/rh/assiduidade.js:65');
            $table->string('modo_compensacao', 10)->nullable()->comment('legado: modo_compensacao · do código legado js/modules/rh/assiduidade.js:66');
            $table->decimal('limite_compensacao_h', 15, 2)->nullable()->comment('legado: limite_compensacao_h · do código legado js/modules/rh/assiduidade.js:67');
            $table->boolean('extra_nao_util_exige_autorizacao')->nullable()->comment('legado: extra_nao_util_exige_autorizacao · do código legado js/modules/rh/assiduidade.js:68');
            $table->string('atualizado_por', 255)->nullable()->comment('legado: actualizado_por · do código legado js/modules/rh/assiduidade.js:69');
            $table->timestampTz('atualizado_em')->nullable()->useCurrent()->comment('legado: actualizado_em · do código legado js/modules/rh/assiduidade.js:69');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_configuracoes_assiduidade_empresa_id ON configuracoes_assiduidade (empresa_id)');

        // rh_attendance_closures (legado) -> fechos_mensais_assiduidade · 2 linhas reais no backup
        Schema::create('fechos_mensais_assiduidade', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: rh_company_id');
            $table->string('mes', 20)->nullable()->comment('legado: mes');
            $table->string('estado', 20)->nullable()->comment('legado: estado · código normalizado ∈ {FECHADO, REABERTO}; texto original em estado_original');
            $table->string('estado_original', 100)->nullable()->comment('legado: estado · texto exacto do legado');
            $table->integer('dias_uteis')->nullable()->comment('legado: dias_uteis');
            $table->jsonb('linhas')->nullable()->comment('legado: linhas');
            $table->jsonb('totais')->nullable()->comment('legado: totais');
            $table->timestampTz('fechado_em')->nullable()->comment('legado: fechado_em');
            $table->string('fechado_por', 100)->nullable()->comment('legado: fechado_por');
            $table->timestampTz('lancado_em')->nullable()->comment('legado: lancado_em');
            $table->string('lancado_por', 100)->nullable()->comment('legado: lancado_por');
            $table->bigInteger('periodo_processamento_salarial_id')->nullable()->comment('legado: period_id');
            $table->date('apurado_ate')->nullable()->comment('legado: apurado_ate');
            $table->integer('ausencias_geradas')->nullable()->comment('legado: ausencias_geradas');
            $table->timestampTz('reaberto_em')->nullable()->comment('legado: reaberto_em · do código legado js/modules/rh/assiduidade.js:174');
            $table->string('reaberto_por', 255)->nullable()->comment('legado: reaberto_por · do código legado js/modules/rh/assiduidade.js:174');
            $table->text('motivo_reabertura')->nullable()->comment('legado: motivo_reabertura · do código legado js/modules/rh/assiduidade.js:174');
            $table->jsonb('configuracao')->nullable()->comment('Configuração da assiduidade usada no apuramento (fotografia)');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE UNIQUE INDEX uq_fechos_mensais_assiduidade_empresa_id_mes ON fechos_mensais_assiduidade (empresa_id, mes)');
        DB::statement('CREATE INDEX ix_fechos_mensais_assiduidade_periodo_processamento_salarial_id ON fechos_mensais_assiduidade (periodo_processamento_salarial_id)');
        DB::statement('ALTER TABLE fechos_mensais_assiduidade ADD CONSTRAINT ck_fechos_mensais_assiduidade_estado CHECK (estado IS NULL OR estado IN (\'FECHADO\',\'REABERTO\'))');

        // rh_prod_items (legado) -> itens_produtividade_rh · 1 linhas reais no backup
        Schema::create('itens_produtividade_rh', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: rh_company_id');
            $table->string('codigo', 50)->nullable()->comment('legado: codigo');
            $table->text('descricao')->nullable()->comment('legado: descricao');
            $table->string('metrica', 20)->nullable()->comment('legado: metrica · código normalizado ∈ {QUANTIDADE, HORAS, OBJECTIVO, PONTOS, TAREFAS}; texto original em metrica_original');
            $table->string('metrica_original', 100)->nullable()->comment('legado: metrica · texto exacto do legado');
            $table->string('unidade', 10)->nullable()->comment('legado: unidade');
            $table->decimal('preco_unitario', 15, 4)->nullable()->comment('legado: preco_unitario · tipo forçado (inferido: numeric(15,2))');
            $table->bigInteger('infotipo_salarial_id')->nullable()->comment('legado: infotype_id');
            $table->decimal('minimo', 15, 3)->nullable()->comment('legado: minimo · sem valores reais: tipo a confirmar no código legado; tipo forçado (inferido: text)');
            $table->decimal('maximo', 15, 3)->nullable()->comment('legado: maximo · sem valores reais: tipo a confirmar no código legado; tipo forçado (inferido: text)');
            $table->boolean('ativo')->nullable()->comment('legado: activo');
            $table->string('atualizado_por', 100)->nullable()->comment('legado: actualizado_por');
            $table->string('criado_por', 100)->nullable()->comment('legado: criado_por');
            $table->timestampTz('atualizado_em')->nullable()->useCurrent()->comment('legado: actualizado_em');
            $table->timestampTz('criado_em')->nullable()->useCurrent()->comment('legado: criado_em');
        });
        DB::statement('CREATE INDEX ix_itens_produtividade_rh_empresa_id ON itens_produtividade_rh (empresa_id)');
        DB::statement('CREATE INDEX ix_itens_produtividade_rh_infotipo_salarial_id ON itens_produtividade_rh (infotipo_salarial_id)');
        DB::statement('ALTER TABLE itens_produtividade_rh ADD CONSTRAINT ck_itens_produtividade_rh_metrica CHECK (metrica IS NULL OR metrica IN (\'QUANTIDADE\',\'HORAS\',\'OBJECTIVO\',\'PONTOS\',\'TAREFAS\'))');

        // rh_prod_periods (legado) -> periodos_produtividade_rh · 1 linhas reais no backup
        Schema::create('periodos_produtividade_rh', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: rh_company_id');
            $table->string('mes', 20)->nullable()->comment('legado: mes');
            $table->date('data_inicio')->nullable()->comment('legado: data_inicio');
            $table->date('data_fim')->nullable()->comment('legado: data_fim');
            $table->text('observacoes')->nullable()->comment('legado: observacoes · sem valores reais: tipo a confirmar no código legado');
            $table->string('atualizado_por', 100)->nullable()->comment('legado: actualizado_por');
            $table->string('estado', 20)->nullable()->comment('legado: estado · código normalizado ∈ {ABERTO, FECHADO}; texto original em estado_original');
            $table->string('estado_original', 100)->nullable()->comment('legado: estado · texto exacto do legado');
            $table->string('criado_por', 100)->nullable()->comment('legado: criado_por');
            $table->timestampTz('fechado_em')->nullable()->comment('legado: fechado_em');
            $table->string('fechado_por', 100)->nullable()->comment('legado: fechado_por');
            $table->decimal('total_fecho', 15, 2)->nullable()->comment('legado: total_fecho');
            $table->integer('registos_fecho')->nullable()->comment('legado: registos_fecho');
            $table->timestampTz('lancado_em')->nullable()->comment('legado: lancado_em');
            $table->string('lancado_por', 100)->nullable()->comment('legado: lancado_por');
            $table->bigInteger('periodo_processamento_salarial_id')->nullable()->comment('legado: period_id');
            $table->timestampTz('reaberto_em')->nullable()->comment('legado: reaberto_em');
            $table->string('reaberto_por', 100)->nullable()->comment('legado: reaberto_por');
            $table->text('motivo_reabertura')->nullable()->comment('legado: motivo_reabertura');
            $table->timestampTz('atualizado_em')->nullable()->useCurrent()->comment('legado: actualizado_em');
            $table->timestampTz('criado_em')->nullable()->useCurrent()->comment('legado: criado_em');
        });
        DB::statement('CREATE UNIQUE INDEX uq_periodos_produtividade_rh_empresa_id_mes ON periodos_produtividade_rh (empresa_id, mes)');
        DB::statement('CREATE INDEX ix_periodos_produtividade_rh_periodo_processamento_salarial_id ON periodos_produtividade_rh (periodo_processamento_salarial_id)');
        DB::statement('ALTER TABLE periodos_produtividade_rh ADD CONSTRAINT ck_periodos_produtividade_rh_estado CHECK (estado IS NULL OR estado IN (\'ABERTO\',\'FECHADO\'))');

        // rh_productivity (legado) -> registos_produtividade_rh · 6 linhas reais no backup
        Schema::create('registos_produtividade_rh', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: rh_company_id');
            $table->bigInteger('periodo_produtividade_id')->nullable()->comment('legado: prod_period_id');
            $table->string('mes', 20)->nullable()->comment('legado: mes');
            $table->bigInteger('colaborador_id')->nullable()->comment('legado: employee_id');
            $table->bigInteger('item_produtividade_id')->nullable()->comment('legado: item_id');
            $table->date('data')->nullable()->comment('legado: data · sem valores reais: tipo a confirmar no código legado; tipo forçado (inferido: text)');
            $table->decimal('quantidade', 12, 3)->nullable()->comment('legado: quantidade');
            $table->decimal('preco_unitario', 15, 4)->nullable()->comment('legado: preco_unitario · tipo forçado (inferido: numeric(15,2))');
            $table->decimal('valor', 15, 2)->nullable()->comment('legado: valor');
            $table->decimal('quantidade_considerada', 12, 3)->nullable()->comment('legado: quantidade_considerada');
            $table->text('observacoes')->nullable()->comment('legado: observacoes · sem valores reais: tipo a confirmar no código legado');
            $table->string('origem', 10)->nullable()->comment('legado: origem');
            $table->string('criado_por', 100)->nullable()->comment('legado: criado_por');
            $table->string('atualizado_por', 255)->nullable()->comment('legado: actualizado_por · do código legado js/modules/rh/produtividade.js:222');
            $table->timestampTz('criado_em')->nullable()->useCurrent()->comment('legado: criado_em');
            $table->timestampTz('atualizado_em')->nullable()->useCurrent()->comment('legado: actualizado_em · do código legado js/modules/rh/produtividade.js:222');
        });
        DB::statement('CREATE INDEX ix_registos_produtividade_rh_empresa_id ON registos_produtividade_rh (empresa_id)');
        DB::statement('CREATE INDEX ix_registos_produtividade_rh_periodo_produtividade_id ON registos_produtividade_rh (periodo_produtividade_id)');
        DB::statement('CREATE INDEX ix_registos_produtividade_rh_colaborador_id ON registos_produtividade_rh (colaborador_id)');
        DB::statement('CREATE INDEX ix_registos_produtividade_rh_item_produtividade_id ON registos_produtividade_rh (item_produtividade_id)');

        // rh_evaluations (legado) -> avaliacoes_desempenho_rh · 0 linhas reais no backup
        Schema::create('avaliacoes_desempenho_rh', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: rh_company_id · do código legado js/modules/rh/avaliacao.js:654');
            $table->bigInteger('ciclo_avaliacao_id')->nullable()->comment('legado: ciclo_id · do código legado js/modules/rh/aval360_dados.js:290');
            $table->decimal('nota_360', 5, 2)->nullable()->comment('legado: nota_360 · do código legado js/modules/rh/aval360_dados.js:290');
            $table->string('classificacao_360', 30)->nullable()->comment('legado: classificacao_360 · do código legado js/modules/rh/aval360_dados.js:290');
            $table->jsonb('componentes_360')->nullable()->comment('legado: componentes_360 · do código legado js/modules/rh/aval360_dados.js:290');
            $table->jsonb('avisos_360')->nullable()->comment('legado: avisos_360 · do código legado js/modules/rh/aval360_dados.js:290');
            $table->timestampTz('atualizado_360_em')->nullable()->comment('legado: actualizado_360_em · do código legado js/modules/rh/aval360_dados.js:290');
            $table->jsonb('conhecimento')->nullable()->comment('legado: conhecimento · do código legado js/modules/rh/aval360_dados.js:317');
            $table->text('comentario_colaborador')->nullable()->comment('legado: comentario_colaborador · do código legado js/modules/rh/aval360_dados.js:317');
            $table->jsonb('contestacao')->nullable()->comment('legado: contestacao · do código legado js/modules/rh/aval360_dados.js:343');
            $table->bigInteger('colaborador_id')->nullable()->comment('legado: employee_id · do código legado js/modules/rh/avaliacao.js:654');
            $table->integer('ano')->nullable()->comment('legado: ano · do código legado js/modules/rh/avaliacao.js:654');
            $table->string('periodo', 10)->nullable()->comment('legado: periodo · do código legado js/modules/rh/avaliacao.js:654');
            $table->jsonb('criterios')->nullable()->comment('legado: criterios · do código legado js/modules/rh/avaliacao.js:655');
            $table->jsonb('objetivos')->nullable()->comment('legado: objectivos · do código legado js/modules/rh/avaliacao.js:655');
            $table->decimal('peso_objetivos', 5, 2)->nullable()->comment('legado: peso_objectivos · do código legado js/modules/rh/avaliacao.js:655');
            $table->decimal('pontuacao', 5, 2)->nullable()->comment('legado: pontuacao · do código legado js/modules/rh/avaliacao.js:656');
            $table->decimal('pontuacao_criterios', 5, 2)->nullable()->comment('legado: pontuacao_criterios · do código legado js/modules/rh/avaliacao.js:656');
            $table->decimal('pontuacao_objetivos', 5, 2)->nullable()->comment('legado: pontuacao_objectivos · do código legado js/modules/rh/avaliacao.js:656');
            $table->string('classificacao', 30)->nullable()->comment('legado: classificacao · do código legado js/modules/rh/avaliacao.js:657');
            $table->string('estado', 20)->nullable()->comment('legado: status · do código legado js/modules/rh/avaliacao.js:658');
            $table->string('avaliador', 255)->nullable()->comment('legado: avaliador · do código legado js/modules/rh/avaliacao.js:659');
            $table->date('data_avaliacao')->nullable()->comment('legado: data_avaliacao · do código legado js/modules/rh/avaliacao.js:659');
            $table->text('pontos_fortes')->nullable()->comment('legado: pontos_fortes · do código legado js/modules/rh/avaliacao.js:660');
            $table->text('pontos_melhorar')->nullable()->comment('legado: pontos_melhorar · do código legado js/modules/rh/avaliacao.js:660');
            $table->text('plano_desenvolvimento')->nullable()->comment('legado: plano_desenvolvimento · do código legado js/modules/rh/avaliacao.js:661');
            $table->timestampTz('concluida_em')->nullable()->comment('legado: concluida_em · do código legado js/modules/rh/avaliacao.js:662');
            $table->string('concluida_por', 255)->nullable()->comment('legado: concluida_por · do código legado js/modules/rh/avaliacao.js:663');
            $table->timestampTz('reaberta_em')->nullable()->comment('legado: reaberta_em · do código legado js/modules/rh/avaliacao.js:713');
            $table->string('reaberta_por', 255)->nullable()->comment('legado: reaberta_por · do código legado js/modules/rh/avaliacao.js:713');
            $table->timestampTz('atualizado_em')->nullable()->useCurrent()->comment('legado: updated_at · do código legado js/modules/rh/avaliacao.js:664');
            $table->timestampTz('criado_em')->nullable()->useCurrent()->comment('legado: created_at · do código legado js/modules/rh/avaliacao.js:668');
        });
        DB::statement('CREATE UNIQUE INDEX uq_avaliacoes_desempenho_rh_empresa__colabora_ano_periodo ON avaliacoes_desempenho_rh (empresa_id, colaborador_id, ano, periodo)');
        DB::statement('CREATE INDEX ix_avaliacoes_desempenho_rh_ciclo_avaliacao_id ON avaliacoes_desempenho_rh (ciclo_avaliacao_id)');
        DB::statement('CREATE INDEX ix_avaliacoes_desempenho_rh_colaborador_id ON avaliacoes_desempenho_rh (colaborador_id)');

        // rh_evaluation_items (legado) -> criterios_avaliacao_rh · 26 linhas reais no backup
        Schema::create('criterios_avaliacao_rh', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: rh_company_id');
            $table->string('ambito', 20)->nullable()->comment('legado: ambito · código normalizado ∈ {COMUM, ESPECIFICO}; texto original em ambito_original');
            $table->string('ambito_original', 100)->nullable()->comment('legado: ambito · texto exacto do legado');
            $table->bigInteger('colaborador_id')->nullable()->comment('legado: employee_id');
            $table->string('tipo', 20)->nullable()->comment('legado: tipo · código normalizado ∈ {CRITERIO, OBJECTIVO}; texto original em tipo_original');
            $table->string('tipo_original', 100)->nullable()->comment('legado: tipo · texto exacto do legado');
            $table->string('chave', 150)->nullable()->comment('legado: chave');
            $table->string('nome', 255)->nullable()->comment('legado: nome');
            $table->text('descricao')->nullable()->comment('legado: descricao');
            $table->decimal('peso', 9, 4)->nullable()->comment('legado: peso');
            $table->integer('ordem')->nullable()->comment('legado: ordem');
            $table->boolean('ativo')->nullable()->comment('legado: activo');
            $table->string('natureza', 20)->nullable()->comment('legado: natureza');
            $table->decimal('meta', 15, 3)->nullable()->comment('legado: meta · tipo forçado (inferido: integer)');
            $table->text('unidade')->nullable()->comment('legado: unidade · sem valores reais: tipo a confirmar no código legado');
            $table->string('sentido', 10)->nullable()->comment('legado: sentido');
            $table->timestampTz('criado_em')->nullable()->useCurrent()->comment('legado: created_at');
            $table->timestampTz('atualizado_em')->nullable()->useCurrent()->comment('legado: updated_at');
        });
        DB::statement('CREATE INDEX ix_criterios_avaliacao_rh_empresa_id ON criterios_avaliacao_rh (empresa_id)');
        DB::statement('CREATE INDEX ix_criterios_avaliacao_rh_colaborador_id ON criterios_avaliacao_rh (colaborador_id)');
        DB::statement('ALTER TABLE criterios_avaliacao_rh ADD CONSTRAINT ck_criterios_avaliacao_rh_ambito CHECK (ambito IS NULL OR ambito IN (\'COMUM\',\'ESPECIFICO\'))');
        DB::statement('ALTER TABLE criterios_avaliacao_rh ADD CONSTRAINT ck_criterios_avaliacao_rh_tipo CHECK (tipo IS NULL OR tipo IN (\'CRITERIO\',\'OBJECTIVO\'))');

        // rh_eval_cycles (legado) -> ciclos_avaliacao_360 · 2 linhas reais no backup
        Schema::create('ciclos_avaliacao_360', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: rh_company_id');
            $table->string('nome', 255)->nullable()->comment('legado: nome');
            $table->integer('ano')->nullable()->comment('legado: ano');
            $table->string('periodo', 10)->nullable()->comment('legado: periodo');
            $table->date('data_inicio')->nullable()->comment('legado: data_inicio');
            $table->date('data_fim')->nullable()->comment('legado: data_fim');
            $table->string('estado', 20)->nullable()->comment('legado: estado · código normalizado ∈ {RASCUNHO, ABERTO, FECHADO}; texto original em estado_original');
            $table->string('estado_original', 100)->nullable()->comment('legado: estado · texto exacto do legado');
            $table->jsonb('prazos')->nullable()->comment('legado: prazos');
            $table->jsonb('pesos')->nullable()->comment('legado: pesos');
            $table->integer('minimo_anonimato')->nullable()->comment('legado: minimo_anonimato');
            $table->integer('max_pares')->nullable()->comment('legado: max_pares');
            $table->jsonb('feedback')->nullable()->comment('legado: feedback');
            $table->jsonb('bonificacao')->nullable()->comment('legado: bonificacao');
            $table->jsonb('comunicado')->nullable()->comment('legado: comunicado');
            $table->string('atualizado_por', 100)->nullable()->comment('legado: actualizado_por');
            $table->string('criado_por', 100)->nullable()->comment('legado: criado_por');
            $table->jsonb('participantes')->nullable()->comment('legado: participantes');
            $table->jsonb('criterios')->nullable()->comment('legado: criterios');
            $table->timestampTz('aberto_em')->nullable()->comment('legado: aberto_em');
            $table->string('aberto_por', 100)->nullable()->comment('legado: aberto_por');
            $table->timestampTz('fechado_em')->nullable()->comment('legado: fechado_em · do código legado js/modules/rh/aval360_dados.js:184');
            $table->string('fechado_por', 255)->nullable()->comment('legado: fechado_por · do código legado js/modules/rh/aval360_dados.js:184');
            $table->timestampTz('atualizado_em')->nullable()->useCurrent()->comment('legado: actualizado_em');
            $table->timestampTz('criado_em')->nullable()->useCurrent()->comment('legado: criado_em');
        });
        DB::statement('CREATE UNIQUE INDEX uq_ciclos_avaliacao_360_empresa_id_ano_periodo ON ciclos_avaliacao_360 (empresa_id, ano, periodo)');
        DB::statement('CREATE UNIQUE INDEX uq_ciclos_avaliacao_360_empresa_id ON ciclos_avaliacao_360 (empresa_id) WHERE estado = \'ABERTO\'');
        DB::statement('ALTER TABLE ciclos_avaliacao_360 ADD CONSTRAINT ck_ciclos_avaliacao_360_estado CHECK (estado IS NULL OR estado IN (\'RASCUNHO\',\'ABERTO\',\'FECHADO\'))');

        // rh_eval_360_part (legado) -> participantes_avaliacao_360 · 1 linhas reais no backup
        Schema::create('participantes_avaliacao_360', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: rh_company_id');
            $table->string('uid', 100)->nullable()->comment('legado: uid');
            $table->bigInteger('ciclo_avaliacao_id')->nullable()->comment('legado: ciclo_id');
            $table->bigInteger('colaborador_avaliador_id')->nullable()->comment('legado: avaliador_employee_id');
            $table->bigInteger('colaborador_avaliado_id')->nullable()->comment('legado: avaliado_employee_id');
            $table->string('grupo', 20)->nullable()->comment('legado: grupo');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE UNIQUE INDEX uq_participantes_avaliacao_360_ciclo_av_colabora_colabora ON participantes_avaliacao_360 (ciclo_avaliacao_id, colaborador_avaliador_id, colaborador_avaliado_id)');
        DB::statement('CREATE INDEX ix_participantes_avaliacao_360_empresa_id ON participantes_avaliacao_360 (empresa_id)');
        DB::statement('CREATE INDEX ix_participantes_avaliacao_360_colaborador_avaliador_id ON participantes_avaliacao_360 (colaborador_avaliador_id)');
        DB::statement('CREATE INDEX ix_participantes_avaliacao_360_colaborador_avaliado_id ON participantes_avaliacao_360 (colaborador_avaliado_id)');

        // rh_eval_360_resp (legado) -> respostas_avaliacao_360 · 1 linhas reais no backup
        Schema::create('respostas_avaliacao_360', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: rh_company_id');
            $table->string('uid', 100)->nullable()->comment('legado: uid');
            $table->bigInteger('ciclo_avaliacao_id')->nullable()->comment('legado: ciclo_id');
            $table->bigInteger('colaborador_avaliado_id')->nullable()->comment('legado: avaliado_employee_id');
            $table->string('grupo', 20)->nullable()->comment('legado: grupo');
            $table->jsonb('notas')->nullable()->comment('legado: notas');
            $table->text('comentario')->nullable()->comment('legado: comentario · sem valores reais: tipo a confirmar no código legado');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_respostas_avaliacao_360_empresa_id ON respostas_avaliacao_360 (empresa_id)');
        DB::statement('CREATE INDEX ix_respostas_avaliacao_360_ciclo_avaliacao_id ON respostas_avaliacao_360 (ciclo_avaliacao_id)');
        DB::statement('CREATE INDEX ix_respostas_avaliacao_360_colaborador_avaliado_id ON respostas_avaliacao_360 (colaborador_avaliado_id)');

        // rh_eval_feedback (legado) -> feedbacks_avaliacao_360 · 1 linhas reais no backup
        Schema::create('feedbacks_avaliacao_360', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: rh_company_id');
            $table->bigInteger('ciclo_avaliacao_id')->nullable()->comment('legado: ciclo_id');
            $table->bigInteger('colaborador_id')->nullable()->comment('legado: employee_id');
            $table->bigInteger('colaborador_chefia_id')->nullable()->comment('legado: chefia_employee_id');
            $table->string('periodo_referencia', 20)->nullable()->comment('legado: periodo_ref');
            $table->date('data')->nullable()->comment('legado: data');
            $table->jsonb('objetivos')->nullable()->comment('legado: objectivos');
            $table->text('positivos')->nullable()->comment('legado: positivos');
            $table->text('melhorar')->nullable()->comment('legado: melhorar');
            $table->text('acordos')->nullable()->comment('legado: acordos · tipo forçado (inferido: varchar(50))');
            $table->string('registado_por', 100)->nullable()->comment('legado: registado_por');
            $table->timestampTz('registado_em')->nullable()->comment('legado: registado_em');
            $table->jsonb('confirmacao')->nullable()->comment('legado: confirmacao · do código legado js/modules/rh/aval360_dados.js:426');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_feedbacks_avaliacao_360_empresa_id ON feedbacks_avaliacao_360 (empresa_id)');
        DB::statement('CREATE INDEX ix_feedbacks_avaliacao_360_ciclo_avaliacao_id ON feedbacks_avaliacao_360 (ciclo_avaliacao_id)');
        DB::statement('CREATE INDEX ix_feedbacks_avaliacao_360_colaborador_id ON feedbacks_avaliacao_360 (colaborador_id)');
        DB::statement('CREATE INDEX ix_feedbacks_avaliacao_360_colaborador_chefia_id ON feedbacks_avaliacao_360 (colaborador_chefia_id)');

        // rh_eval_bonus (legado) -> bonificacoes_avaliacao_rh · 0 linhas reais no backup
        Schema::create('bonificacoes_avaliacao_rh', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: rh_company_id · do código legado js/modules/rh/aval360_dados.js:474');
            $table->bigInteger('ciclo_avaliacao_id')->nullable()->comment('legado: ciclo_id · do código legado js/modules/rh/aval360_dados.js:474');
            $table->bigInteger('colaborador_id')->nullable()->comment('legado: employee_id · do código legado js/modules/rh/aval360_dados.js:474');
            $table->bigInteger('avaliacao_desempenho_id')->nullable()->comment('legado: avaliacao_id · do código legado js/modules/rh/aval360_dados.js:474');
            $table->string('metodo', 20)->nullable()->comment('legado: metodo · do código legado js/modules/rh/aval360_dados.js:474');
            $table->string('classificacao', 30)->nullable()->comment('legado: classificacao · do código legado js/modules/rh/aval360_dados.js:474');
            $table->decimal('nota', 5, 2)->nullable()->comment('legado: nota · do código legado js/modules/rh/aval360_dados.js:474');
            $table->decimal('base', 15, 2)->nullable()->comment('legado: base · do código legado js/modules/rh/aval360_dados.js:474');
            $table->decimal('valor', 15, 2)->nullable()->comment('legado: valor · do código legado js/modules/rh/aval360_dados.js:474');
            $table->string('estado', 20)->nullable()->comment('legado: estado · do código legado js/modules/rh/aval360_dados.js:474');
            $table->string('calculado_por', 255)->nullable()->comment('legado: calculado_por · do código legado js/modules/rh/aval360_dados.js:474');
            $table->timestampTz('calculado_em')->nullable()->comment('legado: calculado_em · do código legado js/modules/rh/aval360_dados.js:474');
            $table->string('aprovado_por', 255)->nullable()->comment('legado: aprovado_por · do código legado js/modules/rh/aval360_dados.js:487');
            $table->timestampTz('aprovado_em')->nullable()->comment('legado: aprovado_em · do código legado js/modules/rh/aval360_dados.js:487');
            $table->bigInteger('linha_folha_salarial_id')->nullable()->comment('legado: payroll_entry_id · do código legado js/modules/rh/aval360_dados.js:506');
            $table->bigInteger('periodo_processamento_salarial_id')->nullable()->comment('legado: period_id · do código legado js/modules/rh/aval360_dados.js:506');
            $table->string('lancado_por', 255)->nullable()->comment('legado: lancado_por · do código legado js/modules/rh/aval360_dados.js:506');
            $table->timestampTz('lancado_em')->nullable()->comment('legado: lancado_em · do código legado js/modules/rh/aval360_dados.js:506');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_bonificacoes_avaliacao_rh_empresa_id ON bonificacoes_avaliacao_rh (empresa_id)');
        DB::statement('CREATE INDEX ix_bonificacoes_avaliacao_rh_ciclo_avaliacao_id ON bonificacoes_avaliacao_rh (ciclo_avaliacao_id)');
        DB::statement('CREATE INDEX ix_bonificacoes_avaliacao_rh_colaborador_id ON bonificacoes_avaliacao_rh (colaborador_id)');
        DB::statement('CREATE INDEX ix_bonificacoes_avaliacao_rh_avaliacao_desempenho_id ON bonificacoes_avaliacao_rh (avaliacao_desempenho_id)');
        DB::statement('CREATE INDEX ix_bonificacoes_avaliacao_rh_linha_folha_salarial_id ON bonificacoes_avaliacao_rh (linha_folha_salarial_id)');
        DB::statement('CREATE INDEX ix_bonificacoes_avaliacao_rh_periodo_processamento_salarial_id ON bonificacoes_avaliacao_rh (periodo_processamento_salarial_id)');

        // rh_eval_ack (legado) -> confirmacoes_avaliacao_rh · 1 linhas reais no backup
        Schema::create('confirmacoes_avaliacao_rh', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: rh_company_id');
            $table->bigInteger('ciclo_avaliacao_id')->nullable()->comment('legado: ciclo_id');
            $table->bigInteger('colaborador_id')->nullable()->comment('legado: employee_id');
            $table->timestampTz('em')->nullable()->comment('legado: em');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE UNIQUE INDEX uq_confirmacoes_avaliacao_rh_ciclo_avaliacao_id_colaborador_id ON confirmacoes_avaliacao_rh (ciclo_avaliacao_id, colaborador_id)');
        DB::statement('CREATE INDEX ix_confirmacoes_avaliacao_rh_empresa_id ON confirmacoes_avaliacao_rh (empresa_id)');
        DB::statement('CREATE INDEX ix_confirmacoes_avaliacao_rh_colaborador_id ON confirmacoes_avaliacao_rh (colaborador_id)');

        // rh_portal_requests (legado) -> pedidos_portal_colaborador · 9 linhas reais no backup
        Schema::create('pedidos_portal_colaborador', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: rh_company_id');
            $table->bigInteger('colaborador_id')->nullable()->comment('legado: employee_id');
            $table->string('tipo', 20)->nullable()->comment('legado: tipo · código normalizado ∈ {FERIAS, AUSENCIA, DOCUMENTO, AGREGADO}; texto original em tipo_original');
            $table->string('tipo_original', 100)->nullable()->comment('legado: tipo · texto exacto do legado');
            $table->jsonb('dados')->nullable()->comment('legado: dados');
            $table->jsonb('etapas')->nullable()->comment('legado: etapas');
            $table->string('estado', 20)->nullable()->comment('legado: estado · código normalizado ∈ {PENDENTE_CHEFIA, PENDENTE_RH, APROVADO, EMITIDO, RECUSADO, CANCELADO}; texto original em estado_original');
            $table->string('estado_original', 100)->nullable()->comment('legado: estado · texto exacto do legado');
            $table->string('criado_por', 100)->nullable()->comment('legado: criado_por');
            $table->bigInteger('plano_ferias_colaborador_id')->nullable()->comment('legado: vacation_id');
            $table->timestampTz('decidido_em')->nullable()->comment('legado: decidido_em');
            $table->jsonb('documento')->nullable()->comment('legado: documento');
            $table->bigInteger('ausencia_falta_id')->nullable()->comment('legado: absence_id · do código legado js/modules/rh/portal_dados.js:170');
            $table->timestampTz('cancelado_em')->nullable()->comment('legado: cancelado_em · do código legado js/modules/rh/portal_dados.js:205');
            $table->timestampTz('criado_em')->nullable()->useCurrent()->comment('legado: criado_em');
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_pedidos_portal_colaborador_empresa_id ON pedidos_portal_colaborador (empresa_id)');
        DB::statement('CREATE INDEX ix_pedidos_portal_colaborador_colaborador_id ON pedidos_portal_colaborador (colaborador_id)');
        DB::statement('CREATE INDEX ix_pedidos_portal_colaborador_plano_ferias_colaborador_id ON pedidos_portal_colaborador (plano_ferias_colaborador_id)');
        DB::statement('CREATE INDEX ix_pedidos_portal_colaborador_ausencia_falta_id ON pedidos_portal_colaborador (ausencia_falta_id)');
        DB::statement('ALTER TABLE pedidos_portal_colaborador ADD CONSTRAINT ck_pedidos_portal_colaborador_tipo CHECK (tipo IS NULL OR tipo IN (\'FERIAS\',\'AUSENCIA\',\'DOCUMENTO\',\'AGREGADO\'))');
        DB::statement('ALTER TABLE pedidos_portal_colaborador ADD CONSTRAINT ck_pedidos_portal_colaborador_estado CHECK (estado IS NULL OR estado IN (\'PENDENTE_CHEFIA\',\'PENDENTE_RH\',\'APROVADO\',\'EMITIDO\',\'RECUSADO\',\'CANCELADO\'))');

        // rh_self_evaluations (legado) -> autoavaliacoes_colaborador · 1 linhas reais no backup
        Schema::create('autoavaliacoes_colaborador', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: rh_company_id');
            $table->bigInteger('colaborador_id')->nullable()->comment('legado: employee_id');
            $table->integer('ano')->nullable()->comment('legado: ano');
            $table->string('periodo', 10)->nullable()->comment('legado: periodo');
            $table->jsonb('criterios')->nullable()->comment('legado: criterios');
            $table->jsonb('objetivos')->nullable()->comment('legado: objectivos');
            $table->text('realizacoes')->nullable()->comment('legado: realizacoes');
            $table->text('dificuldades')->nullable()->comment('legado: dificuldades');
            $table->text('formacao')->nullable()->comment('legado: formacao · tipo forçado (inferido: varchar(10))');
            $table->string('estado', 20)->nullable()->comment('legado: status · código normalizado ∈ {RASCUNHO, SUBMETIDA}; texto original em estado_original');
            $table->string('estado_original', 100)->nullable()->comment('legado: status · texto exacto do legado');
            $table->timestampTz('submetida_em')->nullable()->comment('legado: submetida_em');
            $table->timestampTz('atualizado_em')->nullable()->useCurrent()->comment('legado: actualizado_em');
            $table->timestampTz('criado_em')->nullable()->useCurrent()->comment('legado: criado_em');
        });
        DB::statement('CREATE UNIQUE INDEX uq_autoavaliacoes_colaborador_empresa__colabora_ano_periodo ON autoavaliacoes_colaborador (empresa_id, colaborador_id, ano, periodo)');
        DB::statement('CREATE INDEX ix_autoavaliacoes_colaborador_colaborador_id ON autoavaliacoes_colaborador (colaborador_id)');
        DB::statement('ALTER TABLE autoavaliacoes_colaborador ADD CONSTRAINT ck_autoavaliacoes_colaborador_estado CHECK (estado IS NULL OR estado IN (\'RASCUNHO\',\'SUBMETIDA\'))');

        // rh_upward_participation (legado) -> participacoes_ascendentes_rh · 0 linhas reais no backup
        Schema::create('participacoes_ascendentes_rh', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: rh_company_id · do código legado js/modules/rh/portal_dados.js:456');
            $table->string('uid', 255)->nullable()->comment('legado: uid · do código legado js/modules/rh/portal_dados.js:456');
            $table->bigInteger('colaborador_id')->nullable()->comment('legado: employee_id · do código legado js/modules/rh/portal_dados.js:456');
            $table->bigInteger('colaborador_alvo_id')->nullable()->comment('legado: alvo_employee_id · do código legado js/modules/rh/portal_dados.js:456');
            $table->integer('ano')->nullable()->comment('legado: ano · do código legado js/modules/rh/portal_dados.js:456');
            $table->string('periodo', 10)->nullable()->comment('legado: periodo · do código legado js/modules/rh/portal_dados.js:456');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE UNIQUE INDEX uq_participacoes_ascendentes_rh_empresa__colabora_colabora_ano_ ON participacoes_ascendentes_rh (empresa_id, colaborador_id, colaborador_alvo_id, ano, periodo)');
        DB::statement('CREATE INDEX ix_participacoes_ascendentes_rh_colaborador_id ON participacoes_ascendentes_rh (colaborador_id)');
        DB::statement('CREATE INDEX ix_participacoes_ascendentes_rh_colaborador_alvo_id ON participacoes_ascendentes_rh (colaborador_alvo_id)');

        // rh_upward_responses (legado) -> respostas_ascendentes_rh · 0 linhas reais no backup
        Schema::create('respostas_ascendentes_rh', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: rh_company_id · do código legado js/modules/rh/portal_dados.js:457');
            $table->string('uid', 255)->nullable()->comment('legado: uid · do código legado js/modules/rh/portal_dados.js:457');
            $table->bigInteger('colaborador_alvo_id')->nullable()->comment('legado: alvo_employee_id · do código legado js/modules/rh/portal_dados.js:457');
            $table->integer('ano')->nullable()->comment('legado: ano · do código legado js/modules/rh/portal_dados.js:457');
            $table->string('periodo', 10)->nullable()->comment('legado: periodo · do código legado js/modules/rh/portal_dados.js:457');
            $table->jsonb('respostas')->nullable()->comment('legado: respostas · do código legado js/modules/rh/portal_dados.js:457');
            $table->text('comentario')->nullable()->comment('legado: comentario · do código legado js/modules/rh/portal_dados.js:457');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_respostas_ascendentes_rh_empresa_id ON respostas_ascendentes_rh (empresa_id)');
        DB::statement('CREATE INDEX ix_respostas_ascendentes_rh_colaborador_alvo_id ON respostas_ascendentes_rh (colaborador_alvo_id)');

        // rh_doc_templates (legado) -> modelos_documentos_rh · 0 linhas reais no backup
        Schema::create('modelos_documentos_rh', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: rh_company_id · do código legado js/modules/rh/portal_dados.js:310');
            $table->string('codigo', 50)->nullable()->comment('legado: codigo · do código legado js/modules/rh/portal_dados.js:310');
            $table->string('nome', 255)->nullable()->comment('legado: nome · do código legado js/modules/rh/portal_dados.js:310');
            $table->string('titulo', 255)->nullable()->comment('legado: titulo · do código legado js/modules/rh/portal_dados.js:310');
            $table->text('texto')->nullable()->comment('legado: texto · do código legado js/modules/rh/portal_dados.js:310');
            $table->boolean('ativo')->nullable()->comment('legado: activo · do código legado js/modules/rh/portal_dados.js:310');
            $table->boolean('auto_emitir')->nullable()->comment('legado: auto_emitir · do código legado js/modules/rh/portal_dados.js:310');
            $table->string('assinante', 255)->nullable()->comment('legado: assinante · do código legado js/modules/rh/portal_dados.js:310');
            $table->string('cargo_assinante', 255)->nullable()->comment('legado: cargo_assinante · do código legado js/modules/rh/portal_dados.js:310');
            $table->string('local', 255)->nullable()->comment('legado: local · do código legado js/modules/rh/portal_dados.js:310');
            $table->string('atualizado_por', 255)->nullable()->comment('legado: actualizado_por · do código legado js/modules/rh/portal_dados.js:310');
            $table->timestampTz('atualizado_em')->nullable()->useCurrent()->comment('legado: actualizado_em · do código legado js/modules/rh/portal_dados.js:310');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE UNIQUE INDEX uq_modelos_documentos_rh_empresa_id_codigo ON modelos_documentos_rh (empresa_id, codigo)');

        // org_units (legado) -> unidades_organicas · 16 linhas reais no backup · eliminação lógica
        Schema::create('unidades_organicas', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: org_company_id');
            $table->string('codigo', 50)->nullable()->comment('legado: codigo');
            $table->string('nome', 255)->nullable()->comment('legado: nome');
            $table->string('tipo', 20)->nullable()->comment('legado: tipo · código normalizado ∈ {ORGAO_SOCIAL, DIRECCAO_GERAL, DIRECCAO, DEPARTAMENTO, GABINETE, SECCAO, EQUIPA, OUTRO}; texto original em tipo_original');
            $table->string('tipo_original', 100)->nullable()->comment('legado: tipo · texto exacto do legado');
            $table->bigInteger('unidade_organica_pai_id')->nullable()->comment('legado: pai_id');
            $table->bigInteger('colaborador_responsavel_id')->nullable()->comment('legado: responsavel_employee_id');
            $table->string('utilizador_responsavel', 100)->nullable()->comment('legado: utilizador_responsavel · tipo forçado (inferido: varchar(10))');
            $table->jsonb('utilizadores')->nullable()->comment('legado: utilizadores');
            $table->text('missao')->nullable()->comment('legado: missao · sem valores reais: tipo a confirmar no código legado');
            $table->text('atribuicoes')->nullable()->comment('legado: atribuicoes');
            $table->bigInteger('centro_custo_id')->nullable()->comment('legado: cost_center_id');
            $table->bigInteger('unidade_negocio_id')->nullable()->comment('legado: business_unit_id');
            $table->integer('ordem')->nullable()->comment('legado: ordem');
            $table->boolean('ativo')->nullable()->comment('legado: activo');
            $table->integer('apoio')->nullable()->comment('legado: apoio');
            $table->string('cor', 20)->nullable()->comment('legado: cor');
            $table->string('atualizado_por', 100)->nullable()->comment('legado: actualizado_por');
            $table->string('criado_por', 100)->nullable()->comment('legado: criado_por');
            $table->string('disposicao', 255)->nullable()->comment('legado: disposicao · do código legado js/modules/estrutura/estrutura_ui.js:400');
            $table->timestampTz('atualizado_em')->nullable()->useCurrent()->comment('legado: actualizado_em');
            $table->timestampTz('criado_em')->nullable()->useCurrent()->comment('legado: criado_em');
            $table->timestampTz('eliminado_em')->nullable();
        });
        DB::statement('CREATE UNIQUE INDEX uq_unidades_organicas_empresa_id_codigo ON unidades_organicas (empresa_id, codigo) WHERE eliminado_em IS NULL');
        DB::statement('CREATE INDEX ix_unidades_organicas_unidade_organica_pai_id ON unidades_organicas (unidade_organica_pai_id)');
        DB::statement('CREATE INDEX ix_unidades_organicas_colaborador_responsavel_id ON unidades_organicas (colaborador_responsavel_id)');
        DB::statement('CREATE INDEX ix_unidades_organicas_centro_custo_id ON unidades_organicas (centro_custo_id)');
        DB::statement('CREATE INDEX ix_unidades_organicas_unidade_negocio_id ON unidades_organicas (unidade_negocio_id)');
        DB::statement('ALTER TABLE unidades_organicas ADD CONSTRAINT ck_unidades_organicas_tipo CHECK (tipo IS NULL OR tipo IN (\'ORGAO_SOCIAL\',\'DIRECCAO_GERAL\',\'DIRECCAO\',\'DEPARTAMENTO\',\'GABINETE\',\'SECCAO\',\'EQUIPA\',\'OUTRO\'))');

        // org_positions (legado) -> postos_trabalho · 9 linhas reais no backup · eliminação lógica
        Schema::create('postos_trabalho', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: org_company_id');
            $table->bigInteger('unidade_organica_id')->nullable()->comment('legado: unit_id');
            $table->bigInteger('cargo_funcao_id')->nullable()->comment('legado: role_id');
            $table->string('titulo', 255)->nullable()->comment('legado: titulo');
            $table->integer('vagas')->nullable()->comment('legado: vagas');
            $table->bigInteger('posto_superior_id')->nullable()->comment('legado: reporta_a_position_id');
            $table->text('responsabilidades')->nullable()->comment('legado: responsabilidades');
            $table->integer('chefia')->nullable()->comment('legado: chefia');
            $table->integer('ordem')->nullable()->comment('legado: ordem');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
            $table->timestampTz('eliminado_em')->nullable();
        });
        DB::statement('CREATE INDEX ix_postos_trabalho_empresa_id ON postos_trabalho (empresa_id)');
        DB::statement('CREATE INDEX ix_postos_trabalho_unidade_organica_id ON postos_trabalho (unidade_organica_id)');
        DB::statement('CREATE INDEX ix_postos_trabalho_cargo_funcao_id ON postos_trabalho (cargo_funcao_id)');
        DB::statement('CREATE INDEX ix_postos_trabalho_posto_superior_id ON postos_trabalho (posto_superior_id)');

        // resultados_folha_salarial (tabela nova) · 0 linhas reais no backup
        Schema::create('resultados_folha_salarial', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('tenant (derivado no ETL)');
            $table->bigInteger('periodo_processamento_salarial_id')->nullable()->comment('Período');
            $table->bigInteger('colaborador_id')->nullable()->comment('Colaborador');
            $table->bigInteger('tipo_organizacao_id')->nullable()->comment('Tipo de organização (mapeamento contabilístico)');
            $table->bigInteger('unidade_negocio_id')->nullable();
            $table->bigInteger('centro_custo_id')->nullable();
            $table->boolean('avencado')->nullable();
            $table->boolean('reformado')->nullable();
            $table->decimal('dias_contrato', 6, 2)->nullable();
            $table->decimal('dias_trabalhados', 6, 2)->nullable();
            $table->decimal('bruto', 15, 2)->nullable();
            $table->decimal('base_inss', 15, 2)->nullable();
            $table->decimal('inss_trabalhador', 15, 2)->nullable();
            $table->decimal('inss_patronal', 15, 2)->nullable();
            $table->decimal('isencoes', 15, 2)->nullable()->comment('Isenções de IRT (subsídios até 30 000 Kz) e faltas');
            $table->decimal('base_irt', 15, 2)->nullable();
            $table->decimal('irt', 15, 2)->nullable();
            $table->decimal('descontos', 15, 2)->nullable();
            $table->decimal('liquido', 15, 2)->nullable();
            $table->jsonb('rubricas')->nullable()->comment('Detalhe por rubrica calculada');
            $table->jsonb('avisos')->nullable();
            $table->string('modo_calculo', 10)->nullable()->comment('ATUAL ou LEGADO');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE UNIQUE INDEX uq_resultados_folha_salarial_periodo__colabora ON resultados_folha_salarial (periodo_processamento_salarial_id, colaborador_id)');
        DB::statement('CREATE INDEX ix_resultados_folha_salarial_empresa_id ON resultados_folha_salarial (empresa_id)');
        DB::statement('CREATE INDEX ix_resultados_folha_salarial_colaborador_id ON resultados_folha_salarial (colaborador_id)');

        // configuracoes_rh (tabela nova) · 0 linhas reais no backup
        Schema::create('configuracoes_rh', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('tenant (derivado no ETL)');
            $table->string('chave', 150)->comment('Chave (ver ServicoConfiguracaoRH::PADRAO)');
            $table->jsonb('valor')->nullable()->comment('Valor');
            $table->string('atualizado_por', 100)->nullable()->comment('Quem alterou');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE UNIQUE INDEX uq_configuracoes_rh_empresa_id_chave ON configuracoes_rh (empresa_id, chave)');
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracoes_rh');
        Schema::dropIfExists('resultados_folha_salarial');
        Schema::dropIfExists('postos_trabalho');
        Schema::dropIfExists('unidades_organicas');
        Schema::dropIfExists('modelos_documentos_rh');
        Schema::dropIfExists('respostas_ascendentes_rh');
        Schema::dropIfExists('participacoes_ascendentes_rh');
        Schema::dropIfExists('autoavaliacoes_colaborador');
        Schema::dropIfExists('pedidos_portal_colaborador');
        Schema::dropIfExists('confirmacoes_avaliacao_rh');
        Schema::dropIfExists('bonificacoes_avaliacao_rh');
        Schema::dropIfExists('feedbacks_avaliacao_360');
        Schema::dropIfExists('respostas_avaliacao_360');
        Schema::dropIfExists('participantes_avaliacao_360');
        Schema::dropIfExists('ciclos_avaliacao_360');
        Schema::dropIfExists('criterios_avaliacao_rh');
        Schema::dropIfExists('avaliacoes_desempenho_rh');
        Schema::dropIfExists('registos_produtividade_rh');
        Schema::dropIfExists('periodos_produtividade_rh');
        Schema::dropIfExists('itens_produtividade_rh');
        Schema::dropIfExists('fechos_mensais_assiduidade');
        Schema::dropIfExists('configuracoes_assiduidade');
        Schema::dropIfExists('efectividade_assiduidade');
        Schema::dropIfExists('ausencias_faltas_colaboradores');
        Schema::dropIfExists('plano_ferias_colaboradores');
        Schema::dropIfExists('habilitacoes_colaboradores');
        Schema::dropIfExists('dependentes_colaboradores');
        Schema::dropIfExists('itens_carta_pagamento');
        Schema::dropIfExists('cartas_pagamento_bancario');
        Schema::dropIfExists('coordenadas_bancarias_colaboradores');
        Schema::dropIfExists('bancos');
        Schema::dropIfExists('mapeamentos_contabeis_sistema_rh');
        Schema::dropIfExists('mapeamentos_contabeis_rh');
        Schema::dropIfExists('linhas_folha_salarial');
        Schema::dropIfExists('periodos_processamento_salarial');
        Schema::dropIfExists('contratos_trabalho');
        Schema::dropIfExists('infotipos_salariais');
        Schema::dropIfExists('tipos_organizacao_rh');
        Schema::dropIfExists('cargos_funcoes');
        Schema::dropIfExists('colaboradores');
    }
};
