<?php

use App\Support\Tenancy\IsolamentoRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gestão Imobiliária, Onda 2 (F3): contratos de arrendamento, as fracções que abrangem, os encargos, as rendas do período, o
 * trilho de eventos do contrato, o ledger da caução e a configuração de contabilização por empresa. Só tabelas NOVAS,
 * aditivas e idempotentes (`hasTable`, `CREATE … IF NOT EXISTS`, CHECK com `pg_constraint`); nenhuma tabela existente é
 * alterada. Não exige `btree_gist`: a sobreposição de contratos por fracção e lado verifica-se no serviço, com as fracções
 * bloqueadas (FOR UPDATE), e a idempotência da facturação e dos lançamentos assenta em UNIQUE (venda_id, documento de tesouraria,
 * chave do movimento). RLS por empresa (IsolamentoRls) e GRANT a erp_app. `down()` não remove nada.
 */
return new class extends Migration
{
    private const TABELAS = ['configuracao_imobiliario', 'contratos_arrendamento', 'contrato_fracoes', 'contrato_encargos', 'rendas_arrendamento',
        'historico_contrato_imo', 'movimentos_caucao_imo'];

    public function up(): void
    {
        $this->configuracao();
        $this->contratos();
        $this->contratoFracoes();
        $this->encargos();
        $this->rendas();
        $this->historico();
        $this->caucao();

        foreach (self::TABELAS as $tabela) {
            DB::statement("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'erp_app') THEN "
                ."GRANT SELECT, INSERT, UPDATE, DELETE ON {$tabela} TO erp_app; "
                ."GRANT USAGE, SELECT ON SEQUENCE {$tabela}_id_seq TO erp_app; END IF; END \$\$");
        }

        (new IsolamentoRls(DB::connection()))->aplicarATodas();
    }

    private function restricao(string $tabela, string $nome, string $condicao): void
    {
        DB::statement("DO \$\$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = '{$nome}') THEN "
            ."ALTER TABLE {$tabela} ADD CONSTRAINT {$nome} CHECK ({$condicao}); END IF; END \$\$");
    }

    private function configuracao(): void
    {
        if (! Schema::hasTable('configuracao_imobiliario')) {
            Schema::create('configuracao_imobiliario', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->unsignedBigInteger('produto_renda_id')->nullable();
                $t->unsignedBigInteger('produto_renda_alojamento_id')->nullable();
                $t->string('conta_caucao_passivo', 20)->nullable();
                $t->string('conta_caucao_activo', 20)->nullable();
                $t->string('conta_caucao_receita', 20)->nullable();
                $t->string('conta_ip_credito', 20)->nullable();
                $t->string('criado_por', 100)->nullable();
                $t->string('atualizado_por', 100)->nullable();
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->foreign('empresa_id', 'fk_cfgimo_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
                $t->foreign('produto_renda_id', 'fk_cfgimo_produto_renda')->references('id')->on('produtos')->restrictOnDelete();
                $t->foreign('produto_renda_alojamento_id', 'fk_cfgimo_produto_aloj')->references('id')->on('produtos')->restrictOnDelete();
            });
        }
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_cfgimo_empresa ON configuracao_imobiliario (empresa_id)');
    }

    private function contratos(): void
    {
        if (! Schema::hasTable('contratos_arrendamento')) {
            Schema::create('contratos_arrendamento', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->string('numero', 30);
                $t->string('tipo', 16)->default('ARRENDAMENTO');
                $t->string('posicao', 10)->default('SENHORIO');
                $t->string('estado', 10)->default('RASCUNHO');
                $t->string('ambito', 8)->default('FRACCAO');
                $t->unsignedBigInteger('imovel_id');
                $t->unsignedBigInteger('fracao_id');
                $t->unsignedBigInteger('terceiro_id');
                $t->unsignedBigInteger('contrato_pai_id')->nullable();
                $t->boolean('permite_sub')->default(false);
                $t->string('finalidade_fiscal', 10)->default('HABITACAO');
                $t->date('inicio');
                $t->date('fim')->nullable();
                $t->date('fim_real')->nullable();
                $t->string('motivo_saida', 300)->nullable();
                $t->string('periodicidade', 10)->default('MENSAL');
                $t->boolean('antecipada')->default(true);
                $t->smallInteger('dia_vencimento')->default(1);
                $t->decimal('renda', 18, 2);
                $t->boolean('pro_rata')->default(true);
                $t->string('actualizacao', 5)->default('NAO');
                $t->decimal('actualizacao_valor', 9, 4)->default(0);
                $t->smallInteger('actualizacao_meses')->default(12);
                $t->date('actualizacao_base')->nullable();
                $t->date('ultima_actualizacao')->nullable();
                $t->decimal('renda_anterior', 18, 2)->nullable();
                $t->decimal('caucao', 18, 2)->default(0);
                $t->decimal('caucao_recebida', 18, 2)->default(0);
                $t->decimal('caucao_devolvida', 18, 2)->default(0);
                $t->decimal('caucao_retida', 18, 2)->default(0);
                $t->boolean('retencao_ip')->default(true);
                $t->string('isencao_ip', 6)->default('NAO');
                $t->string('selo_encargo', 10)->default('SENHORIO');
                $t->string('doc_tipo', 2)->default('FT');
                $t->string('renovacao', 4)->default('NAO');
                $t->smallInteger('renovacao_meses')->default(12);
                $t->smallInteger('aviso_dias')->default(60);
                $t->string('conta_custo', 20)->nullable();
                $t->string('conta_retencao', 20)->nullable();
                $t->string('conta_financeira', 20)->nullable();
                $t->decimal('mora_mensal', 9, 4)->nullable();
                $t->date('registo_agt_data')->nullable();
                $t->string('outra_parte_morada', 300)->nullable();
                $t->string('outra_parte_doc', 100)->nullable();
                $t->string('outra_parte_contacto', 150)->nullable();
                $t->jsonb('documentos')->default('[]');
                $t->text('notas')->nullable();
                $t->string('criado_por', 100)->nullable();
                $t->string('atualizado_por', 100)->nullable();
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->foreign('empresa_id', 'fk_contratos_arr_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
                $t->foreign('imovel_id', 'fk_contratos_arr_imovel')->references('id')->on('imoveis')->restrictOnDelete();
                $t->foreign('fracao_id', 'fk_contratos_arr_fracao')->references('id')->on('fracoes')->restrictOnDelete();
                $t->foreign('terceiro_id', 'fk_contratos_arr_terceiro')->references('id')->on('terceiros')->restrictOnDelete();
                $t->foreign('contrato_pai_id', 'fk_contratos_arr_pai')->references('id')->on('contratos_arrendamento')->restrictOnDelete();
            });
        }
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_contratos_arr_empresa_numero ON contratos_arrendamento (empresa_id, lower(numero))');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_contratos_arr_estado ON contratos_arrendamento (empresa_id, estado)');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_contratos_arr_posicao ON contratos_arrendamento (empresa_id, posicao)');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_contratos_arr_fracao ON contratos_arrendamento (fracao_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_contratos_arr_terceiro ON contratos_arrendamento (terceiro_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_contratos_arr_pai ON contratos_arrendamento (contrato_pai_id) WHERE contrato_pai_id IS NOT NULL');

        $this->restricao('contratos_arrendamento', 'ck_contratos_arr_tipo', "tipo IN ('ARRENDAMENTO','SUBARRENDAMENTO','TOMADA')");
        $this->restricao('contratos_arrendamento', 'ck_contratos_arr_posicao', "(tipo = 'TOMADA' AND posicao = 'INQUILINO') OR (tipo <> 'TOMADA' AND posicao = 'SENHORIO')");
        $this->restricao('contratos_arrendamento', 'ck_contratos_arr_estado', "estado IN ('RASCUNHO','ACTIVO','SUSPENSO','TERMINADO','DENUNCIADO')");
        $this->restricao('contratos_arrendamento', 'ck_contratos_arr_ambito', "ambito IN ('FRACCAO','IMOVEL','PARCIAL')");
        $this->restricao('contratos_arrendamento', 'ck_contratos_arr_pai', "(tipo = 'SUBARRENDAMENTO') = (contrato_pai_id IS NOT NULL)");
        $this->restricao('contratos_arrendamento', 'ck_contratos_arr_finalidade', "finalidade_fiscal IN ('HABITACAO','COMERCIO','ESCRITORIO','ARMAZEM','ALOJAMENTO','OUTRO')");
        $this->restricao('contratos_arrendamento', 'ck_contratos_arr_periodicidade', "periodicidade IN ('MENSAL','BIMESTRAL','TRIMESTRAL','SEMESTRAL','ANUAL')");
        $this->restricao('contratos_arrendamento', 'ck_contratos_arr_dia', 'dia_vencimento BETWEEN 1 AND 28');
        $this->restricao('contratos_arrendamento', 'ck_contratos_arr_renda', 'renda > 0');
        $this->restricao('contratos_arrendamento', 'ck_contratos_arr_datas', 'fim IS NULL OR fim > inicio');
        $this->restricao('contratos_arrendamento', 'ck_contratos_arr_actualizacao', "actualizacao IN ('NAO','PERC','VALOR') AND actualizacao_valor >= 0 AND actualizacao_meses >= 1 AND (actualizacao <> 'PERC' OR actualizacao_valor <= 100)");
        $this->restricao('contratos_arrendamento', 'ck_contratos_arr_caucao', 'caucao >= 0 AND caucao_recebida >= 0 AND caucao_devolvida >= 0 AND caucao_retida >= 0 AND caucao_recebida <= caucao AND caucao_devolvida + caucao_retida <= caucao_recebida');
        $this->restricao('contratos_arrendamento', 'ck_contratos_arr_fiscal', "isencao_ip IN ('NAO','METADE','TOTAL') AND selo_encargo IN ('SENHORIO','INQUILINO','ISENTO') AND doc_tipo IN ('FT','FR')");
        $this->restricao('contratos_arrendamento', 'ck_contratos_arr_renovacao', "renovacao IN ('NAO','AUTO') AND renovacao_meses >= 1 AND aviso_dias >= 0");
        $this->restricao('contratos_arrendamento', 'ck_contratos_arr_mora', 'mora_mensal IS NULL OR (mora_mensal >= 0 AND mora_mensal <= 100)');
        $this->restricao('contratos_arrendamento', 'ck_contratos_arr_documentos', "jsonb_typeof(documentos) = 'array'");
    }

    private function contratoFracoes(): void
    {
        if (! Schema::hasTable('contrato_fracoes')) {
            Schema::create('contrato_fracoes', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->unsignedBigInteger('contrato_id');
                $t->unsignedBigInteger('fracao_id');
                $t->string('posicao', 10);
                $t->date('inicio');
                $t->date('fim')->nullable();
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->foreign('empresa_id', 'fk_contrato_fracoes_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
                $t->foreign('contrato_id', 'fk_contrato_fracoes_contrato')->references('id')->on('contratos_arrendamento')->cascadeOnDelete();
                $t->foreign('fracao_id', 'fk_contrato_fracoes_fracao')->references('id')->on('fracoes')->restrictOnDelete();
            });
        }
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_contrato_fracoes ON contrato_fracoes (contrato_id, fracao_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_contrato_fracoes_fracao ON contrato_fracoes (fracao_id, posicao)');
        $this->restricao('contrato_fracoes', 'ck_contrato_fracoes_posicao', "posicao IN ('SENHORIO','INQUILINO')");
    }

    private function encargos(): void
    {
        if (! Schema::hasTable('contrato_encargos')) {
            Schema::create('contrato_encargos', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->unsignedBigInteger('contrato_id');
                $t->string('designacao', 150);
                $t->decimal('valor', 18, 2)->default(0);
                $t->boolean('incluido_na_renda')->default(false);
                $t->string('origem', 8)->default('MANUAL');
                $t->unsignedBigInteger('fracao_id')->nullable();
                $t->smallInteger('ordem')->default(0);
                $t->foreign('empresa_id', 'fk_contrato_encargos_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
                $t->foreign('contrato_id', 'fk_contrato_encargos_contrato')->references('id')->on('contratos_arrendamento')->cascadeOnDelete();
                $t->foreign('fracao_id', 'fk_contrato_encargos_fracao')->references('id')->on('fracoes')->nullOnDelete();
            });
        }
        DB::statement('CREATE INDEX IF NOT EXISTS ix_contrato_encargos_contrato ON contrato_encargos (contrato_id)');
        $this->restricao('contrato_encargos', 'ck_contrato_encargos_valor', "valor >= 0 AND origem IN ('MANUAL','PARQUE')");
    }

    private function rendas(): void
    {
        if (! Schema::hasTable('rendas_arrendamento')) {
            Schema::create('rendas_arrendamento', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->unsignedBigInteger('contrato_id');
                $t->string('periodo', 7);
                $t->string('posicao', 10);
                $t->date('de');
                $t->date('ate');
                $t->date('vencimento');
                $t->string('estado', 10)->default('PREVISTA');
                $t->decimal('renda', 18, 2);
                $t->decimal('factor', 9, 6)->default(1);
                $t->decimal('encargos', 18, 2)->default(0);
                $t->decimal('desconto', 18, 2)->default(0);
                $t->jsonb('extras')->default('[]');
                $t->decimal('extras_total', 18, 2)->default(0);
                $t->decimal('base', 18, 2)->default(0);
                $t->decimal('ip_base', 18, 2)->default(0);
                $t->decimal('ip_valor', 18, 2)->default(0);
                $t->boolean('ip_retido')->default(false);
                $t->decimal('selo_pct', 9, 4)->default(0);
                $t->decimal('selo_valor', 18, 2)->default(0);
                $t->string('selo_encargo', 10)->default('SENHORIO');
                $t->decimal('iva_taxa', 9, 4)->default(0);
                $t->decimal('iva_valor', 18, 2)->default(0);
                $t->decimal('total_facturar', 18, 2)->default(0);
                $t->decimal('total_receber', 18, 2)->default(0);
                $t->decimal('custo_senhorio', 18, 2)->default(0);
                $t->unsignedBigInteger('venda_id')->nullable();
                $t->unsignedBigInteger('documento_tesouraria_id')->nullable();
                $t->string('numero_documento', 60)->nullable();
                $t->date('data_facturada')->nullable();
                $t->date('data_pagamento')->nullable();
                $t->string('aprovada_por', 100)->nullable();
                $t->timestampTz('aprovada_em')->nullable();
                $t->string('retencao_lancamento', 60)->nullable();
                $t->text('notas')->nullable();
                $t->string('criado_por', 100)->nullable();
                $t->string('atualizado_por', 100)->nullable();
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->foreign('empresa_id', 'fk_rendas_arr_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
                $t->foreign('contrato_id', 'fk_rendas_arr_contrato')->references('id')->on('contratos_arrendamento')->restrictOnDelete();
                $t->foreign('venda_id', 'fk_rendas_arr_venda')->references('id')->on('vendas')->restrictOnDelete();
                $t->foreign('documento_tesouraria_id', 'fk_rendas_arr_doc_tesouraria')->references('id')->on('documentos_tesouraria')->restrictOnDelete();
            });
        }
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_rendas_arr_contrato_periodo ON rendas_arrendamento (contrato_id, periodo)');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_rendas_arr_venda ON rendas_arrendamento (venda_id) WHERE venda_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_rendas_arr_doc_tesouraria ON rendas_arrendamento (documento_tesouraria_id) WHERE documento_tesouraria_id IS NOT NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_rendas_arr_periodo ON rendas_arrendamento (empresa_id, periodo)');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_rendas_arr_estado ON rendas_arrendamento (empresa_id, estado)');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_rendas_arr_vencimento ON rendas_arrendamento (empresa_id, vencimento)');

        $this->restricao('rendas_arrendamento', 'ck_rendas_arr_estado', "estado IN ('PREVISTA','APROVADA','FACTURADA','PAGA','ANULADA')");
        $this->restricao('rendas_arrendamento', 'ck_rendas_arr_posicao', "posicao IN ('SENHORIO','INQUILINO')");
        $this->restricao('rendas_arrendamento', 'ck_rendas_arr_periodo', "periodo ~ '^[0-9]{4}-(0[1-9]|1[0-2])$' AND ate >= de");
        $this->restricao('rendas_arrendamento', 'ck_rendas_arr_valores', 'renda >= 0 AND encargos >= 0 AND desconto >= 0 AND extras_total >= 0 AND factor > 0 AND factor <= 1 AND base >= 0 AND total_facturar >= 0 AND total_receber >= 0');
        $this->restricao('rendas_arrendamento', 'ck_rendas_arr_total', 'total_facturar = base + iva_valor + extras_total');
        $this->restricao('rendas_arrendamento', 'ck_rendas_arr_extras', "jsonb_typeof(extras) = 'array'");
        $this->restricao('rendas_arrendamento', 'ck_rendas_arr_documento', "estado <> 'FACTURADA' OR venda_id IS NOT NULL");
    }

    private function historico(): void
    {
        if (! Schema::hasTable('historico_contrato_imo')) {
            Schema::create('historico_contrato_imo', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->unsignedBigInteger('contrato_id');
                $t->string('evento', 20);
                $t->string('estado_de', 10)->nullable();
                $t->string('estado_para', 10)->nullable();
                $t->string('motivo', 300)->nullable();
                $t->jsonb('dados')->nullable();
                $t->unsignedBigInteger('utilizador_id')->nullable();
                $t->timestampTz('em')->useCurrent();
                $t->foreign('empresa_id', 'fk_hist_contrato_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
                $t->foreign('contrato_id', 'fk_hist_contrato_contrato')->references('id')->on('contratos_arrendamento')->cascadeOnDelete();
            });
        }
        DB::statement('CREATE INDEX IF NOT EXISTS ix_hist_contrato_em ON historico_contrato_imo (contrato_id, em)');
        $this->restricao('historico_contrato_imo', 'ck_hist_contrato_evento', "evento IN ('CRIADO','EDITADO','ACTIVADO','SUSPENSO','REACTIVADO','TERMINADO','DENUNCIADO','RENOVADO','RENDA_ACTUALIZADA')");
    }

    private function caucao(): void
    {
        if (! Schema::hasTable('movimentos_caucao_imo')) {
            Schema::create('movimentos_caucao_imo', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->unsignedBigInteger('contrato_id');
                $t->string('tipo', 10);
                $t->decimal('valor', 18, 2);
                $t->date('data');
                $t->string('conta_financeira', 20)->nullable();
                $t->string('motivo', 300)->nullable();
                $t->string('chave', 80);
                $t->string('estado', 8)->default('VALIDO');
                $t->string('lancamento_numero', 40)->nullable();
                $t->string('estorno_numero', 40)->nullable();
                $t->string('anulado_por', 100)->nullable();
                $t->timestampTz('anulado_em')->nullable();
                $t->string('anulado_motivo', 300)->nullable();
                $t->string('criado_por', 100)->nullable();
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->foreign('empresa_id', 'fk_mov_caucao_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
                $t->foreign('contrato_id', 'fk_mov_caucao_contrato')->references('id')->on('contratos_arrendamento')->restrictOnDelete();
            });
        }
        DB::statement('ALTER TABLE movimentos_caucao_imo ADD COLUMN IF NOT EXISTS atualizado_em timestamptz NOT NULL DEFAULT now()');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_mov_caucao_chave ON movimentos_caucao_imo (empresa_id, chave)');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_mov_caucao_contrato ON movimentos_caucao_imo (contrato_id, data)');
        $this->restricao('movimentos_caucao_imo', 'ck_mov_caucao_tipo', "tipo IN ('RECEBIDA','DEVOLVIDA','RETIDA') AND estado IN ('VALIDO','ANULADO') AND valor > 0");
    }

    public function down(): void
    {
        // Aditiva: não remove tabelas com dados (política do projecto).
    }
};
