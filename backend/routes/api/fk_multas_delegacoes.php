<?php

use App\Http\Controllers\Api\Estrutura\DelegacoesChefiaController;
use App\Http\Controllers\Api\Estrutura\MultasEntregaveisController;
use Illuminate\Support\Facades\Route;

// Fase K (ADR-079) — multas de entregáveis (decisão humana, tecto opcional, lançamento na folha) e delegação da chefia
// (portal, Método de Trabalho e entregáveis). Incluído por routes/api.php no grupo com empresa activa (prefixo /api).

Route::prefix('rh/estrutura/multas')->name('rh.estrutura.multas.')->controller(MultasEntregaveisController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('propor', 'propor')->name('propor');
    Route::post('{multa}/aplicar', 'aplicar')->whereNumber('multa')->name('aplicar');
    Route::post('{multa}/dispensar', 'dispensar')->whereNumber('multa')->name('dispensar');
    Route::post('{multa}/repor', 'repor')->whereNumber('multa')->name('repor');
});

// «Importar Multas» no cálculo da folha (junto de Importar Produtividade / Importar Empréstimos)
Route::prefix('rh/salarios/periodos')->name('rh.salarios.multas.')->controller(MultasEntregaveisController::class)->group(function () {
    Route::post('{id}/importar-multas-entregaveis', 'importar')->whereNumber('id')->name('importar');
    Route::post('{id}/anular-multas-entregaveis', 'anular')->whereNumber('id')->name('anular');
});

Route::prefix('rh/estrutura/delegacoes')->name('rh.estrutura.delegacoes.')->controller(DelegacoesChefiaController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/', 'store')->name('store');
    Route::post('{delegacao}/revogar', 'revogar')->whereNumber('delegacao')->name('revogar');
});

// O portal usa o prefixo `rh.portal.` da lista branca do subdomínio portal.<base> (ExigirAreaPortal): nada a acrescentar lá.
Route::prefix('rh/portal/delegacoes')->name('rh.portal.delegacoes.')->controller(DelegacoesChefiaController::class)->group(function () {
    Route::get('/', 'doPortal')->name('index');
    Route::get('candidatos', 'candidatos')->name('candidatos');
    Route::post('/', 'storePortal')->name('store');
    Route::post('{delegacao}/revogar', 'revogarPortal')->whereNumber('delegacao')->name('revogar');
});
