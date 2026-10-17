<?php

// Incluído por routes/api.php dentro do grupo autenticado com empresa activa (prefixo /api).
// Ronda 3 — Contabilidade (r3_contab): quebra dos lançamentos por terceiro/unidade de negócio/centro de custo, cálculo
// detalhado das rubricas das demonstrações e modelo/importação da actualização em massa. Autorização nos controladores.

use App\Http\Controllers\Api\Contabilidade\ComposicaoDemonstracoesController;
use App\Http\Controllers\Api\Contabilidade\LancamentoController;
use App\Http\Controllers\Api\Contabilidade\MapasPorEmpresaController;
use App\Http\Controllers\Api\Contabilidade\RotinasController;
use Illuminate\Support\Facades\Route;

Route::prefix('contabilidade')->name('contabilidade.')->group(function () {
    Route::get('lancamentos/quebra', [LancamentoController::class, 'quebra'])->middleware('throttle:pesado')->name('lancamentos.quebra');
    Route::get('relatorios/composicao/{tipo}', [ComposicaoDemonstracoesController::class, 'show'])->whereIn('tipo', ['balanco', 'dr', 'fluxo'])->name('relatorios.composicao');
    Route::get('relatorios/por-empresa/{mapa}', [MapasPorEmpresaController::class, 'show'])->whereIn('mapa', ['balancete', 'balanco', 'dr', 'fluxo'])->middleware('throttle:pesado')->name('relatorios.por-empresa');
    Route::get('rotinas/actualizacao-massa/modelo', [RotinasController::class, 'modeloActualizacao'])->name('rotinas.actualizacao.modelo');
    Route::post('rotinas/actualizacao-massa/importar', [RotinasController::class, 'importarActualizacao'])->middleware('throttle:pesado')->name('rotinas.actualizacao.importar');
});
