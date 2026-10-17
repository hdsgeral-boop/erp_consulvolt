<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Logística — guias de remessa (GR) emitidas no formulário «Emitir Guia de Saída» do Armazém (legado: showGuiaSaidaForm /
 * saveGuiaSaida, js/ui_warehouse.js:603-1008, tipos VENDA e BACK_TO_BACK). A saída para o cliente é a GR das Vendas (ADR-043):
 * esta coluna só marca que a GR saiu do armazém e com que tipo, para a lista «Guias de Saída e Entrega» a mostrar a par das
 * guias de consumo (o legado gravava a guia e uma «GR LOG» espelho — dois documentos para a mesma saída). Aditiva e idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('vendas', 'saida_armazem')) {
            Schema::table('vendas', function (Blueprint $table) {
                $table->string('saida_armazem', 20)->nullable()->comment('GR emitida no Armazém: VENDA ou BACK_TO_BACK (delivery_notes.type do legado)');
            });
        }
        if (! DB::selectOne('SELECT 1 AS existe FROM pg_constraint WHERE conname = ?', ['ck_vendas_saida_armazem'])) {
            DB::statement("ALTER TABLE vendas ADD CONSTRAINT ck_vendas_saida_armazem CHECK (saida_armazem IS NULL OR (saida_armazem IN ('VENDA', 'BACK_TO_BACK') AND tipo_documento = 'GR'))");
        }
        DB::statement('CREATE INDEX IF NOT EXISTS ix_vendas_saida_armazem ON vendas (empresa_id, data_emissao) WHERE saida_armazem IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ix_vendas_saida_armazem');
        DB::statement('ALTER TABLE vendas DROP CONSTRAINT IF EXISTS ck_vendas_saida_armazem');
        if (Schema::hasColumn('vendas', 'saida_armazem')) {
            Schema::table('vendas', fn (Blueprint $table) => $table->dropColumn('saida_armazem'));
        }
    }
};
