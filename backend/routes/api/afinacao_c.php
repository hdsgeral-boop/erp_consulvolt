<?php

use App\Http\Controllers\Api\Sistema\MoedaController;
use App\Http\Controllers\Api\Vendas\RelatoriosVendasController;
use Illuminate\Support\Facades\Route;

// Incluído por routes/api.php dentro do grupo autenticado com empresa activa (prefixo /api).
// Afinação da Fase 5 — Vendas, Contabilidade, Sistema e Gestão (ADR-064).

Route::prefix('vendas')->name('vendas.')->controller(RelatoriosVendasController::class)->group(function () {
    // vendas_relatorios_view
    Route::get('relatorios/resumo', 'resumo')->name('relatorios.resumo');
    // vendas_relatorios_view | vendas_fe_config — validação prévia do SAF-T, sem gerar o ficheiro
    Route::get('saft/validar', 'validarSaft')->name('saft.validar');
});

Route::prefix('sistema')->name('sistema.')->controller(MoedaController::class)->group(function () {
    // config_moedas_view | config_moedas_gerir
    Route::get('moedas/funcional', 'obterFuncional')->name('moedas.funcional.obter');
});
