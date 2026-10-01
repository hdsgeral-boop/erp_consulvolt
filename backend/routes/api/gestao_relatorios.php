<?php

use App\Http\Controllers\Api\Gestao\FluxosController;
use App\Http\Controllers\Api\Gestao\RelatoriosGestaoController;
use Illuminate\Support\Facades\Route;

// Incluído por routes/api.php dentro do grupo autenticado com empresa activa (prefixo /api).
// Relatórios de gestão (comparação de períodos) e Fluxo de Processos (ADR-060)
Route::prefix('gestao')->name('gestao.')->group(function () {
    Route::prefix('relatorios')->name('relatorios.')->controller(RelatoriosGestaoController::class)->group(function () {
        Route::get('/', 'catalogo')->name('catalogo');
        Route::get('periodos', 'periodos')->name('periodos');
        Route::get('resumo', 'resumo')->name('resumo');
        Route::get('todos', 'todos')->name('todos');
        Route::get('{modulo}', 'modulo')->where('modulo', '[a-z_]+')->name('modulo');
    });
    Route::prefix('fluxos')->name('fluxos.')->controller(FluxosController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('{fluxo}', 'mostrar')->where('fluxo', '[a-z_]+')->name('mostrar');
        Route::get('{fluxo}/processos', 'processos')->where('fluxo', '[a-z_]+')->name('processos');
        Route::get('{fluxo}/processos/{chave}', 'processo')->where('fluxo', '[a-z_]+')->where('chave', '[A-Za-z0-9_.:\-]+')->name('processo');
    });
});
