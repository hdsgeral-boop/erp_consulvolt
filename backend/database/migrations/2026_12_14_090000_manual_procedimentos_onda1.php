<?php

use App\Support\Tenancy\IsolamentoRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Manual de Procedimentos, Onda 1 (paridade com o legado v_boss): definições do documento por empresa (`manuais`), edições
 * e processos próprios sobre as narrativas de fábrica (`procedimentos`) com as suas etapas e cruzamentos. Só tabelas novas,
 * aditivas e idempotentes; nenhuma tabela existente é tocada. As narrativas de fábrica vivem em
 * backend/resources/procedimentos/narrativas.json (não na BD). Versões, aprovação, leitura e anexos ficam para as Ondas 2 e 3.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('manuais')) {
            Schema::create('manuais', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->string('tipo', 20);
                $t->string('titulo', 200);
                $t->string('versao_rascunho', 20)->default('1.0');
                $t->date('data_documento')->nullable();
                $t->string('elaborado_por', 150)->nullable();
                $t->string('aprovado_por', 150)->nullable();
                $t->date('aprovado_em')->nullable();
                $t->jsonb('textos')->default('{}');
                $t->jsonb('opcoes')->default('{}');
                $t->string('criado_por', 100)->nullable();
                $t->string('atualizado_por', 100)->nullable();
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->foreign('empresa_id', 'fk_manuais_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
            });
            DB::statement("ALTER TABLE manuais ADD CONSTRAINT ck_manuais_tipo CHECK (tipo IN ('PROCEDIMENTOS','ORGANIZACAO'))");
            DB::statement("ALTER TABLE manuais ADD CONSTRAINT ck_manuais_textos CHECK (jsonb_typeof(textos) = 'object')");
            DB::statement("ALTER TABLE manuais ADD CONSTRAINT ck_manuais_opcoes CHECK (jsonb_typeof(opcoes) = 'object')");
        }
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_manuais_empresa_tipo ON manuais (empresa_id, tipo)');

        if (! Schema::hasTable('procedimentos')) {
            Schema::create('procedimentos', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->string('chave', 40);
                $t->string('origem', 8);
                $t->boolean('personalizado')->default(false);
                $t->boolean('oculto')->default(false);
                $t->string('titulo', 200)->nullable();
                $t->text('objetivo')->nullable();
                $t->jsonb('intervenientes')->default('[]');
                $t->integer('ordem')->default(0);
                $t->string('fluxo_id', 30)->nullable();
                $t->string('modulo', 30)->nullable();
                $t->char('base_hash', 64)->nullable();
                $t->string('criado_por', 100)->nullable();
                $t->string('atualizado_por', 100)->nullable();
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->foreign('empresa_id', 'fk_procedimentos_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
            });
            DB::statement("ALTER TABLE procedimentos ADD CONSTRAINT ck_procedimentos_chave CHECK (chave ~ '^[a-z0-9_]{1,40}\$')");
            DB::statement("ALTER TABLE procedimentos ADD CONSTRAINT ck_procedimentos_origem CHECK (origem IN ('FABRICA','PROPRIO'))");
            DB::statement("ALTER TABLE procedimentos ADD CONSTRAINT ck_procedimentos_proprio CHECK (origem = 'FABRICA' OR personalizado)");
            DB::statement('ALTER TABLE procedimentos ADD CONSTRAINT ck_procedimentos_titulo CHECK (NOT personalizado OR titulo IS NOT NULL)');
            DB::statement("ALTER TABLE procedimentos ADD CONSTRAINT ck_procedimentos_intervenientes CHECK (jsonb_typeof(intervenientes) = 'array')");
        }
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_procedimentos_empresa_chave ON procedimentos (empresa_id, chave)');

        if (! Schema::hasTable('procedimentos_etapas')) {
            Schema::create('procedimentos_etapas', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->unsignedBigInteger('procedimento_id');
                $t->unsignedSmallInteger('ordem');
                $t->string('nome', 200);
                $t->string('quem', 200)->nullable();
                $t->text('descricao')->nullable();
                $t->text('controlos')->nullable();
                $t->text('resultado')->nullable();
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->foreign('empresa_id', 'fk_procedimentos_etapas_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
                $t->foreign('procedimento_id', 'fk_procedimentos_etapas_procedimento_id')->references('id')->on('procedimentos')->cascadeOnDelete();
            });
            DB::statement('ALTER TABLE procedimentos_etapas ADD CONSTRAINT ck_procedimentos_etapas_ordem CHECK (ordem >= 1)');
        }
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_procedimentos_etapas_ordem ON procedimentos_etapas (procedimento_id, ordem)');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_procedimentos_etapas_empresa ON procedimentos_etapas (empresa_id, procedimento_id)');

        if (! Schema::hasTable('procedimentos_cruzamentos')) {
            Schema::create('procedimentos_cruzamentos', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->unsignedBigInteger('procedimento_id');
                $t->unsignedSmallInteger('ordem');
                $t->string('com_chave', 40);
                $t->string('onde', 200)->nullable();
                $t->text('o_que')->nullable();
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->foreign('empresa_id', 'fk_procedimentos_cruz_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
                $t->foreign('procedimento_id', 'fk_procedimentos_cruz_procedimento_id')->references('id')->on('procedimentos')->cascadeOnDelete();
            });
        }
        DB::statement('CREATE INDEX IF NOT EXISTS ix_procedimentos_cruz_proc ON procedimentos_cruzamentos (empresa_id, procedimento_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_procedimentos_cruz_com ON procedimentos_cruzamentos (empresa_id, com_chave)');

        foreach (['manuais', 'procedimentos', 'procedimentos_etapas', 'procedimentos_cruzamentos'] as $tabela) {
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
};
