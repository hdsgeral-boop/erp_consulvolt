<?php

use App\Http\Controllers\Api\Terceiros\ContasBancariasTerceiroController;
use Illuminate\Support\Facades\Route;

// Fase A (Versão Actual, ADR-071) — rotas do agente fa_terceiros; incluído por routes/api.php no grupo com empresa activa.
// Contas bancárias dos terceiros (ui_aux.js:920-1005). As contas por papel e o endereço estruturado gravam-se pela
// própria ficha (POST/PUT /terceiros).
Route::prefix('terceiros')->name('terceiros.')->controller(ContasBancariasTerceiroController::class)->group(function () {
    Route::get('bancos', 'bancos')->name('bancos');
    Route::get('{terceiro}/contas-bancarias', 'index')->whereNumber('terceiro')->name('contas-bancarias.index');
    Route::post('{terceiro}/contas-bancarias', 'store')->whereNumber('terceiro')->name('contas-bancarias.store');
    Route::put('{terceiro}/contas-bancarias/{conta}', 'update')->whereNumber(['terceiro', 'conta'])->name('contas-bancarias.update');
    Route::post('{terceiro}/contas-bancarias/{conta}/predefinida', 'predefinir')->whereNumber(['terceiro', 'conta'])->name('contas-bancarias.predefinir');
    Route::delete('{terceiro}/contas-bancarias/{conta}', 'destroy')->whereNumber(['terceiro', 'conta'])->name('contas-bancarias.destroy');
});
