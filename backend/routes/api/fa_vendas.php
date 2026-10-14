<?php

use App\Http\Controllers\Api\Vendas\RecebimentosFaturaController;
use App\Http\Controllers\Api\Vendas\SeriesVendasController;
use Illuminate\Support\Facades\Route;

// Fase A (Versão Actual, ADR-071) — rotas do agente fa_vendas; incluído por routes/api.php no grupo com empresa activa.
// Autorização por permissão em cada método do controlador (exigir antes de validar).

Route::prefix('vendas')->name('vendas.fa.')->group(function () {
    // Vendas › Séries de numeração (js/vendas_series.js): número inicial, uma activa por tipo+ano+origem, fecha-se em vez de apagar
    Route::get('series', [SeriesVendasController::class, 'index'])->name('series.index');
    Route::post('series', [SeriesVendasController::class, 'store'])->name('series.store');
    Route::put('series/{serie}', [SeriesVendasController::class, 'update'])->whereNumber('serie')->name('series.update');
    Route::delete('series/{serie}', [SeriesVendasController::class, 'destroy'])->whereNumber('serie')->name('series.destroy');

    // Factura paga: cancelar os recibos e os recebimentos de tesouraria antes da nota de crédito (anulacao_documentos.js)
    Route::get('documentos/{venda}/recebimentos', [RecebimentosFaturaController::class, 'show'])->whereNumber('venda')->name('documentos.recebimentos');
    Route::post('documentos/{venda}/cancelar-recebimentos', [RecebimentosFaturaController::class, 'cancelar'])->whereNumber('venda')->name('documentos.cancelar_recebimentos');
});
