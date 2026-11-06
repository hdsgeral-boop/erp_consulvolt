<?php

use App\Support\Tenancy\IsolamentoRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gestão Imobiliária, Onda 1 (F0 + F1 + F2): cadastro (imóveis, fracções e o histórico de estados), modelos de documentos e
 * taxas configuráveis por ano. Só tabelas NOVAS, aditivas e idempotentes; nenhuma tabela existente é alterada. As ligações
 * ao módulo Activos são chaves estrangeiras opcionais: `imoveis.ativo_edificio_id` (bem EDIFICIO) e `fracoes.porta_id`
 * (porta do edifício, ON DELETE SET NULL para nunca bloquear a manutenção da planta). A numeração dos códigos usa a tabela
 * `sequencias_documentos` (ServicoNumeracao: bloqueio + FOR UPDATE). As colunas `park_*` da fracção ficam criadas para a
 * Onda do Parqueamento (F4), que não exige nova alteração de tabela. Contratos, rendas, caução, reservas, obras e negócios
 * ficam para as ondas seguintes (docs/paridade/IMOBILIARIO_PLANO.md).
 */
return new class extends Migration
{
    private const TAXAS_PCT = ['ip_taxa', 'ip_base', 'selo_habitacao', 'selo_outro', 'iva_arrendamento', 'iva_alojamento', 'mora_mensal', 'ip_transmissao',
        'selo_transmissao', 'det_escalao1_pct', 'det_escalao3_pct', 'det_terreno_pct', 'det_agravamento_pct'];

    private const TAXAS_VALOR = ['prazo_registo', 'escritura_fixo', 'isencao_ate', 'isencao_metade_ate', 'det_escalao1_ate', 'det_escalao2_ate',
        'det_escalao2_fixo', 'det_agravamento_meses'];

    public function up(): void
    {
        $this->imoveis();
        $this->fracoes();
        $this->historico();
        $this->modelos();
        $this->taxas();

        foreach (['imoveis', 'fracoes', 'historico_estado_fracao', 'modelos_documento_imo', 'taxas_imobiliario'] as $tabela) {
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

    private function imoveis(): void
    {
        if (! Schema::hasTable('imoveis')) {
            Schema::create('imoveis', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->string('codigo', 20);
                $t->string('nome', 200);
                $t->string('tipo', 12)->default('PREDIO');
                $t->string('papel', 10)->default('PROPRIO');
                $t->smallInteger('n_pisos')->default(0);
                $t->smallInteger('piso_inicial')->default(0);
                $t->integer('n_habitacoes')->default(0);
                $t->unsignedBigInteger('proprietario_terceiro_id')->nullable();
                $t->unsignedBigInteger('ativo_edificio_id')->nullable();
                $t->string('morada', 300)->nullable();
                $t->string('municipio', 100)->nullable();
                $t->string('provincia', 100)->nullable();
                $t->string('coordenadas', 60)->nullable();
                $t->string('matriz_predial', 60)->nullable();
                $t->string('registo_predial', 120)->nullable();
                $t->string('licenca_utilizacao', 120)->nullable();
                $t->decimal('area_terreno', 14, 2)->default(0);
                $t->decimal('area_bruta', 14, 2)->default(0);
                $t->decimal('area_util', 14, 2)->default(0);
                $t->decimal('valor_patrimonial', 18, 2)->nullable();
                $t->date('valor_patrimonial_data')->nullable();
                $t->string('isencao_detencao', 10)->default('NAO');
                $t->decimal('valor_aquisicao', 18, 2)->nullable();
                $t->date('data_aquisicao')->nullable();
                $t->string('estado', 10)->default('ACTIVO');
                $t->jsonb('documentos')->default('[]');
                $t->text('notas')->nullable();
                $t->string('criado_por', 100)->nullable();
                $t->string('atualizado_por', 100)->nullable();
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->foreign('empresa_id', 'fk_imoveis_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
                $t->foreign('proprietario_terceiro_id', 'fk_imoveis_proprietario')->references('id')->on('terceiros')->restrictOnDelete();
                $t->foreign('ativo_edificio_id', 'fk_imoveis_ativo_edificio')->references('id')->on('ativos_imobilizados')->restrictOnDelete();
            });
        }
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_imoveis_empresa_codigo ON imoveis (empresa_id, lower(codigo))');
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS uq_imoveis_empresa_matriz ON imoveis (empresa_id, matriz_predial) WHERE matriz_predial IS NOT NULL AND matriz_predial <> ''");
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_imoveis_empresa_edificio ON imoveis (empresa_id, ativo_edificio_id) WHERE ativo_edificio_id IS NOT NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_imoveis_empresa_papel ON imoveis (empresa_id, papel)');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_imoveis_empresa_estado ON imoveis (empresa_id, estado)');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_imoveis_proprietario ON imoveis (proprietario_terceiro_id) WHERE proprietario_terceiro_id IS NOT NULL');

        $this->restricao('imoveis', 'ck_imoveis_tipo', "tipo IN ('PREDIO','CONDOMINIO','MORADIA','APARTAMENTO','LOJA','ESCRITORIO','ARMAZEM','TERRENO','OUTRO')");
        $this->restricao('imoveis', 'ck_imoveis_papel', "papel IN ('PROPRIO','ARRENDADO','MEDIACAO')");
        $this->restricao('imoveis', 'ck_imoveis_estado', "estado IN ('ACTIVO','INACTIVO','ALIENADO')");
        $this->restricao('imoveis', 'ck_imoveis_isencao', "isencao_detencao IN ('NAO','METADE','TOTAL')");
        $this->restricao('imoveis', 'ck_imoveis_areas', 'area_terreno >= 0 AND area_bruta >= 0 AND area_util >= 0 AND (area_bruta = 0 OR area_util = 0 OR area_util <= area_bruta)');
        $this->restricao('imoveis', 'ck_imoveis_pisos', 'n_pisos >= 0 AND n_habitacoes >= 0');
        $this->restricao('imoveis', 'ck_imoveis_valores', '(valor_patrimonial IS NULL OR valor_patrimonial >= 0) AND (valor_aquisicao IS NULL OR valor_aquisicao >= 0)');
        $this->restricao('imoveis', 'ck_imoveis_proprietario', "(papel = 'PROPRIO' AND proprietario_terceiro_id IS NULL) OR (papel <> 'PROPRIO' AND proprietario_terceiro_id IS NOT NULL)");
        $this->restricao('imoveis', 'ck_imoveis_edificio', "ativo_edificio_id IS NULL OR papel IN ('PROPRIO','ARRENDADO')");
        $this->restricao('imoveis', 'ck_imoveis_documentos', "jsonb_typeof(documentos) = 'array'");
    }

    private function fracoes(): void
    {
        if (! Schema::hasTable('fracoes')) {
            Schema::create('fracoes', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->unsignedBigInteger('imovel_id');
                $t->string('codigo', 40);
                $t->string('designacao', 120);
                $t->string('natureza', 14)->default('HABITACIONAL');
                $t->string('park_afectacao', 14)->nullable();
                $t->unsignedBigInteger('park_fracao_id')->nullable();
                $t->string('park_tipo', 12)->nullable();
                $t->string('park_porte', 8)->nullable();
                $t->decimal('park_altura_max', 8, 2)->nullable();
                $t->decimal('park_comprimento', 8, 2)->nullable();
                $t->decimal('park_largura', 8, 2)->nullable();
                $t->boolean('park_tomada')->default(false);
                $t->string('park_distico', 60)->nullable();
                $t->boolean('park_incluido_na_renda')->nullable();
                $t->unsignedBigInteger('porta_id')->nullable();
                $t->string('bloco', 40)->nullable();
                $t->string('andar', 60)->nullable();
                $t->string('numero', 30)->nullable();
                $t->smallInteger('piso')->nullable();
                $t->string('tipologia', 12)->default('OUTRA');
                $t->string('finalidade', 14)->default('HABITACAO');
                $t->decimal('area_bruta', 14, 2)->default(0);
                $t->decimal('area_util', 14, 2)->default(0);
                $t->smallInteger('quartos')->nullable();
                $t->smallInteger('casas_banho')->nullable();
                $t->string('mobilada', 8)->default('NAO');
                $t->string('estado', 12)->default('DISPONIVEL');
                $t->date('estado_desde')->nullable();
                $t->string('estado_motivo', 300)->nullable();
                $t->string('matriz_predial', 60)->nullable();
                $t->decimal('valor_patrimonial', 18, 2)->nullable();
                $t->decimal('renda_referencia', 18, 2)->nullable();
                $t->decimal('venda_referencia', 18, 2)->nullable();
                $t->jsonb('documentos')->default('[]');
                $t->integer('ordem')->default(0);
                $t->text('notas')->nullable();
                $t->string('criado_por', 100)->nullable();
                $t->string('atualizado_por', 100)->nullable();
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->foreign('empresa_id', 'fk_fracoes_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
                $t->foreign('imovel_id', 'fk_fracoes_imovel_id')->references('id')->on('imoveis')->restrictOnDelete();
                $t->foreign('park_fracao_id', 'fk_fracoes_park_fracao')->references('id')->on('fracoes')->restrictOnDelete();
                $t->foreign('porta_id', 'fk_fracoes_porta_id')->references('id')->on('portas_edificios')->nullOnDelete();
            });
        }
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_fracoes_imovel_codigo ON fracoes (imovel_id, lower(codigo))');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_fracoes_imovel_designacao ON fracoes (imovel_id, lower(designacao))');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_fracoes_imovel_porta ON fracoes (imovel_id, porta_id) WHERE porta_id IS NOT NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_fracoes_empresa_imovel ON fracoes (empresa_id, imovel_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_fracoes_empresa_estado ON fracoes (empresa_id, estado)');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_fracoes_empresa_natureza ON fracoes (empresa_id, natureza)');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_fracoes_park_fracao ON fracoes (park_fracao_id) WHERE park_fracao_id IS NOT NULL');

        $this->restricao('fracoes', 'ck_fracoes_natureza', "natureza IN ('HABITACIONAL','ESTACIONAMENTO','ARRECADACAO')");
        $this->restricao('fracoes', 'ck_fracoes_estado', "estado IN ('DISPONIVEL','RESERVADA','ARRENDADA','VENDIDA','EM_OBRA','INDISPONIVEL')");
        $this->restricao('fracoes', 'ck_fracoes_tipologia', "tipologia IN ('T0','T1','T2','T3','T4','T5','LOJA','ESCRITORIO','ARMAZEM','GARAGEM','ARRECADACAO','TERRENO','OUTRA')");
        $this->restricao('fracoes', 'ck_fracoes_finalidade', "finalidade IN ('HABITACAO','COMERCIO','SERVICOS','INDUSTRIA','ARMAZENAGEM','ESTACIONAMENTO','OUTRA')");
        $this->restricao('fracoes', 'ck_fracoes_mobilada', "mobilada IN ('NAO','PARCIAL','SIM')");
        $this->restricao('fracoes', 'ck_fracoes_park_afectacao', "park_afectacao IS NULL OR park_afectacao IN ('PERMANENTE','AUTONOMA','VISITA','CARGA_DESCARGA')");
        $this->restricao('fracoes', 'ck_fracoes_park_tipo', "park_tipo IS NULL OR park_tipo IN ('DESCOBERTO','COBERTO','SUBTERRANEO','BOX')");
        $this->restricao('fracoes', 'ck_fracoes_park_porte', "park_porte IS NULL OR park_porte IN ('MOTA','LIGEIRO','SUV','PESADO')");
        $this->restricao('fracoes', 'ck_fracoes_areas', 'area_bruta >= 0 AND area_util >= 0 AND (area_bruta = 0 OR area_util = 0 OR area_util <= area_bruta)');
        $this->restricao('fracoes', 'ck_fracoes_contagens', '(quartos IS NULL OR quartos >= 0) AND (casas_banho IS NULL OR casas_banho >= 0)');
        $this->restricao('fracoes', 'ck_fracoes_valores', '(valor_patrimonial IS NULL OR valor_patrimonial >= 0) AND (renda_referencia IS NULL OR renda_referencia >= 0) AND (venda_referencia IS NULL OR venda_referencia >= 0)');
        $this->restricao('fracoes', 'ck_fracoes_documentos', "jsonb_typeof(documentos) = 'array'");
    }

    private function historico(): void
    {
        if (! Schema::hasTable('historico_estado_fracao')) {
            Schema::create('historico_estado_fracao', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->unsignedBigInteger('fracao_id');
                $t->string('estado_de', 12)->nullable();
                $t->string('estado_para', 12);
                $t->string('motivo', 300)->nullable();
                $t->unsignedBigInteger('utilizador_id')->nullable();
                $t->timestampTz('em')->useCurrent();
                $t->foreign('empresa_id', 'fk_historico_estado_fracao_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
                $t->foreign('fracao_id', 'fk_historico_estado_fracao_fracao_id')->references('id')->on('fracoes')->cascadeOnDelete();
            });
        }
        DB::statement('CREATE INDEX IF NOT EXISTS ix_historico_estado_fracao_fracao ON historico_estado_fracao (fracao_id, em)');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_historico_estado_fracao_empresa ON historico_estado_fracao (empresa_id, fracao_id)');
        $estados = "'DISPONIVEL','RESERVADA','ARRENDADA','VENDIDA','EM_OBRA','INDISPONIVEL'";
        $this->restricao('historico_estado_fracao', 'ck_historico_estado_fracao_estados', "estado_para IN ({$estados}) AND (estado_de IS NULL OR estado_de IN ({$estados}))");
    }

    private function modelos(): void
    {
        if (! Schema::hasTable('modelos_documento_imo')) {
            Schema::create('modelos_documento_imo', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->string('tipo', 14);
                $t->string('nome', 200);
                $t->string('assunto', 300)->nullable();
                $t->text('corpo');
                $t->boolean('activo')->default(true);
                $t->boolean('sistema')->default(false);
                $t->integer('versao')->default(1);
                $t->string('criado_por', 100)->nullable();
                $t->string('atualizado_por', 100)->nullable();
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->foreign('empresa_id', 'fk_modelos_documento_imo_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
            });
        }
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_modelos_documento_imo_nome ON modelos_documento_imo (empresa_id, tipo, lower(nome))');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_modelos_documento_imo_empresa_tipo ON modelos_documento_imo (empresa_id, tipo)');
        $this->restricao('modelos_documento_imo', 'ck_modelos_documento_imo_tipo', "tipo IN ('CONTRATO','CONTRATO_SUB','RECIBO','AVISO','INTERPELACAO','ACTUALIZACAO','DENUNCIA','VISTORIA')");
        $this->restricao('modelos_documento_imo', 'ck_modelos_documento_imo_corpo', "btrim(corpo) <> '' AND versao >= 1");
    }

    private function taxas(): void
    {
        if (! Schema::hasTable('taxas_imobiliario')) {
            Schema::create('taxas_imobiliario', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->smallInteger('ano');
                foreach (self::TAXAS_PCT as $c) {
                    $t->decimal($c, 9, 4)->default(0);
                }
                foreach (self::TAXAS_VALOR as $c) {
                    $t->decimal($c, 18, 2)->default(0);
                }
                $t->string('estado_validacao', 12)->default('POR_VALIDAR');
                $t->string('validada_por', 100)->nullable();
                $t->timestampTz('validada_em')->nullable();
                $t->string('validacao_nota', 300)->nullable();
                $t->string('criado_por', 100)->nullable();
                $t->string('atualizado_por', 100)->nullable();
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->foreign('empresa_id', 'fk_taxas_imobiliario_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
            });
        }
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_taxas_imobiliario_empresa_ano ON taxas_imobiliario (empresa_id, ano)');
        $this->restricao('taxas_imobiliario', 'ck_taxas_imobiliario_ano', 'ano BETWEEN 2000 AND 2100');
        $this->restricao('taxas_imobiliario', 'ck_taxas_imobiliario_estado', "estado_validacao IN ('POR_VALIDAR','VALIDADA')");
        $pct = implode(' AND ', array_map(fn ($c) => "{$c} BETWEEN 0 AND 100", self::TAXAS_PCT));
        $val = implode(' AND ', array_map(fn ($c) => "{$c} >= 0", self::TAXAS_VALOR));
        $this->restricao('taxas_imobiliario', 'ck_taxas_imobiliario_percentagens', $pct);
        $this->restricao('taxas_imobiliario', 'ck_taxas_imobiliario_valores', $val);
    }

    public function down(): void
    {
        // Aditiva: não se removem tabelas nem dados.
    }
};
