<?php

use App\Support\Tenancy\IsolamentoRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Planeamento Estratégico, Onda 1 (F0 + F1 + F2a): plano, perspectivas, objectivos, indicadores, metas e medições.
 * Só tabelas novas, aditivas e idempotentes (produção tem dados reais: nenhuma tabela existente é tocada). Todas com
 * `empresa_id`, RLS e GRANT a `erp_app`. Valores de indicador em numeric(20,4): o semáforo calcula-se em decimal.
 * Eliminação física (com FK RESTRICT e regras no serviço), não lógica: um plano em rascunho sem dados apaga-se de vez.
 * Nas ondas seguintes acrescentam-se as restantes tabelas do plano (contributos, iniciativas, revisões, diagnóstico, documento).
 */
return new class extends Migration
{
    private const PERIODO = '^[0-9]{4}(-(0[1-9]|1[0-2]|T[1-4]|S[12]))?$';

    public function up(): void
    {
        $this->planos();
        $this->perspectivas();
        $this->objectivos();
        $this->indicadores();
        $this->metas();
        $this->medicoes();

        foreach (['planos_estrategicos', 'perspectivas_estrategicas', 'objectivos_estrategicos', 'indicadores_estrategicos', 'metas_indicadores', 'medicoes_indicadores'] as $tabela) {
            DB::statement("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'erp_app') THEN "
                ."GRANT SELECT, INSERT, UPDATE, DELETE ON {$tabela} TO erp_app; "
                ."GRANT USAGE, SELECT ON SEQUENCE {$tabela}_id_seq TO erp_app; END IF; END \$\$");
        }

        (new IsolamentoRls(DB::connection()))->aplicarATodas();
    }

    public function down(): void
    {
        // Aditiva: não se removem tabelas nem dados.
    }

    private function planos(): void
    {
        if (! Schema::hasTable('planos_estrategicos')) {
            Schema::create('planos_estrategicos', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->string('nome', 200);
                $t->unsignedSmallInteger('ano_inicio');
                $t->unsignedSmallInteger('ano_fim');
                $t->text('missao')->nullable();
                $t->text('visao')->nullable();
                $t->text('valores')->nullable();
                $t->string('estado', 12)->default('RASCUNHO');
                $t->unsignedSmallInteger('versao')->default(1);
                $t->unsignedBigInteger('autor_id')->nullable();
                $t->unsignedBigInteger('editado_por_id')->nullable();
                $t->timestampTz('aprovado_em')->nullable();
                $t->string('aprovado_por', 150)->nullable();
                $t->unsignedBigInteger('aprovado_por_id')->nullable();
                $t->string('aprovacao_excepcao', 300)->nullable();
                $t->timestampTz('execucao_iniciada_em')->nullable();
                $t->string('execucao_iniciada_por', 150)->nullable();
                $t->timestampTz('encerrado_em')->nullable();
                $t->string('encerrado_por', 150)->nullable();
                $t->string('motivo_reabertura', 300)->nullable();
                $t->string('criado_por', 150)->nullable();
                $t->string('atualizado_por', 150)->nullable();
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->foreign('empresa_id', 'fk_planos_estrategicos_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
            });
            DB::statement("ALTER TABLE planos_estrategicos ADD CONSTRAINT ck_planos_estrategicos_estado CHECK (estado IN ('RASCUNHO','APROVADO','EM_EXECUCAO','ENCERRADO'))");
            DB::statement('ALTER TABLE planos_estrategicos ADD CONSTRAINT ck_planos_estrategicos_anos CHECK (ano_inicio > 1900 AND ano_fim >= ano_inicio AND ano_fim - ano_inicio <= 14)');
            DB::statement('ALTER TABLE planos_estrategicos ADD CONSTRAINT ck_planos_estrategicos_versao CHECK (versao >= 1)');
        }
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS ux_planos_estrategicos_nome ON planos_estrategicos (empresa_id, nome, versao)');
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS ux_planos_estrategicos_vigente ON planos_estrategicos (empresa_id) WHERE estado IN ('APROVADO','EM_EXECUCAO')");
        DB::statement('CREATE INDEX IF NOT EXISTS ix_planos_estrategicos_estado ON planos_estrategicos (empresa_id, estado)');
    }

    private function perspectivas(): void
    {
        if (! Schema::hasTable('perspectivas_estrategicas')) {
            Schema::create('perspectivas_estrategicas', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->unsignedBigInteger('plano_estrategico_id');
                $t->smallInteger('ordem')->default(0);
                $t->string('nome', 120);
                $t->string('descricao', 300)->nullable();
                $t->char('cor', 7)->default('#475569');
                $t->string('icone', 40)->default('fa-bullseye');
                $t->string('criado_por', 150)->nullable();
                $t->string('atualizado_por', 150)->nullable();
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->foreign('empresa_id', 'fk_perspectivas_estrategicas_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
                $t->foreign('plano_estrategico_id', 'fk_perspectivas_estrategicas_plano')->references('id')->on('planos_estrategicos')->restrictOnDelete();
            });
            DB::statement("ALTER TABLE perspectivas_estrategicas ADD CONSTRAINT ck_perspectivas_estrategicas_cor CHECK (cor ~ '^#[0-9A-Fa-f]{6}\$')");
            DB::statement("ALTER TABLE perspectivas_estrategicas ADD CONSTRAINT ck_perspectivas_estrategicas_icone CHECK (icone ~ '^fa-[a-z0-9-]+\$')");
        }
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS ux_perspectivas_estrategicas_nome ON perspectivas_estrategicas (plano_estrategico_id, nome)');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_perspectivas_estrategicas_ordem ON perspectivas_estrategicas (plano_estrategico_id, ordem)');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_perspectivas_estrategicas_empresa ON perspectivas_estrategicas (empresa_id)');
    }

    private function objectivos(): void
    {
        if (! Schema::hasTable('objectivos_estrategicos')) {
            Schema::create('objectivos_estrategicos', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->unsignedBigInteger('plano_estrategico_id');
                $t->unsignedBigInteger('perspectiva_id');
                $t->unsignedBigInteger('objectivo_pai_id')->nullable();
                $t->unsignedBigInteger('unidade_organica_id')->nullable();
                $t->unsignedBigInteger('responsavel_colaborador_id')->nullable();
                $t->string('responsavel_externo', 150)->nullable();
                $t->string('nome', 300);
                $t->text('descricao')->nullable();
                $t->string('horizonte', 10)->default('PLANO');
                $t->string('periodo', 8)->nullable();
                $t->string('estado', 12)->default('ACTIVO');
                $t->integer('ordem')->default(0);
                $t->decimal('posicao_mapa', 5, 2)->nullable();
                $t->string('criado_por', 150)->nullable();
                $t->string('atualizado_por', 150)->nullable();
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->foreign('empresa_id', 'fk_objectivos_estrategicos_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
                $t->foreign('plano_estrategico_id', 'fk_objectivos_estrategicos_plano')->references('id')->on('planos_estrategicos')->restrictOnDelete();
                $t->foreign('perspectiva_id', 'fk_objectivos_estrategicos_perspectiva')->references('id')->on('perspectivas_estrategicas')->restrictOnDelete();
                $t->foreign('objectivo_pai_id', 'fk_objectivos_estrategicos_pai')->references('id')->on('objectivos_estrategicos')->restrictOnDelete();
                $t->foreign('unidade_organica_id', 'fk_objectivos_estrategicos_unidade')->references('id')->on('unidades_organicas')->restrictOnDelete();
                $t->foreign('responsavel_colaborador_id', 'fk_objectivos_estrategicos_responsavel')->references('id')->on('colaboradores')->restrictOnDelete();
            });
            DB::statement("ALTER TABLE objectivos_estrategicos ADD CONSTRAINT ck_objectivos_estrategicos_horizonte CHECK (horizonte IN ('PLANO','TRIMESTRE'))");
            DB::statement("ALTER TABLE objectivos_estrategicos ADD CONSTRAINT ck_objectivos_estrategicos_periodo CHECK ((horizonte = 'TRIMESTRE') = (periodo IS NOT NULL) AND (periodo IS NULL OR periodo ~ '^[0-9]{4}-T[1-4]\$'))");
            DB::statement("ALTER TABLE objectivos_estrategicos ADD CONSTRAINT ck_objectivos_estrategicos_estado CHECK (estado IN ('ACTIVO','SUSPENSO','CONCLUIDO','ABANDONADO'))");
            DB::statement('ALTER TABLE objectivos_estrategicos ADD CONSTRAINT ck_objectivos_estrategicos_pai CHECK (objectivo_pai_id IS NULL OR objectivo_pai_id <> id)');
            DB::statement('ALTER TABLE objectivos_estrategicos ADD CONSTRAINT ck_objectivos_estrategicos_posicao CHECK (posicao_mapa IS NULL OR posicao_mapa BETWEEN 0 AND 100)');
        }
        DB::statement('CREATE INDEX IF NOT EXISTS ix_objectivos_estrategicos_plano ON objectivos_estrategicos (plano_estrategico_id, perspectiva_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_objectivos_estrategicos_pai ON objectivos_estrategicos (objectivo_pai_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_objectivos_estrategicos_unidade ON objectivos_estrategicos (unidade_organica_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_objectivos_estrategicos_empresa ON objectivos_estrategicos (empresa_id)');
    }

    private function indicadores(): void
    {
        if (! Schema::hasTable('indicadores_estrategicos')) {
            Schema::create('indicadores_estrategicos', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->unsignedBigInteger('plano_estrategico_id');
                $t->unsignedBigInteger('objectivo_id');
                $t->string('nome', 200);
                $t->text('descricao')->nullable();
                $t->string('origem', 12);
                $t->unsignedBigInteger('rubrica_orcamental_id')->nullable();
                $t->jsonb('fonte')->default('{}');
                $t->string('polaridade', 5)->default('SOBE');
                $t->string('tipo', 9)->default('RESULTADO');
                $t->string('formato', 6)->default('num');
                $t->string('frequencia', 10)->default('MENSAL');
                $t->string('agregacao', 7)->nullable();
                $t->string('unidade_medida', 30)->nullable();
                $t->decimal('linha_base', 20, 4)->nullable();
                $t->string('linha_base_periodo', 8)->nullable();
                $t->unsignedBigInteger('dono_colaborador_id')->nullable();
                $t->string('dono_externo', 150)->nullable();
                $t->smallInteger('prazo_dias')->nullable();
                $t->decimal('tolerancia_verde', 6, 2)->default(100);
                $t->decimal('tolerancia_amarela', 6, 2)->default(90);
                $t->boolean('ativo')->default(true);
                $t->integer('ordem')->default(0);
                $t->string('criado_por', 150)->nullable();
                $t->string('atualizado_por', 150)->nullable();
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->foreign('empresa_id', 'fk_indicadores_estrategicos_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
                $t->foreign('plano_estrategico_id', 'fk_indicadores_estrategicos_plano')->references('id')->on('planos_estrategicos')->restrictOnDelete();
                $t->foreign('objectivo_id', 'fk_indicadores_estrategicos_objectivo')->references('id')->on('objectivos_estrategicos')->restrictOnDelete();
                $t->foreign('rubrica_orcamental_id', 'fk_indicadores_estrategicos_rubrica')->references('id')->on('rubricas_orcamentais')->restrictOnDelete();
                $t->foreign('dono_colaborador_id', 'fk_indicadores_estrategicos_dono')->references('id')->on('colaboradores')->restrictOnDelete();
            });
            DB::statement("ALTER TABLE indicadores_estrategicos ADD CONSTRAINT ck_indicadores_estrategicos_origem CHECK (origem IN ('KPI_GESTAO','CONTA','ORCAMENTO','MODULO','MANUAL'))");
            DB::statement("ALTER TABLE indicadores_estrategicos ADD CONSTRAINT ck_indicadores_estrategicos_polaridade CHECK (polaridade IN ('SOBE','DESCE','ALVO'))");
            DB::statement("ALTER TABLE indicadores_estrategicos ADD CONSTRAINT ck_indicadores_estrategicos_tipo CHECK (tipo IN ('RESULTADO','INDUTOR'))");
            DB::statement("ALTER TABLE indicadores_estrategicos ADD CONSTRAINT ck_indicadores_estrategicos_formato CHECK (formato IN ('kz','num','pct','dias','horas','meses','nota'))");
            DB::statement("ALTER TABLE indicadores_estrategicos ADD CONSTRAINT ck_indicadores_estrategicos_frequencia CHECK (frequencia IN ('MENSAL','TRIMESTRAL','SEMESTRAL','ANUAL'))");
            DB::statement("ALTER TABLE indicadores_estrategicos ADD CONSTRAINT ck_indicadores_estrategicos_agregacao CHECK (agregacao IS NULL OR agregacao IN ('SOMA','MEDIA','NENHUMA'))");
            DB::statement("ALTER TABLE indicadores_estrategicos ADD CONSTRAINT ck_indicadores_estrategicos_fonte CHECK (jsonb_typeof(fonte) = 'object')");
            DB::statement("ALTER TABLE indicadores_estrategicos ADD CONSTRAINT ck_indicadores_estrategicos_dono CHECK (origem <> 'MANUAL' OR dono_colaborador_id IS NOT NULL OR dono_externo IS NOT NULL)");
            DB::statement("ALTER TABLE indicadores_estrategicos ADD CONSTRAINT ck_indicadores_estrategicos_rubrica CHECK ((origem = 'ORCAMENTO') = (rubrica_orcamental_id IS NOT NULL))");
            DB::statement('ALTER TABLE indicadores_estrategicos ADD CONSTRAINT ck_indicadores_estrategicos_tolerancias CHECK (tolerancia_amarela > 0 AND tolerancia_verde >= tolerancia_amarela)');
            DB::statement('ALTER TABLE indicadores_estrategicos ADD CONSTRAINT ck_indicadores_estrategicos_prazo CHECK (prazo_dias IS NULL OR prazo_dias BETWEEN 0 AND 120)');
        }
        DB::statement('CREATE INDEX IF NOT EXISTS ix_indicadores_estrategicos_plano ON indicadores_estrategicos (plano_estrategico_id, objectivo_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_indicadores_estrategicos_origem ON indicadores_estrategicos (origem)');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_indicadores_estrategicos_dono ON indicadores_estrategicos (dono_colaborador_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_indicadores_estrategicos_empresa ON indicadores_estrategicos (empresa_id)');
    }

    private function metas(): void
    {
        if (! Schema::hasTable('metas_indicadores')) {
            Schema::create('metas_indicadores', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->unsignedBigInteger('indicador_id');
                $t->string('periodo', 8);
                $t->decimal('valor', 20, 4);
                $t->decimal('valor_minimo', 20, 4)->nullable();
                $t->string('nota', 300)->nullable();
                $t->string('criado_por', 150)->nullable();
                $t->string('atualizado_por', 150)->nullable();
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->foreign('empresa_id', 'fk_metas_indicadores_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
                $t->foreign('indicador_id', 'fk_metas_indicadores_indicador')->references('id')->on('indicadores_estrategicos')->restrictOnDelete();
            });
            DB::statement('ALTER TABLE metas_indicadores ADD CONSTRAINT ck_metas_indicadores_periodo CHECK (periodo ~ \''.self::PERIODO.'\')');
        }
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS ux_metas_indicadores_periodo ON metas_indicadores (indicador_id, periodo)');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_metas_indicadores_empresa ON metas_indicadores (empresa_id)');
    }

    private function medicoes(): void
    {
        if (! Schema::hasTable('medicoes_indicadores')) {
            Schema::create('medicoes_indicadores', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->unsignedBigInteger('indicador_id');
                $t->string('periodo', 8);
                $t->unsignedBigInteger('unidade_organica_id')->nullable();
                $t->decimal('valor', 20, 4);
                $t->string('origem', 12);
                $t->string('nota', 300)->nullable();
                $t->string('detalhe', 300)->nullable();
                $t->timestampTz('medido_em')->useCurrent();
                $t->string('medido_por', 150)->nullable();
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->foreign('empresa_id', 'fk_medicoes_indicadores_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
                $t->foreign('indicador_id', 'fk_medicoes_indicadores_indicador')->references('id')->on('indicadores_estrategicos')->restrictOnDelete();
                $t->foreign('unidade_organica_id', 'fk_medicoes_indicadores_unidade')->references('id')->on('unidades_organicas')->restrictOnDelete();
            });
            DB::statement('ALTER TABLE medicoes_indicadores ADD CONSTRAINT ck_medicoes_indicadores_periodo CHECK (periodo ~ \''.self::PERIODO.'\')');
            DB::statement("ALTER TABLE medicoes_indicadores ADD CONSTRAINT ck_medicoes_indicadores_origem CHECK (origem IN ('KPI_GESTAO','CONTA','ORCAMENTO','MODULO','MANUAL'))");
        }
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS ux_medicoes_indicadores_empresa ON medicoes_indicadores (indicador_id, periodo) WHERE unidade_organica_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS ux_medicoes_indicadores_unidade ON medicoes_indicadores (indicador_id, periodo, unidade_organica_id) WHERE unidade_organica_id IS NOT NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_medicoes_indicadores_periodo ON medicoes_indicadores (indicador_id, periodo)');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_medicoes_indicadores_empresa ON medicoes_indicadores (empresa_id)');
    }
};
