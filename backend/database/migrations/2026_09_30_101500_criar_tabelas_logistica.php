<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo Logistica — GERADO por ferramentas/gerador/gerar_esquema.mjs a partir de docs/dicionario/mapa_de_para.json.
 * Não editar à mão: alterar glossario.mjs / esquema_extra.mjs e regenerar.
 * As chaves estrangeiras são criadas em 2026_09_30_900000_criar_chaves_estrangeiras.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        // configuracoes_contabeis_logistica (tabela nova) · 0 linhas reais no backup
        Schema::create('configuracoes_contabeis_logistica', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('empresa_id')->comment('tenant (derivado no ETL)');
            $table->string('chave', 150)->nullable()->comment('Chave da conta (ver ServicoConfigLogistica::CHAVES)');
            $table->string('codigo_conta', 20)->nullable()->comment('Conta do plano');
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->timestampTz('atualizado_em')->nullable()->useCurrent();
        });
        DB::statement('CREATE UNIQUE INDEX uq_configuracoes_contabeis_logistica_empresa_id_chave ON configuracoes_contabeis_logistica (empresa_id, chave)');
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracoes_contabeis_logistica');
    }
};
