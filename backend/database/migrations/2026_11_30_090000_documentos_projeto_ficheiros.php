<?php

use App\Support\Tenancy\IsolamentoRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Documentos do projecto (separador «Documentos»): catálogo de tipos por empresa e metadados do ficheiro em
 * `documentos_projeto` (o PDF vive no disco de objectos; na base fica só o caminho, o nome original, o tamanho, o
 * hash SHA-256 e o tipo MIME). Aditiva e idempotente: nenhuma coluna existente é alterada; `tipo_documento_id`
 * (que aponta para os tipos de documento de venda) fica sem uso e o tipo passa a ser `tipo_projeto_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tipos_documento_projeto')) {
            Schema::create('tipos_documento_projeto', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->string('nome', 60);
                $t->unsignedSmallInteger('ordem')->default(0);
                $t->boolean('ativo')->default(true);
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->foreign('empresa_id', 'fk_tipos_documento_projeto_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
            });
            DB::statement('CREATE UNIQUE INDEX uq_tipos_documento_projeto_empresa_nome ON tipos_documento_projeto (empresa_id, lower(nome))');
        }

        Schema::table('documentos_projeto', function (Blueprint $t) {
            if (! Schema::hasColumn('documentos_projeto', 'tipo_projeto_id')) {
                $t->unsignedBigInteger('tipo_projeto_id')->nullable();
                $t->foreign('tipo_projeto_id', 'fk_documentos_projeto_tipo_projeto_id')->references('id')->on('tipos_documento_projeto')->nullOnDelete();
            }
            foreach ([
                'titulo' => fn () => $t->string('titulo', 200)->nullable(),
                'versao' => fn () => $t->unsignedInteger('versao')->default(1),
                'grupo_uid' => fn () => $t->uuid('grupo_uid')->nullable(),
                'data_documento' => fn () => $t->date('data_documento')->nullable(),
                'autor' => fn () => $t->string('autor', 150)->nullable(),
                'observacoes' => fn () => $t->text('observacoes')->nullable(),
                'caminho' => fn () => $t->string('caminho', 500)->nullable(),
                'mime' => fn () => $t->string('mime', 100)->nullable(),
                'tamanho_bytes' => fn () => $t->unsignedBigInteger('tamanho_bytes')->nullable(),
                'hash_sha256' => fn () => $t->string('hash_sha256', 64)->nullable(),
                'eliminado_em' => fn () => $t->timestampTz('eliminado_em')->nullable(),
                'eliminado_por' => fn () => $t->string('eliminado_por', 150)->nullable(),
            ] as $coluna => $criar) {
                if (! Schema::hasColumn('documentos_projeto', $coluna)) {
                    $criar();
                }
            }
        });
        DB::statement('CREATE INDEX IF NOT EXISTS ix_documentos_projeto_grupo_uid ON documentos_projeto (grupo_uid)');

        DB::statement("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'erp_app') THEN "
            .'GRANT SELECT, INSERT, UPDATE, DELETE ON tipos_documento_projeto, documentos_projeto TO erp_app; '
            .'GRANT USAGE, SELECT ON SEQUENCE tipos_documento_projeto_id_seq, documentos_projeto_id_seq TO erp_app; END IF; END $$');

        (new IsolamentoRls(DB::connection()))->aplicarATodas();
    }

    public function down(): void
    {
        // Aditiva: não se removem colunas nem dados.
    }
};
