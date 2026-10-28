<?php

use App\Support\Tenancy\IsolamentoRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Comprovativo de transferência bancária da subscrição SaaS (motor de precificação, Módulo C). Uma transferência
 * bancária é uma TransacaoGateway com gateway=MANUAL (já prevista, nunca usada antes desta fase) — o anexo só
 * acompanha essa transacção com o ficheiro enviado pelo cliente, para o super-administrador validar manualmente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anexos_comprovativo_pagamento', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('subscricao_id');
            $t->unsignedBigInteger('transacao_id')->nullable();
            $t->unsignedBigInteger('empresa_id');
            $t->string('nome_original', 255);
            $t->string('caminho', 500);
            $t->unsignedBigInteger('tamanho_bytes');
            $t->string('tipo_mime', 100);
            $t->unsignedBigInteger('enviado_por')->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestampTz('criado_em')->useCurrent();
            $t->foreign('subscricao_id', 'fk_anexos_comprovativo_subscricao_id')->references('id')->on('subscricoes_empresa')->cascadeOnDelete();
            $t->foreign('transacao_id', 'fk_anexos_comprovativo_transacao_id')->references('id')->on('transacoes_gateway')->nullOnDelete();
            $t->foreign('empresa_id', 'fk_anexos_comprovativo_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
            $t->foreign('enviado_por', 'fk_anexos_comprovativo_enviado_por')->references('id')->on('utilizadores')->nullOnDelete();
            $t->index('subscricao_id', 'idx_anexos_comprovativo_subscricao');
        });

        if (DB::selectOne("SELECT 1 AS x FROM pg_roles WHERE rolname = 'erp_app'") !== null) {
            DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON anexos_comprovativo_pagamento TO erp_app');
            DB::statement('GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO erp_app');
        }

        (new IsolamentoRls(DB::connection()))->aplicarATodas();
    }

    public function down(): void
    {
        Schema::dropIfExists('anexos_comprovativo_pagamento');
    }
};
