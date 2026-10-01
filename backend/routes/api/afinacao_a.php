<?php

use App\Http\Controllers\Api\Logistica\StockController;
use App\Http\Controllers\Api\Tesouraria\MapasTesourariaController;
use App\Http\Controllers\Api\Tesouraria\TesourariaController;
use Illuminate\Support\Facades\Route;

// Incluído por routes/api.php dentro do grupo autenticado com empresa activa (prefixo /api).
// Afinação da Fase 5 — Compras, Tesouraria e Armazém (ADR-064).

Route::prefix('tesouraria')->name('tesouraria.')->group(function () {
    Route::get('disponibilidades', [MapasTesourariaController::class, 'disponibilidades'])->name('disponibilidades');
    Route::get('extrato-conta', [MapasTesourariaController::class, 'extratoConta'])->name('extrato-conta');
    Route::post('documentos/integrar', [TesourariaController::class, 'integrarLote'])->name('documentos.integrar-lote');
});

Route::prefix('logistica')->name('logistica.')->group(function () {
    Route::post('stock/recalcular-valorizacoes', [StockController::class, 'recalcularValorizacoes'])->name('stock.recalcular-valorizacoes');
});
