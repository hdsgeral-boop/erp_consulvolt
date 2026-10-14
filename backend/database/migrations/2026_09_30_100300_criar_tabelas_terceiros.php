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
        // third_parties (legado) -> terceiros · 6561 linhas reais no backup · eliminação lógica
        Schema::create('terceiros', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->string('nif', 50)->nullable()->comment('legado: nif · tipos mistos: string=664, string_inteiro=5897');
            $table->string('nome', 255)->nullable()->comment('legado: name');
            $table->string('tipo', 23)->nullable()->comment('legado: type · código normalizado ∈ {CLIENTE, FORNECEDOR, COLABORADOR, CLIENTE_FORNECEDOR}; texto original em tipo_original');
            $table->string('tipo_original', 100)->nullable()->comment('legado: type · texto exacto do legado');
            $table->text('endereco')->nullable()->comment('legado: address');
            $table->string('codigo_conta', 20)->nullable()->comment('legado: account_code · tipos mistos: string_inteiro=306, string_decimal=8');
            $table->string('conta_fornecedor', 20)->nullable()->comment('legado: account_supplier');
            $table->string('conta_compra_transitoria', 20)->nullable()->comment('legado: account_purchase_clearing');
            $table->string('email', 150)->nullable()->comment('legado: email');
            $table->string('conta_cliente', 20)->nullable()->comment('legado: account_client · tipos mistos: string_decimal=8, string_inteiro=670');
            $table->string('codigo_moeda', 50)->nullable()->comment('legado: currency');
            $table->text('fe_pais')->nullable()->comment('legado: fe_pais · sem valores reais: tipo a confirmar no código legado');
            $table->string('telefone', 50)->nullable()->comment('legado: phone');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
            $table->timestampTz('eliminado_em')->nullable();
        });
        DB::statement('CREATE INDEX ix_terceiros_empresa_id ON terceiros (empresa_id)');
        DB::statement('CREATE INDEX ix_terceiros_empresa_id_codigo_conta ON terceiros (empresa_id, codigo_conta)');
        DB::statement('CREATE INDEX ix_terceiros_empresa_id_nif ON terceiros (empresa_id, nif)');
        DB::statement('ALTER TABLE terceiros ADD CONSTRAINT ck_terceiros_tipo CHECK (tipo IS NULL OR tipo IN (\'CLIENTE\',\'FORNECEDOR\',\'COLABORADOR\',\'CLIENTE_FORNECEDOR\'))');

        // third_party_addresses (legado) -> enderecos_terceiros · 59 linhas reais no backup
        Schema::create('enderecos_terceiros', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('legado: company_id');
            $table->string('nif', 30)->nullable()->comment('legado: nif · tipos mistos: string_inteiro=49, string=10');
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

        // third_party_bank_details (legado) -> contas_bancarias_terceiros · 0 linhas reais no backup
        Schema::create('contas_bancarias_terceiros', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('tenant (derivado no ETL)');
            $table->bigInteger('terceiro_id')->nullable()->comment('legado: third_party_id · do código legado Versões/Versão Actual/ERP/js/ui_aux.js:976');
            $table->bigInteger('banco_id')->nullable()->comment('legado: bank_id · do código legado js/db_v2.js (v1271, índice)');
            $table->string('banco', 150)->nullable()->comment('legado: banco · do código legado Versões/Versão Actual/ERP/js/ui_aux.js:976');
            $table->string('numero_conta', 50)->nullable()->comment('legado: numero_conta · do código legado Versões/Versão Actual/ERP/js/ui_aux.js:976');
            $table->string('iban', 50)->nullable()->comment('legado: iban · do código legado Versões/Versão Actual/ERP/js/ui_aux.js:976');
            $table->string('titular', 150)->nullable()->comment('legado: titular · do código legado Versões/Versão Actual/ERP/js/ui_aux.js:976');
            $table->boolean('predefinida')->nullable()->comment('legado: predefinida · do código legado Versões/Versão Actual/ERP/js/ui_aux.js:976');
            $table->string('codigo_moeda', 10)->nullable()->comment('Moeda da conta (opcional)');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE INDEX ix_contas_bancarias_terceiros_empresa_id ON contas_bancarias_terceiros (empresa_id)');
        DB::statement('CREATE INDEX ix_contas_bancarias_terceiros_terceiro_id ON contas_bancarias_terceiros (terceiro_id)');
        DB::statement('CREATE INDEX ix_contas_bancarias_terceiros_banco_id ON contas_bancarias_terceiros (banco_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('contas_bancarias_terceiros');
        Schema::dropIfExists('enderecos_terceiros');
        Schema::dropIfExists('terceiros');
    }
};
