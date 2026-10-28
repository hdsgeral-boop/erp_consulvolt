<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Factura AGT automática da subscrição SaaS (motor de precificação, Módulo D): liga a subscrição PAGA à Venda (FT)
 * emitida na empresa de facturação da plataforma (ServicoFaturacaoSaas). Nullable: subscrições antigas, ambientes
 * sem `erp.faturacao_saas.empresa_id` configurado, ou uma emissão falhada (nunca desfaz o pagamento já confirmado).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscricoes_empresa', function (Blueprint $t) {
            $t->unsignedBigInteger('venda_id')->nullable()->after('valor_desconto_cupom');
            $t->foreign('venda_id', 'fk_subscricoes_empresa_venda_id')->references('id')->on('vendas')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subscricoes_empresa', function (Blueprint $t) {
            $t->dropForeign('fk_subscricoes_empresa_venda_id');
            $t->dropColumn('venda_id');
        });
    }
};
