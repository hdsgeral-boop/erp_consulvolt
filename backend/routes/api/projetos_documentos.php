<?php

use App\Http\Controllers\Api\Projetos\DocumentoProjetoController;
use App\Http\Controllers\Api\Projetos\FinanceiroProjetoController;
use Illuminate\Support\Facades\Route;

// Documentos do projecto (PDF, versões, tipos por empresa); incluído por routes/api.php no grupo com empresa activa.
Route::controller(DocumentoProjetoController::class)->group(function () {
    Route::get('projetos/documentos/tipos', 'tipos')->name('projetos.documentos.tipos');
    Route::post('projetos/documentos/tipos', 'guardarTipo')->name('projetos.documentos.tipos.store');
    Route::put('projetos/documentos/tipos/{tipo}', 'guardarTipo')->whereNumber('tipo')->name('projetos.documentos.tipos.update');
    Route::delete('projetos/documentos/tipos/{tipo}', 'eliminarTipo')->whereNumber('tipo')->name('projetos.documentos.tipos.destroy');

    Route::prefix('projetos/{projeto}/documentos')->whereNumber('projeto')->name('projetos.documentos.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::get('{documento}/versoes', 'versoes')->whereNumber('documento')->name('versoes');
        Route::post('{documento}/versoes', 'novaVersao')->whereNumber('documento')->name('versoes.store');
        Route::put('{documento}', 'update')->whereNumber('documento')->name('update');
        Route::get('{documento}/ficheiro', 'descarregar')->whereNumber('documento')->name('ficheiro');
        Route::delete('{documento}', 'destroy')->whereNumber('documento')->name('destroy');
    });
});

// Financeiro do projecto (indicadores, valor acrescentado, plano de facturação)
Route::prefix('projetos/{projeto}/financeiro')->whereNumber('projeto')->name('projetos.financeiro.')->controller(FinanceiroProjetoController::class)->group(function () {
    Route::get('/', 'painel')->name('painel');
    Route::post('plano', 'guardar')->name('plano.store');
    Route::put('plano/{linha}', 'guardar')->whereNumber('linha')->name('plano.update');
    Route::post('plano/{linha}/cancelar', 'cancelar')->whereNumber('linha')->name('plano.cancelar');
    Route::post('plano/{linha}/factura', 'associar')->whereNumber('linha')->name('plano.associar');
    Route::delete('plano/{linha}/factura', 'desassociar')->whereNumber('linha')->name('plano.desassociar');
});
