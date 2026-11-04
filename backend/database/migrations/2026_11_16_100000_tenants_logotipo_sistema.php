<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identidade do sistema por cliente (integração da produção, Bloco A.9): logótipo próprio do cliente, mostrado no ecrã
 * de entrada e no menu do endereço <slug>.<base>. Data URI de imagem raster (validada na gravação) ou, com o disco
 * público activo (Cloudflare R2), o URL da imagem — por isso `text`. O `nome_sistema` já existe (saas_cliente_planos_suporte).
 * Aditiva e idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tenants', 'logotipo_sistema')) {
            Schema::table('tenants', fn (Blueprint $t) => $t->text('logotipo_sistema')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tenants', 'logotipo_sistema')) {
            Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn('logotipo_sistema'));
        }
    }
};
