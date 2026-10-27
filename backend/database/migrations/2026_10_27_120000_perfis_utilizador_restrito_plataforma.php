<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-077: perfis do backoffice de suporte (técnico/financeiro/marketing) vivem na mesma tabela global
 * `perfis_utilizador` que os perfis das empresas clientes (a tabela não tem tenant_id/empresa_id — é partilhada por
 * toda a instalação). Esta coluna isola-os da listagem normal (GET /sistema/perfis, usada pelo selector de Perfil de
 * cada empresa): não é uma fronteira de segurança (o middleware `suporte`/`plataforma` continua a ser essa
 * fronteira), só evita que apareçam como opção a uma empresa cliente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('perfis_utilizador')) {
            return;
        }
        Schema::table('perfis_utilizador', function (Blueprint $t) {
            if (! Schema::hasColumn('perfis_utilizador', 'restrito_plataforma')) {
                $t->boolean('restrito_plataforma')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('perfis_utilizador', function (Blueprint $t) {
            if (Schema::hasColumn('perfis_utilizador', 'restrito_plataforma')) {
                $t->dropColumn('restrito_plataforma');
            }
        });
    }
};
