<?php

// Incluído por routes/api.php dentro do grupo autenticado com empresa activa (prefixo /api).

use App\Http\Controllers\Api\POS\PrestacaoContasController;
use Illuminate\Support\Facades\Route;

// POS parte 2 (ADR-048): prestação de contas e relatórios
Route::prefix('pos')->name('pos.')->controller(PrestacaoContasController::class)->group(function () {
    Route::get('prestacao', 'pendentes')->name('prestacao.index');
    Route::get('sessoes/{sessao}/prestacao', 'itens')->whereNumber('sessao')->name('prestacao.sessao');
    Route::post('sessoes/{sessao}/prestacao', 'registar')->whereNumber('sessao')->name('prestacao.registar');
    Route::post('sessoes/{sessao}/prestacao/transferencias', 'registarTransferencias')->whereNumber('sessao')->name('prestacao.transferencias');
    Route::get('liquidacoes', 'liquidacoes')->name('liquidacoes.index');
    Route::get('liquidacoes/{liquidacao}', 'liquidacao')->whereNumber('liquidacao')->name('liquidacoes.show');
    Route::post('liquidacoes/{liquidacao}/anular', 'anular')->whereNumber('liquidacao')->name('liquidacoes.anular');
    Route::get('relatorios', 'relatorios')->name('relatorios.index');
    Route::get('relatorios/servicos', 'relatorioServicos')->name('relatorios.servicos');
});
