<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cobertura parcial de pedidos de compra (ADR pendente): uma linha de proposta (cotação) passa a apontar para a
 * linha do pedido a que responde — até agora a ligação era feita de forma transitória por produto_id
 * (ServicoProcessoCompras::comparar), sem suportar duas linhas do mesmo produto nem uma quantidade parcial.
 * Mesmo padrão de `item_encomenda_id` (auto-referência a itens_compra, fora do CHECK ck_itens_compra_documento_origem
 * — é uma referência cruzada auxiliar, não o «dono» da linha).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('itens_compra', function (Blueprint $table) {
            if (! Schema::hasColumn('itens_compra', 'item_pedido_id')) {
                $table->unsignedBigInteger('item_pedido_id')->nullable()->after('item_encomenda_id');
                $table->index('item_pedido_id', 'ix_itens_compra_item_pedido_id');
                $table->foreign('item_pedido_id', 'fk_itens_compra_item_pedido_id')->references('id')->on('itens_compra')->restrictOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('itens_compra', function (Blueprint $table) {
            if (Schema::hasColumn('itens_compra', 'item_pedido_id')) {
                $table->dropForeign('fk_itens_compra_item_pedido_id');
                $table->dropIndex('ix_itens_compra_item_pedido_id');
                $table->dropColumn('item_pedido_id');
            }
        });
    }
};
