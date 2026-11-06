<?php

use App\Support\POS\IntegridadePOS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F0 do POS: índices de desempenho aditivos (listagem de sessões, estadias abertas, ordens de lavandaria por estado).
 * `CREATE INDEX CONCURRENTLY IF NOT EXISTS` não bloqueia escritas (por isso fora de transacção) e é idempotente; um índice
 * inválido deixado por uma criação interrompida é recriado. Não altera dados; reverter é só apagar os índices.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        (new IntegridadePOS(DB::connection()))->aplicarIndices();
    }

    public function down(): void
    {
        foreach (IntegridadePOS::indices() as $i) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$i['nome']}");
        }
    }
};
