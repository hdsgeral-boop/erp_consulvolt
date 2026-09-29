<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * companies (legado) -> empresas. Tenant raiz do sistema multi-empresa.
 * Colunas conforme docs/dicionario/DICIONARIO_DADOS.md (as colunas de enchimento das antigas linhas
 * "dado mestre" das empresas 10 e 18 não migram).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empresas', function (Blueprint $table) {
            $table->id();                                   // id preservado do backup (setval após o ETL)
            $table->string('nome', 255);
            $table->string('nif', 30);
            $table->text('endereco')->nullable();
            $table->string('provincia', 100)->nullable();
            $table->string('municipio', 100)->nullable();
            $table->string('comuna', 100)->nullable();
            $table->string('telefone', 50)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('website', 255)->nullable();
            $table->string('numero_registo_comercial', 50)->nullable();
            $table->text('rodape_documento')->nullable();
            $table->text('logotipo')->nullable();           // data URI (base64) herdado do legado
            $table->decimal('taxa_inss_patronal', 5, 2)->default(8.00);
            $table->decimal('taxa_inss_trabalhador', 5, 2)->default(3.00);
            $table->text('regras_ia')->nullable();
            $table->string('estado', 20)->default('ATIVO');
            $table->string('estado_original', 20)->nullable();
            // Consolidação (holding)
            $table->boolean('e_consolidacao')->default(false);
            $table->string('moeda_consolidacao', 10)->nullable();
            $table->date('data_fim_consolidacao')->nullable();
            $table->unsignedBigInteger('execucao_consolidacao_id')->nullable(); // FK criada com execucoes_consolidacao
            $table->carimbosTemporais();
            $table->eliminacaoLogica();

            $table->index('nif');
        });

        DB::statement("ALTER TABLE empresas ADD CONSTRAINT ck_empresas_estado CHECK (estado IN ('ATIVO','INATIVO'))");
        DB::statement('ALTER TABLE empresas ADD CONSTRAINT ck_empresas_taxas_inss CHECK (taxa_inss_patronal BETWEEN 0 AND 100 AND taxa_inss_trabalhador BETWEEN 0 AND 100)');
        // NIF único entre empresas activas (a eliminação lógica liberta o NIF).
        DB::statement('CREATE UNIQUE INDEX uq_empresas_nif_ativas ON empresas (nif) WHERE eliminado_em IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('empresas');
    }
};
