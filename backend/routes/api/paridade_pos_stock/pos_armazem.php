<?php

// Incluído por routes/api/paridade_pos_stock.php (grupo autenticado com empresa activa, prefixo /api).
// POS de armazém — paridade com o legado (RELATORIO 2.6): artigos em cartões, acerto de stock e senhas.

use App\Http\Controllers\Api\POS\ParidadePOSArmazemController;
use Illuminate\Support\Facades\Route;

Route::prefix('pos/armazem')->name('paridade.pos_armazem.')->controller(ParidadePOSArmazemController::class)->group(function () {
    Route::get('artigos', 'artigos')->name('artigos');
    Route::get('acerto-stock', 'diferencas')->name('acerto.index');
    Route::post('acerto-stock', 'acertar')->name('acerto.aplicar');
    Route::get('senhas', 'senhas')->name('senhas.index');
    Route::post('senhas', 'gerarSenha')->name('senhas.store');
    Route::post('senhas/chamar', 'chamarSenha')->name('senhas.chamar');
    Route::post('senhas/{senha}/repetir', 'repetirSenha')->whereNumber('senha')->name('senhas.repetir');
});
