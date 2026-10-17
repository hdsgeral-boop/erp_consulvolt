<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ronda 3 (r3_teso) — ligação do recebimento de tesouraria ao recibo de venda que o gerou (aditivo, com guardas).
 * Cada recibo de cliente (RE, recibo de adiantamento e recibo da factura-recibo) tem o SEU registo de tesouraria
 * (RECEBIMENTO) que espelha o estado do recibo: PENDENTE (por contabilizar), INTEGRADO (contabilizado — o lançamento é o
 * do recibo, não há um segundo) ou ANULADO. Um recibo → no máximo um documento (índice único parcial). Nenhum dado
 * existente é alterado; os recibos anteriores ligam-se com `erp:tesouraria:reconciliar-recibos` (--simular primeiro).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('documentos_tesouraria') || Schema::hasColumn('documentos_tesouraria', 'recibo_venda_id')) {
            return;
        }
        Schema::table('documentos_tesouraria', function (Blueprint $t) {
            $t->bigInteger('recibo_venda_id')->nullable()->comment('Recibo de venda que gerou este recebimento (espelho; contabiliza-se pelo recibo)');
        });
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_documentos_tesouraria_empresa_recibo_venda ON documentos_tesouraria (empresa_id, recibo_venda_id) WHERE recibo_venda_id IS NOT NULL');
        if (Schema::hasTable('recibos_venda')) {
            DB::statement('ALTER TABLE documentos_tesouraria ADD CONSTRAINT fk_documentos_tesouraria_recibo_venda_id FOREIGN KEY (recibo_venda_id) REFERENCES recibos_venda (id) ON DELETE RESTRICT DEFERRABLE INITIALLY IMMEDIATE');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('documentos_tesouraria') || ! Schema::hasColumn('documentos_tesouraria', 'recibo_venda_id')) {
            return;
        }
        DB::statement('ALTER TABLE documentos_tesouraria DROP CONSTRAINT IF EXISTS fk_documentos_tesouraria_recibo_venda_id');
        DB::statement('DROP INDEX IF EXISTS uq_documentos_tesouraria_empresa_recibo_venda');
        Schema::table('documentos_tesouraria', fn (Blueprint $t) => $t->dropColumn('recibo_venda_id'));
    }
};
