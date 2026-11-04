<?php

use App\Http\Controllers\Api\RH\FiRhController;
use Illuminate\Support\Facades\Route;

// Fecho Versão Actual: RH (funções, minuta, produtividade, efectividade) — ADR-071; incluído por routes/api.php no grupo com empresa activa.

// #13/#26 — elegibilidade de produtividade em massa (contratos_produtividade.js; os itens já não pertencem ao contrato)
Route::post('rh/produtividade/elegibilidade/massa', [FiRhController::class, 'elegibilidadeMassa'])->middleware('throttle:pesado')->name('rh.produtividade.elegibilidade.massa');

// #11 — métricas, bases de cálculo e explicação do cálculo da produtividade (produtividade.js:36-160)
Route::get('rh/produtividade/metricas', [FiRhController::class, 'metricas'])->name('rh.produtividade.metricas');
Route::get('rh/produtividade/explicar', [FiRhController::class, 'explicar'])->name('rh.produtividade.explicar');
Route::get('rh/produtividade/periodos/{periodo}/explicacao', [FiRhController::class, 'explicacaoPeriodo'])->whereNumber('periodo')->name('rh.produtividade.periodos.explicacao');

// #4 — modelo Excel da efectividade já preenchido (assiduidade.js:618)
Route::get('rh/assiduidade/modelo', [FiRhController::class, 'modeloEfectividade'])->middleware('throttle:pesado')->name('rh.assiduidade.modelo');
