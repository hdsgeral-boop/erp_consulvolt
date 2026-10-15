<?php

use App\Http\Controllers\Api\Tesouraria\CartasPagamentoController;
use Illuminate\Support\Facades\Route;

// Versão Actual (ADR-071) — rotas do agente fd_cartas; incluído por routes/api.php no grupo com empresa activa.
// Tesouraria › Cartas de Pagamento (ecrã teso_cartas): a carta nasce de um pagamento gravado e não gera lançamentos.
Route::prefix('tesouraria/cartas')->name('tesouraria.cartas.')->controller(CartasPagamentoController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('documentos', 'documentos')->name('documentos');
    Route::get('documentos/{documento}/elegibilidade', 'elegibilidade')->whereNumber('documento')->name('elegibilidade');
    Route::post('documentos/{documento}', 'emitir')->whereNumber('documento')->name('emitir');

    Route::get('modelos', 'modelos')->name('modelos.index');
    Route::post('modelos', 'criarModelo')->name('modelos.store');
    Route::put('modelos/{modelo}', 'atualizarModelo')->whereNumber('modelo')->name('modelos.update');
    Route::post('modelos/{modelo}/predefinir', 'predefinirModelo')->whereNumber('modelo')->name('modelos.predefinir');
    Route::delete('modelos/{modelo}', 'eliminarModelo')->whereNumber('modelo')->name('modelos.destroy');

    Route::get('{carta}', 'show')->whereNumber('carta')->name('show');
    Route::post('{carta}/anular', 'anular')->whereNumber('carta')->name('anular');
});
