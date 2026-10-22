<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Licenças (pedido de 2026-10-22): cria os 4 planos iniciais e atribui o plano Interno a consulvolt e grupoglomeu
 * (erp:licencas:planos-iniciais), e grava «licenças activas» nas definições da plataforma (o efectivo vem de
 * LICENCAS_ATIVAS, que o docker-compose.aws.yml liga em produção). Nos testes não corre: cada teste parte de um
 * catálogo de planos vazio.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! app()->runningUnitTests()) {
            Artisan::call('erp:licencas:planos-iniciais');
            if (Schema::hasTable('definicoes_plataforma')) {
                DB::table('definicoes_plataforma')->updateOrInsert(['chave' => 'licencas_ativas'], ['valor' => 'true', 'atualizado_em' => now()]);
            }
        }
    }

    public function down(): void
    {
        // Dados de negócio (planos e atribuições): não se desfazem automaticamente.
    }
};
