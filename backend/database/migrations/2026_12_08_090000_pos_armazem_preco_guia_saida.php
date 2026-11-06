<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POS F3 — a guia de saída do balcão de armazém passa a guardar o preço de venda de cada linha no momento da saída, para
 * o impresso entregue ao cliente (Guia de Saída A4 e talão de levantamento) mostrar preço e total como no legado, sem
 * revelar o custo. Aditiva e idempotente: só acrescenta uma coluna anulável (a tabela já tem RLS e GRANT; a coluna herda-os).
 * As guias existentes ficam com NULL: o impresso mostra só as quantidades.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('itens_guia_saida') && ! Schema::hasColumn('itens_guia_saida', 'preco_unitario_kz')) {
            Schema::table('itens_guia_saida', fn (Blueprint $t) => $t->decimal('preco_unitario_kz', 15, 2)->nullable()->comment('POS de armazém: preço de venda unitário na altura da saída (sem custo)'));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('itens_guia_saida') && Schema::hasColumn('itens_guia_saida', 'preco_unitario_kz')) {
            Schema::table('itens_guia_saida', fn (Blueprint $t) => $t->dropColumn('preco_unitario_kz'));
        }
    }
};
