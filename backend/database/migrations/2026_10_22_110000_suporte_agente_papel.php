<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SaaS, fase 4 (fatia 3): backoffice de suporte isolado (ADR-076). Novo papel AGENTE_SUPORTE — tenant da plataforma,
 * sem ser super-administrador: só vê e responde a tickets (ExigirSuporte), nunca clientes, planos ou definições
 * (continuam atrás de ExigirPlataforma, que exige SUPER_ADMINISTRADOR).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE utilizadores DROP CONSTRAINT IF EXISTS ck_utilizadores_papel');
        DB::statement("ALTER TABLE utilizadores ADD CONSTRAINT ck_utilizadores_papel CHECK (papel IN ('SUPER_ADMINISTRADOR','ADMINISTRADOR','UTILIZADOR','AGENTE_SUPORTE'))");
    }

    public function down(): void
    {
        DB::statement("UPDATE utilizadores SET papel = 'UTILIZADOR' WHERE papel = 'AGENTE_SUPORTE'");
        DB::statement('ALTER TABLE utilizadores DROP CONSTRAINT IF EXISTS ck_utilizadores_papel');
        DB::statement("ALTER TABLE utilizadores ADD CONSTRAINT ck_utilizadores_papel CHECK (papel IN ('SUPER_ADMINISTRADOR','ADMINISTRADOR','UTILIZADOR'))");
    }
};
