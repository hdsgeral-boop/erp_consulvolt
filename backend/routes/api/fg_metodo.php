<?php

use App\Http\Controllers\Api\Metodo\AssuntosMetodoController;
use Illuminate\Support\Facades\Route;

// Versão Actual (ADR-071) — rotas do agente fg_metodo; incluído por routes/api.php no grupo com empresa activa.
// Fase G: Método de Trabalho — Registo de Assunto 5W1H (js/modules/metodo): registo, deliberação pelo nível acima
// no organigrama e integrações (pedido de compra / projecto).

Route::prefix('metodo/assuntos')->name('metodo.assuntos.')->controller(AssuntosMetodoController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('opcoes', 'opcoes')->name('opcoes');
    Route::get('aprovador', 'aprovador')->name('aprovador');
    Route::get('artigos', 'artigos')->name('artigos');
    Route::get('encomendas', 'encomendas')->name('encomendas');
    Route::get('projetos-disponiveis', 'projetosDisponiveis')->name('projetos');
    Route::post('/', 'store')->name('store');
    Route::get('{assunto}', 'show')->whereNumber('assunto')->name('show');
    Route::put('{assunto}', 'update')->whereNumber('assunto')->name('update');
    Route::get('{assunto}/historico', 'historico')->whereNumber('assunto')->name('historico');
    Route::post('{assunto}/submeter', 'submeter')->whereNumber('assunto')->name('submeter');
    Route::post('{assunto}/decidir', 'decidir')->whereNumber('assunto')->name('decidir');
    Route::post('{assunto}/estado', 'estado')->whereNumber('assunto')->name('estado');
});
