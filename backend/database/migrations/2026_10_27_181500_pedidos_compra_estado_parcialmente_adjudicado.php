<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cobertura parcial de pedidos de compra: novo estado PARCIALMENTE_ADJUDICADO (pedido com alguma linha já adjudicada,
 * mas ainda com saldo pendente — ver ServicoProcessoCompras::recalcularEstadoPedido). «PARCIALMENTE_ADJUDICADO» (23
 * caracteres) não cabia no varchar(20) original (2026_09_30_100500_criar_tabelas_compras.php) nem estava no CHECK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos_compra', function () {
            DB::statement('ALTER TABLE pedidos_compra ALTER COLUMN estado TYPE character varying(30)');
            DB::statement('ALTER TABLE pedidos_compra DROP CONSTRAINT IF EXISTS ck_pedidos_compra_estado');
            DB::statement("ALTER TABLE pedidos_compra ADD CONSTRAINT ck_pedidos_compra_estado CHECK (estado IS NULL OR estado::text = ANY (ARRAY['PENDENTE','APROVADO','PARCIALMENTE_ADJUDICADO','REJEITADO','ADJUDICADO','FECHADO','ANULADO']::text[]))");
        });
    }

    public function down(): void
    {
        Schema::table('pedidos_compra', function () {
            DB::statement("UPDATE pedidos_compra SET estado = 'APROVADO' WHERE estado = 'PARCIALMENTE_ADJUDICADO'");
            DB::statement('ALTER TABLE pedidos_compra DROP CONSTRAINT IF EXISTS ck_pedidos_compra_estado');
            DB::statement("ALTER TABLE pedidos_compra ADD CONSTRAINT ck_pedidos_compra_estado CHECK (estado IS NULL OR estado::text = ANY (ARRAY['PENDENTE','APROVADO','REJEITADO','ADJUDICADO','FECHADO','ANULADO']::text[]))");
            DB::statement('ALTER TABLE pedidos_compra ALTER COLUMN estado TYPE character varying(20)');
        });
    }
};
