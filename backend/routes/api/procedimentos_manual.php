<?php

// Incluído por routes/api.php dentro do grupo autenticado com empresa activa (prefixo /api).
// Manual de Procedimentos, Onda 1 (ecrã proc_manual): processos (fábrica + edições + próprios) e definições do documento.

use App\Http\Controllers\Api\Procedimentos\ProcedimentosController;
use Illuminate\Support\Facades\Route;

Route::prefix('procedimentos')->name('procedimentos.')->controller(ProcedimentosController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('cobertura', 'cobertura')->name('cobertura');
    Route::post('/', 'store')->name('store');
    Route::put('{chave}', 'update')->where('chave', '[a-z0-9_]{1,40}')->name('update');
    Route::delete('{chave}', 'destroy')->where('chave', '[a-z0-9_]{1,40}')->name('destroy');
    Route::post('{chave}/ocultar', 'ocultar')->where('chave', '[a-z0-9_]{1,40}')->name('ocultar');
    Route::post('{chave}/mostrar', 'mostrar')->where('chave', '[a-z0-9_]{1,40}')->name('mostrar');
});

Route::prefix('manuais/procedimentos')->name('manuais.procedimentos.')->controller(ProcedimentosController::class)->group(function () {
    Route::get('/', 'definicoes')->name('show');
    Route::put('/', 'guardarDefinicoes')->name('update');
    Route::post('recolha', 'recolha')->middleware('throttle:pesado')->name('recolha');
});
