<?php

use App\Support\Tenancy\IsolamentoRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase K (ADR-079) — delegação da chefia: durante um período e para um âmbito (PORTAL, METODO, ENTREGAVEIS) o delegado pode
 * decidir o que cabia ao delegante. A validade é por datas (não há revogação automática). Também acrescenta `por_delegado_de`
 * (nome e colaborador do delegante) às decisões já registadas por tabela: entregas validadas e impedimentos decididos; nos
 * pedidos do portal e nas deliberações do Método a marca vai dentro do JSON da etapa/deliberação.
 * Migração aditiva, com guardas; GRANT ao papel erp_app e política RLS (ADR-073).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('delegacoes_chefia')) {
            Schema::create('delegacoes_chefia', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->unsignedBigInteger('delegante_colaborador_id');
                $t->unsignedBigInteger('delegado_colaborador_id');
                $t->date('data_inicio');
                $t->date('data_fim');
                $t->jsonb('ambito')->comment('lista ⊂ {PORTAL, METODO, ENTREGAVEIS}');
                $t->string('motivo', 255)->nullable();
                $t->string('estado', 10)->default('ATIVA');
                $t->string('revogada_por', 100)->nullable();
                $t->timestampTz('revogada_em')->nullable();
                $t->string('criado_por', 100)->nullable();
                $t->unsignedBigInteger('criado_por_colaborador_id')->nullable();
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->foreign('empresa_id', 'fk_delegacoes_chefia_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
                $t->foreign('delegante_colaborador_id', 'fk_delegacoes_chefia_delegante')->references('id')->on('colaboradores')->restrictOnDelete();
                $t->foreign('delegado_colaborador_id', 'fk_delegacoes_chefia_delegado')->references('id')->on('colaboradores')->restrictOnDelete();
                $t->foreign('criado_por_colaborador_id', 'fk_delegacoes_chefia_criador')->references('id')->on('colaboradores')->nullOnDelete();
            });
            DB::statement("ALTER TABLE delegacoes_chefia ADD CONSTRAINT ck_delegacoes_chefia_estado CHECK (estado IN ('ATIVA','REVOGADA'))");
            DB::statement('ALTER TABLE delegacoes_chefia ADD CONSTRAINT ck_delegacoes_chefia_datas CHECK (data_fim >= data_inicio)');
            DB::statement('ALTER TABLE delegacoes_chefia ADD CONSTRAINT ck_delegacoes_chefia_pessoas CHECK (delegante_colaborador_id <> delegado_colaborador_id)');
            DB::statement('CREATE INDEX ix_delegacoes_chefia_delegado ON delegacoes_chefia (empresa_id, delegado_colaborador_id, estado)');
            DB::statement('CREATE INDEX ix_delegacoes_chefia_delegante ON delegacoes_chefia (empresa_id, delegante_colaborador_id, estado)');
        }

        foreach (['execucoes_entregavel', 'impedimentos_entregavel'] as $tabela) {
            if (Schema::hasTable($tabela) && ! Schema::hasColumn($tabela, 'por_delegado_de')) {
                Schema::table($tabela, fn (Blueprint $t) => $t->jsonb('por_delegado_de')->nullable()->comment('{colaborador_id, nome} do delegante, se decidido por delegação'));
            }
        }

        DB::statement("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'erp_app') THEN "
            .'GRANT SELECT, INSERT, UPDATE, DELETE ON delegacoes_chefia TO erp_app; '
            .'GRANT USAGE, SELECT ON SEQUENCE delegacoes_chefia_id_seq TO erp_app; END IF; END $$');

        (new IsolamentoRls(DB::connection()))->aplicarATodas();
    }

    public function down(): void
    {
        foreach (['execucoes_entregavel', 'impedimentos_entregavel'] as $tabela) {
            if (Schema::hasTable($tabela) && Schema::hasColumn($tabela, 'por_delegado_de')) {
                Schema::table($tabela, fn (Blueprint $t) => $t->dropColumn('por_delegado_de'));
            }
        }
        Schema::dropIfExists('delegacoes_chefia');
    }
};
