<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 da extensão SaaS (segurança.txt, secção 12, ponto 222): MFA (TOTP) para contas privilegiadas. Aditiva.
 *
 * `mfa_segredo`: encriptado (cast `encrypted` do model, nunca em texto simples na BD); `mfa_ativo` só passa a true
 * depois do fluxo de confirmação (código TOTP válido) — nunca no momento em que o segredo é gerado. `mfa_codigos_recuperacao`:
 * hashes de códigos de uso único, para quando o utilizador perde o dispositivo de autenticação.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('utilizadores', 'mfa_segredo')) {
            DB::statement('ALTER TABLE utilizadores ADD COLUMN mfa_segredo text NULL');
        }
        if (! Schema::hasColumn('utilizadores', 'mfa_ativo')) {
            DB::statement('ALTER TABLE utilizadores ADD COLUMN mfa_ativo boolean NOT NULL DEFAULT false');
        }
        if (! Schema::hasColumn('utilizadores', 'mfa_codigos_recuperacao')) {
            DB::statement('ALTER TABLE utilizadores ADD COLUMN mfa_codigos_recuperacao jsonb NULL');
        }
        if (! Schema::hasColumn('utilizadores', 'mfa_ativado_em')) {
            DB::statement('ALTER TABLE utilizadores ADD COLUMN mfa_ativado_em timestamptz NULL');
        }
    }

    public function down(): void
    {
        foreach (['mfa_ativado_em', 'mfa_codigos_recuperacao', 'mfa_ativo', 'mfa_segredo'] as $coluna) {
            if (Schema::hasColumn('utilizadores', $coluna)) {
                DB::statement("ALTER TABLE utilizadores DROP COLUMN {$coluna}");
            }
        }
    }
};
