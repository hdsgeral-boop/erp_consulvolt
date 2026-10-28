<?php

use App\Support\Tenancy\IsolamentoRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo partilhado de «Etiquetas e sinalizações» dos projectos (por empresa, com cor): substitui o campo livre
 * `projetos.tags` (sem catálogo, sem cor). Etiquetas classificam (sector, tipo de obra, cliente…); sinalizações chamam
 * a atenção e guardam o motivo, quem a levantou e quando. Apagar uma do catálogo retira-a de todos os projectos que a
 * tinham (ON DELETE CASCADE nas tabelas de associação). Os dados existentes em `tags` são migrados para o catálogo
 * antes de a coluna ser eliminada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('etiquetas_catalogo_projeto')) {
            Schema::create('etiquetas_catalogo_projeto', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->string('nome', 60);
                $t->string('cor', 7)->default('#2563eb');
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->foreign('empresa_id', 'fk_etiquetas_catalogo_projeto_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
            });
            DB::statement('CREATE UNIQUE INDEX uq_etiquetas_catalogo_projeto_empresa_nome ON etiquetas_catalogo_projeto (empresa_id, lower(nome))');
        }

        if (! Schema::hasTable('sinalizacoes_catalogo_projeto')) {
            Schema::create('sinalizacoes_catalogo_projeto', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->string('nome', 60);
                $t->string('cor', 7)->default('#dc2626');
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->foreign('empresa_id', 'fk_sinalizacoes_catalogo_projeto_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
            });
            DB::statement('CREATE UNIQUE INDEX uq_sinalizacoes_catalogo_projeto_empresa_nome ON sinalizacoes_catalogo_projeto (empresa_id, lower(nome))');
        }

        if (! Schema::hasTable('projeto_etiqueta')) {
            Schema::create('projeto_etiqueta', function (Blueprint $t) {
                $t->unsignedBigInteger('projeto_id');
                $t->unsignedBigInteger('etiqueta_id');
                $t->timestampTz('criado_em')->useCurrent();
                $t->primary(['projeto_id', 'etiqueta_id'], 'pk_projeto_etiqueta');
                $t->foreign('projeto_id', 'fk_projeto_etiqueta_projeto_id')->references('id')->on('projetos')->cascadeOnDelete();
                $t->foreign('etiqueta_id', 'fk_projeto_etiqueta_etiqueta_id')->references('id')->on('etiquetas_catalogo_projeto')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('projeto_sinalizacao')) {
            Schema::create('projeto_sinalizacao', function (Blueprint $t) {
                $t->unsignedBigInteger('projeto_id');
                $t->unsignedBigInteger('sinalizacao_id');
                $t->text('motivo')->nullable();
                $t->string('levantada_por', 150)->nullable();
                $t->timestampTz('levantada_em')->useCurrent();
                $t->primary(['projeto_id', 'sinalizacao_id'], 'pk_projeto_sinalizacao');
                $t->foreign('projeto_id', 'fk_projeto_sinalizacao_projeto_id')->references('id')->on('projetos')->cascadeOnDelete();
                $t->foreign('sinalizacao_id', 'fk_projeto_sinalizacao_sinalizacao_id')->references('id')->on('sinalizacoes_catalogo_projeto')->cascadeOnDelete();
            });
        }

        DB::statement("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'erp_app') THEN "
            .'GRANT SELECT, INSERT, UPDATE, DELETE ON etiquetas_catalogo_projeto, sinalizacoes_catalogo_projeto, projeto_etiqueta, projeto_sinalizacao TO erp_app; '
            .'GRANT USAGE, SELECT ON SEQUENCE etiquetas_catalogo_projeto_id_seq, sinalizacoes_catalogo_projeto_id_seq TO erp_app; END IF; END $$');

        // Política RLS isolamento_tenant nas tabelas novas com empresa_id (etiquetas_catalogo_projeto, sinalizacoes_catalogo_projeto).
        (new IsolamentoRls(DB::connection()))->aplicarATodas();

        // Migra os dados de `projetos.tags` (texto livre) para o catálogo novo, uma etiqueta por valor distinto (por empresa).
        if (Schema::hasColumn('projetos', 'tags')) {
            foreach (DB::table('projetos')->whereNotNull('tags')->get(['id', 'empresa_id', 'tags']) as $p) {
                $tags = json_decode((string) $p->tags, true) ?: [];
                foreach ($tags as $nomeTag) {
                    $nome = trim((string) $nomeTag);
                    if ($nome === '') {
                        continue;
                    }
                    $etiquetaId = DB::table('etiquetas_catalogo_projeto')->where('empresa_id', $p->empresa_id)
                        ->whereRaw('lower(nome) = lower(?)', [$nome])->value('id');
                    if (! $etiquetaId) {
                        $etiquetaId = DB::table('etiquetas_catalogo_projeto')
                            ->insertGetId(['empresa_id' => $p->empresa_id, 'nome' => $nome, 'cor' => '#2563eb', 'criado_em' => now(), 'atualizado_em' => now()]);
                    }
                    DB::table('projeto_etiqueta')->insertOrIgnore(['projeto_id' => $p->id, 'etiqueta_id' => $etiquetaId, 'criado_em' => now()]);
                }
            }
            Schema::table('projetos', fn (Blueprint $t) => $t->dropColumn('tags'));
        }
    }

    public function down(): void
    {
        Schema::table('projetos', function (Blueprint $t) {
            if (! Schema::hasColumn('projetos', 'tags')) {
                $t->jsonb('tags')->nullable();
            }
        });
        Schema::dropIfExists('projeto_sinalizacao');
        Schema::dropIfExists('projeto_etiqueta');
        Schema::dropIfExists('sinalizacoes_catalogo_projeto');
        Schema::dropIfExists('etiquetas_catalogo_projeto');
    }
};
