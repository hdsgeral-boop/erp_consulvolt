<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ronda 4 — registos_conversao_contas_produtos passou a constar do esquema gerado (TABELAS_NOVAS em
 * ferramentas/gerador/esquema_extra.mjs). Todas as FKs do projecto são DEFERRABLE INITIALLY IMMEDIATE (contrato do
 * esquema, EsquemaContratoTest); as desta tabela foram criadas com $table->foreign() (não adiáveis). Torna-as adiáveis
 * sem as recriar (ALTER CONSTRAINT): não mexe em dados nem na regra ON DELETE.
 */
return new class extends Migration
{
    private const COLUNAS = ['empresa_id', 'produto_id', 'categoria_nova_id'];

    public function up(): void
    {
        $this->alterar('DEFERRABLE INITIALLY IMMEDIATE', false);
    }

    public function down(): void
    {
        $this->alterar('NOT DEFERRABLE', true);
    }

    private function alterar(string $modo, bool $adiavel): void
    {
        if (! Schema::hasTable('registos_conversao_contas_produtos')) {
            return;
        }
        foreach (self::COLUNAS as $coluna) {
            $fk = DB::selectOne(<<<'SQL'
                SELECT c.conname AS nome FROM pg_constraint c
                JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = ANY (c.conkey)
                WHERE c.contype = 'f' AND c.conrelid = 'registos_conversao_contas_produtos'::regclass AND a.attname = ? AND c.condeferrable = ?
            SQL, [$coluna, $adiavel]);
            if ($fk) {
                DB::statement('ALTER TABLE registos_conversao_contas_produtos ALTER CONSTRAINT "'.str_replace('"', '', $fk->nome).'" '.$modo);
            }
        }
    }
};
