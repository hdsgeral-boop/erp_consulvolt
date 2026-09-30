<?php

use App\Models\ContratoFornecedor;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\Schedule;

// Partições anuais da auditoria preparadas com antecedência (1 de Dezembro).
Schedule::command('erp:auditoria:particoes --anos=2')->yearlyOn(12, 1, '02:00');

// Facturação electrónica: envio automático e consulta de estados na AGT (no legado corria no browser, de 2 em 2 min).
Schedule::command('erp:agt:ciclo')->everyTwoMinutes()->withoutOverlapping();

// Contratos de fornecedores: ATIVO → EXPIRADO depois da data de fim (no legado a expiração era manual).
Schedule::call(function () {
    app(ContextoEmpresa::class)->semIsolamento(fn () => ContratoFornecedor::query()->where('estado', 'ATIVO')
        ->whereNotNull('data_fim')->where('data_fim', '<', now()->toDateString())->update(['estado' => 'EXPIRADO']));
})->dailyAt('00:15')->name('compras:contratos-expirados')->withoutOverlapping();
