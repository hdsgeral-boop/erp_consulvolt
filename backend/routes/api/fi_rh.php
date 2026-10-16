<?php

use App\Http\Controllers\Api\RH\FiRhController;
use Illuminate\Support\Facades\Route;

// Fecho Versão Actual: RH (funções, minuta, produtividade, efectividade) — ADR-071; incluído por routes/api.php no grupo com empresa activa.

// #13/#26 — itens de produtividade em massa nos contratos (contratos_produtividade.js)
Route::post('rh/contratos/produtividade-massa', [FiRhController::class, 'produtividadeMassa'])->middleware('throttle:pesado')->name('rh.contratos.produtividade-massa');

// #11 — métricas, bases de cálculo e explicação do cálculo da produtividade (produtividade.js:36-160)
Route::get('rh/produtividade/metricas', [FiRhController::class, 'metricas'])->name('rh.produtividade.metricas');
Route::get('rh/produtividade/explicar', [FiRhController::class, 'explicar'])->name('rh.produtividade.explicar');
Route::get('rh/produtividade/periodos/{periodo}/explicacao', [FiRhController::class, 'explicacaoPeriodo'])->whereNumber('periodo')->name('rh.produtividade.periodos.explicacao');

// #4 — modelo Excel da efectividade já preenchido (assiduidade.js:618)
Route::get('rh/assiduidade/modelo', [FiRhController::class, 'modeloEfectividade'])->middleware('throttle:pesado')->name('rh.assiduidade.modelo');
