<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POS F0b — guardar sem perda as opções de preço da lavandaria do legado (lav_pieces.lav_prices) e o contador das
 * facturas-recibo (pos_terminals.lav_fr_counters), que o ETL original não lia. Aditiva e idempotente: só acrescenta colunas
 * anuláveis; não altera tipos nem apaga. As tabelas já têm RLS e GRANT (as colunas novas herdam-nos).
 *
 *   pecas_lavandaria.opcoes_preco         [{modo: PECA|KG|TAMANHO, tamanho: '', preco: '0.00'}] — a 1.ª opção manda na grelha (legado)
 *   pecas_lavandaria.opcoes_preco_origem  LEGADO (lido de lav_prices) | DERIVADA (reconstruída de unidade+preco) | NOVO (gravada no sistema novo)
 *   terminais_pos.lavandaria_contadores_fr {ano: n} do legado (lav_fr_counters)
 *
 * Ainda não há regra de preço que as use (Fase F5): hoje a peça continua a valer pela unidade e pelo preço base.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pecas_lavandaria')) {
            Schema::table('pecas_lavandaria', function (Blueprint $t) {
                if (! Schema::hasColumn('pecas_lavandaria', 'opcoes_preco')) {
                    $t->jsonb('opcoes_preco')->nullable()->comment('legado: lav_prices · [{modo, tamanho, preco}]; a 1.ª opção manda');
                }
                if (! Schema::hasColumn('pecas_lavandaria', 'opcoes_preco_origem')) {
                    $t->string('opcoes_preco_origem', 12)->nullable()->comment('LEGADO | DERIVADA | NOVO');
                }
            });
        }
        if (Schema::hasTable('terminais_pos') && ! Schema::hasColumn('terminais_pos', 'lavandaria_contadores_fr')) {
            Schema::table('terminais_pos', fn (Blueprint $t) => $t->jsonb('lavandaria_contadores_fr')->nullable()->comment('legado: lav_fr_counters'));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('pecas_lavandaria')) {
            foreach (['opcoes_preco', 'opcoes_preco_origem'] as $c) {
                if (Schema::hasColumn('pecas_lavandaria', $c)) {
                    Schema::table('pecas_lavandaria', fn (Blueprint $t) => $t->dropColumn($c));
                }
            }
        }
        if (Schema::hasTable('terminais_pos') && Schema::hasColumn('terminais_pos', 'lavandaria_contadores_fr')) {
            Schema::table('terminais_pos', fn (Blueprint $t) => $t->dropColumn('lavandaria_contadores_fr'));
        }
    }
};
