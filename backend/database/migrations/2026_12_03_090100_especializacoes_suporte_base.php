<?php

use App\Services\Plataforma\ServicoEspecializacoesSuporte;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Especializações de suporte base (ADR-077): Geral, Técnico, Financeiro, Marketing e Comunicação e «todas as
 * categorias». Aditiva e idempotente — só cria o que falta (comparando conjunto de categorias e nome); nunca altera nem
 * apaga perfis existentes. `perfis_utilizador` é global (sem tenant_id), por isso não depende do tenant da plataforma.
 * Também disponível manualmente: `php artisan erp:suporte:criar-especializacoes`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('perfis_utilizador') || ! Schema::hasColumn('perfis_utilizador', 'restrito_plataforma') || ! Schema::hasColumn('perfis_utilizador', 'ativo')) {
            return;
        }
        // A suite corre migrate:fresh em cada execução e vários testes assumem `perfis_utilizador` só com os seus próprios
        // dados: nos testes as especializações criam-se explicitamente (garantirBase / comando / selector lazy).
        if (app()->environment('testing')) {
            return;
        }
        app(ServicoEspecializacoesSuporte::class)->garantirBase();
    }

    public function down(): void
    {
        // Dados aditivos: não se apagam (poderiam já estar atribuídos a agentes).
    }
};
