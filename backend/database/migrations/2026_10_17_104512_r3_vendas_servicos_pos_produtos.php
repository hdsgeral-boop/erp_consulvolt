<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * POS — serviços em que cada produto se vende (pedido do utilizador: «somente devem aparecer no POS os serviços/produtos
 * marcados para cada serviço»). O legado só separava os quartos (is_room, só no POS de hotelaria — ui_sales.js:7014-7017) e
 * os serviços de lavandaria (lav_group); os restantes produtos apareciam em todos os POS.
 *
 * produtos.servicos_pos (jsonb): lista dos tipos de terminal (LOJA, RESTAURANTE, HOTELARIA, LAVANDARIA) onde o produto
 * aparece. NULL = todos (comportamento anterior, nada muda nos dados existentes); [] = em nenhum POS.
 * Aditiva e idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('produtos') && ! Schema::hasColumn('produtos', 'servicos_pos')) {
            Schema::table('produtos', function (Blueprint $table) {
                $table->jsonb('servicos_pos')->nullable()
                    ->comment('Tipos de terminal POS onde o produto aparece (LOJA, RESTAURANTE, HOTELARIA, LAVANDARIA); NULL = todos; [] = nenhum');
            });
        }
        if (Schema::hasColumn('produtos', 'servicos_pos')
            && ! DB::selectOne('SELECT 1 AS existe FROM pg_constraint WHERE conname = ?', ['ck_produtos_servicos_pos'])) {
            DB::statement("ALTER TABLE produtos ADD CONSTRAINT ck_produtos_servicos_pos CHECK (servicos_pos IS NULL OR jsonb_typeof(servicos_pos) = 'array')");
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE produtos DROP CONSTRAINT IF EXISTS ck_produtos_servicos_pos');
        if (Schema::hasColumn('produtos', 'servicos_pos')) {
            Schema::table('produtos', fn (Blueprint $table) => $table->dropColumn('servicos_pos'));
        }
    }
};
