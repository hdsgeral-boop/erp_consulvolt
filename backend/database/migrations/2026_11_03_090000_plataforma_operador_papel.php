<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 4 da extensão SaaS (segurança.txt, secção 12): RBAC granular do back-office. Novo papel OPERADOR_PLATAFORMA
 * — tenant da plataforma, sem ser super-administrador: entra na área da plataforma (ExigirPlataforma, ao lado do
 * super-administrador), mas cada operação sensível exige a chave `plataforma_*_gerir` correspondente no seu perfil
 * (ver ServicoPermissoes::elegivelPlataforma, ClientesController e afins) — nunca acesso irrestrito como hoje.
 * Mesmo padrão de AGENTE_SUPORTE (2026_10_20_090000_suporte_agente_papel.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE utilizadores DROP CONSTRAINT IF EXISTS ck_utilizadores_papel');
        DB::statement("ALTER TABLE utilizadores ADD CONSTRAINT ck_utilizadores_papel CHECK (papel IN ('SUPER_ADMINISTRADOR','ADMINISTRADOR','UTILIZADOR','AGENTE_SUPORTE','OPERADOR_PLATAFORMA'))");
    }

    public function down(): void
    {
        DB::statement("UPDATE utilizadores SET papel = 'UTILIZADOR' WHERE papel = 'OPERADOR_PLATAFORMA'");
        DB::statement('ALTER TABLE utilizadores DROP CONSTRAINT IF EXISTS ck_utilizadores_papel');
        DB::statement("ALTER TABLE utilizadores ADD CONSTRAINT ck_utilizadores_papel CHECK (papel IN ('SUPER_ADMINISTRADOR','ADMINISTRADOR','UTILIZADOR','AGENTE_SUPORTE'))");
    }
};
