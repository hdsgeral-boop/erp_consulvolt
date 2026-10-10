<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tesouraria — índice (empresa_id, estado) em documentos_tesouraria (aditivo). Serve os indicadores «Pendentes» /
 * «Integrados» dos agregadores (GET /api/tesouraria/resumo), as listas filtradas por estado (Integração, Histórico)
 * e a pré-visualização da reparação de integrações (verificação visual 7.0.1 e 7.4.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS ix_documentos_tesouraria_empresa_id_estado ON documentos_tesouraria (empresa_id, estado)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ix_documentos_tesouraria_empresa_id_estado');
    }
};
