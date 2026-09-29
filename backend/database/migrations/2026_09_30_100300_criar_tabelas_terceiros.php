<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo Terceiros — GERADO por ferramentas/gerador/gerar_esquema.mjs a partir de docs/dicionario/mapa_de_para.json.
 * Não editar à mão: alterar glossario.mjs / esquema_extra.mjs e regenerar.
 * As chaves estrangeiras são criadas em 2026_09_30_900000_criar_chaves_estrangeiras.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        // third_parties (legado) -> terceiros · 6545 linhas reais no backup · eliminação lógica
        Schema::create('terceiros', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->string('nif', 50)->nullable()->comment('legado: nif · tipos mistos: string=656, string_inteiro=5889');
            $table->string('nome', 255)->nullable()->comment('legado: name');
            $table->string('tipo', 23)->nullable()->comment('legado: type · código normalizado ∈ {CLIENTE, FORNECEDOR, COLABORADOR, CLIENTE_FORNECEDOR}; texto original em tipo_original');
            $table->string('tipo_original', 100)->nullable()->comment('legado: type · texto exacto do legado');
            $table->text('endereco')->nullable()->comment('legado: address');
            $table->string('codigo_conta', 20)->nullable()->comment('legado: account_code · tipos mistos: string_inteiro=301, string_decimal=8');
            $table->string('conta_compra_transitoria', 20)->nullable()->comment('legado: account_purchase_clearing');
            $table->string('email', 150)->nullable()->comment('legado: email');
            $table->string('codigo_moeda', 50)->nullable()->comment('legado: currency');
            $table->string('telefone', 50)->nullable()->comment('legado: phone');
            $table->string('fe_pais', 255)->nullable()->comment('legado: fe_pais · do código legado js/ui_sales.js:2197');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
            $table->timestampTz('eliminado_em')->nullable();
        });
        DB::statement('CREATE INDEX ix_terceiros_empresa_id ON terceiros (empresa_id)');
        DB::statement('CREATE INDEX ix_terceiros_empresa_id_codigo_conta ON terceiros (empresa_id, codigo_conta)');
        DB::statement('CREATE INDEX ix_terceiros_empresa_id_nif ON terceiros (empresa_id, nif)');
        DB::statement('ALTER TABLE terceiros ADD CONSTRAINT ck_terceiros_tipo CHECK (tipo IS NULL OR tipo IN (\'CLIENTE\',\'FORNECEDOR\',\'COLABORADOR\',\'CLIENTE_FORNECEDOR\'))');

        // third_party_addresses (legado) -> enderecos_terceiros · 54 linhas reais no backup
        Schema::create('enderecos_terceiros', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->string('nif', 30)->nullable()->comment('legado: nif · tipos mistos: string_inteiro=44, string=10');
            $table->string('rua', 150)->nullable()->comment('legado: street');
            $table->string('numero_porta', 50)->nullable()->comment('legado: door_number');
            $table->string('bairro', 150)->nullable()->comment('legado: neighborhood');
            $table->string('municipio', 150)->nullable()->comment('legado: municipality');
            $table->string('provincia', 150)->nullable()->comment('legado: province');
            $table->string('pais', 150)->nullable()->comment('legado: country');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_enderecos_terceiros_empresa_id ON enderecos_terceiros (empresa_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('enderecos_terceiros');
        Schema::dropIfExists('terceiros');
    }
};
