<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Desempenho (ADR-085, docs/DESEMPENHO_BACKEND_2026-11.md). A listagem de notificações («só não lidas», centro de
 * notificações e sino) faz `WHERE empresa_id = ? AND lida = false ORDER BY id DESC LIMIT 80`. Os índices existentes
 * (`empresa_id, lida, criado_em` e `empresa_id, criado_em`) não servem a ordenação por `id`; quando a empresa já leu tudo
 * (o caso normal) o planeador percorre a chave primária de trás para a frente, linha a linha, até ao fim da tabela.
 * Medido em dados sintéticos (600 000 notificações, 40 empresas): 360-377 ms -> 0,23-0,26 ms (índice parcial de 3,7 MB).
 *
 * Aditiva e segura em produção: `CREATE INDEX CONCURRENTLY IF NOT EXISTS` não bloqueia escritas (por isso fora de
 * transacção) e é idempotente; reverter é só apagar o índice. Não altera dados.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_notificacoes_nao_lidas ON notificacoes_sistema (empresa_id, id DESC) WHERE lida = false');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_notificacoes_nao_lidas');
    }
};
