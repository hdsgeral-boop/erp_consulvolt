<?php

// Incluído por routes/api.php dentro do grupo autenticado com empresa activa (prefixo /api).
// Gestão Imobiliária, Onda 1 (F0-F2): cadastro (imóveis, fracções, mapa de ocupação), modelos de documentos e taxas por ano.
// As ondas seguintes acrescentam o seu próprio ficheiro (imobiliario_<parte>.php).

use App\Http\Controllers\Api\Imobiliario\FracoesController;
use App\Http\Controllers\Api\Imobiliario\ImoveisController;
use App\Http\Controllers\Api\Imobiliario\ModelosTaxasController;
use Illuminate\Support\Facades\Route;

Route::prefix('imobiliario')->name('imobiliario.')->group(function () {
    Route::controller(ImoveisController::class)->group(function () {
        Route::get('listas', 'listas')->name('listas');
        Route::get('resumo', 'resumo')->name('resumo');
        Route::get('edificios', 'edificios')->name('edificios');
        Route::get('terceiros', 'terceiros')->name('terceiros');
        Route::get('imoveis', 'index')->name('imoveis.index');
        Route::post('imoveis', 'store')->name('imoveis.store');
        Route::get('imoveis/{imovel}', 'show')->whereNumber('imovel')->name('imoveis.show');
        Route::put('imoveis/{imovel}', 'update')->whereNumber('imovel')->name('imoveis.update');
        Route::delete('imoveis/{imovel}', 'destroy')->whereNumber('imovel')->name('imoveis.destroy');
    });

    Route::controller(FracoesController::class)->group(function () {
        Route::get('imoveis/{imovel}/fracoes', 'index')->whereNumber('imovel')->name('fracoes.index');
        Route::post('imoveis/{imovel}/fracoes', 'store')->whereNumber('imovel')->name('fracoes.store');
        Route::post('imoveis/{imovel}/fracoes/gerar', 'gerar')->whereNumber('imovel')->name('fracoes.gerar');
        Route::post('imoveis/{imovel}/fracoes/unica', 'unica')->whereNumber('imovel')->name('fracoes.unica');
        Route::post('imoveis/{imovel}/fracoes/importar-portas', 'importarPortas')->whereNumber('imovel')->name('fracoes.importar_portas');
        Route::get('fracoes/{fracao}', 'show')->whereNumber('fracao')->name('fracoes.show');
        Route::put('fracoes/{fracao}', 'update')->whereNumber('fracao')->name('fracoes.update');
        Route::delete('fracoes/{fracao}', 'destroy')->whereNumber('fracao')->name('fracoes.destroy');
        Route::post('fracoes/{fracao}/estado', 'estado')->whereNumber('fracao')->name('fracoes.estado');
    });

    Route::controller(ModelosTaxasController::class)->group(function () {
        Route::get('modelos', 'index')->name('modelos.index');
        Route::get('modelos/catalogo', 'catalogo')->name('modelos.catalogo');
        Route::post('modelos', 'store')->name('modelos.store');
        Route::post('modelos/importar-padrao', 'importarPadrao')->name('modelos.importar_padrao');
        Route::post('modelos/pre-visualizar', 'preVisualizar')->name('modelos.pre_visualizar');
        Route::put('modelos/{modelo}', 'update')->whereNumber('modelo')->name('modelos.update');
        Route::delete('modelos/{modelo}', 'destroy')->whereNumber('modelo')->name('modelos.destroy');
        Route::get('taxas', 'taxas')->name('taxas.show');
        Route::put('taxas/{ano}', 'gravarTaxas')->whereNumber('ano')->name('taxas.update');
        Route::post('taxas/{ano}/validar', 'validarTaxas')->whereNumber('ano')->name('taxas.validar');
    });
});
