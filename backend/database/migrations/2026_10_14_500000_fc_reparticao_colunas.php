<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase C (Versão Actual, ADR-071) — repartição por unidades de negócio. Migration ADITIVA com guardas:
 *   - `unidades_alocadas` (jsonb) nas facturas de compra, encomendas de compra e recibos de venda — o legado novo guarda a
 *     alocação {criterio_id, unidades:[{business_unit_id, peso}]} em purchase_invoices/receipts e lê-a em purchase_orders
 *     (ui_compras_v2.js:3506-3524, ui_sales.js:3784-3797). Vendas, pedidos de compra e colaboradores já a têm (esquema.json);
 *   - `criterios_reparticao_un.indicador` alargado de varchar(10) para varchar(100): é o nome livre do indicador
 *     («horas-máquina», «viaturas»…), que o legado não limita (reparticao.js:96). Alargar não perde dados.
 * Nada é apagado nem estreitado; o down() só retira o que este ficheiro acrescentou.
 */
return new class extends Migration
{
    private const TABELAS = ['faturas_compra', 'encomendas_compra', 'recibos_venda'];

    public function up(): void
    {
        foreach (self::TABELAS as $tabela) {
            if (Schema::hasTable($tabela) && ! Schema::hasColumn($tabela, 'unidades_alocadas')) {
                DB::statement("ALTER TABLE {$tabela} ADD COLUMN unidades_alocadas jsonb NULL");
                DB::statement("COMMENT ON COLUMN {$tabela}.unidades_alocadas IS 'Fase C: repartição por unidades de negócio {criterio_id, unidades:[{unidade_negocio_id, peso}]} (legado: unidades_alocadas)'");
            }
        }

        if (Schema::hasTable('criterios_reparticao_un') && Schema::hasColumn('criterios_reparticao_un', 'indicador')) {
            $tamanho = DB::selectOne("SELECT character_maximum_length AS n FROM information_schema.columns
                WHERE table_schema = current_schema() AND table_name = 'criterios_reparticao_un' AND column_name = 'indicador'")?->n;
            if ($tamanho !== null && (int) $tamanho < 100) {
                DB::statement('ALTER TABLE criterios_reparticao_un ALTER COLUMN indicador TYPE varchar(100)');
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABELAS as $tabela) {
            if (Schema::hasTable($tabela) && Schema::hasColumn($tabela, 'unidades_alocadas')) {
                DB::statement("ALTER TABLE {$tabela} DROP COLUMN unidades_alocadas");
            }
        }
        // o alargamento de criterios_reparticao_un.indicador não se reverte (estreitar poderia truncar dados)
    }
};
