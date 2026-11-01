<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * fk_itens_compra_item_pedido_id (2026_10_27_180000_itens_compra_item_pedido_id) foi criada pelo Blueprint
 * ($table->foreign()->restrictOnDelete()), que no PostgreSQL não gera DEFERRABLE — ao contrário de todas as
 * outras FKs do projecto (criar_chaves_estrangeiras), incluindo a FK irmã item_encomenda_id. Sem isto, fica
 * inconsistente com o resto do esquema (EsquemaContratoTest exige DEFERRABLE em todas as FKs do contrato).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE itens_compra DROP CONSTRAINT IF EXISTS fk_itens_compra_item_pedido_id');
        DB::statement('ALTER TABLE itens_compra ADD CONSTRAINT fk_itens_compra_item_pedido_id FOREIGN KEY (item_pedido_id) REFERENCES itens_compra (id) ON DELETE RESTRICT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE itens_compra DROP CONSTRAINT IF EXISTS fk_itens_compra_item_pedido_id');
        DB::statement('ALTER TABLE itens_compra ADD CONSTRAINT fk_itens_compra_item_pedido_id FOREIGN KEY (item_pedido_id) REFERENCES itens_compra (id) ON DELETE RESTRICT');
    }
};
