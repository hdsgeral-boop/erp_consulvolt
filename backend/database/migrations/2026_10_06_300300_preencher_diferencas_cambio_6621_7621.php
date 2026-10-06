<?php

use App\Services\Tesouraria\ServicoConfigTesouraria;
use Illuminate\Database\Migrations\Migration;

/**
 * Decisão 19 do utilizador: 6621 (favoráveis) / 7621 (desfavoráveis), como o legado, nas configurações de tesouraria e
 * de compras das empresas que já existem (ex.: produção). Numa base nova, as empresas chegam depois com a migração do
 * legado, que volta a aplicar este passo depois do COMMIT (ServicoMigracaoLegado::aplicarPosCarga; para repetir:
 * `php artisan erp:migracao:pos-carga` ou `php artisan erp:tesouraria:diferencas-cambio`). Só preenche chaves vazias; down() não desfaz
 * (não se sabe que configurações eram já do utilizador).
 */
return new class extends Migration
{
    public function up(): void
    {
        ServicoConfigTesouraria::preencherDiferencasCambio();
    }

    public function down(): void {}
};
