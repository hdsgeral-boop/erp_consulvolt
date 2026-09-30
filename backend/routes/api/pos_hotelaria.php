<?php

// Incluído por routes/api.php dentro do grupo autenticado com empresa activa (prefixo /api).
// Hotelaria e POS de armazém (ADR-050).

use App\Http\Controllers\Api\POS\HotelariaController;
use App\Http\Controllers\Api\POS\POSArmazemController;
use Illuminate\Support\Facades\Route;

Route::prefix('pos/hotelaria')->name('pos.hotelaria.')->controller(HotelariaController::class)->group(function () {
    Route::get('quartos', 'quartos')->name('quartos.index');
    Route::put('quartos/{produto}', 'guardarQuarto')->whereNumber('produto')->name('quartos.update');
    Route::get('estadias', 'lista')->name('estadias.index');
    Route::get('estadias/{estadia}', 'estadia')->whereNumber('estadia')->name('estadias.show');
    Route::put('estadias/{estadia}', 'alterar')->whereNumber('estadia')->name('estadias.update');
    Route::put('estadias/{estadia}/consumos', 'consumos')->whereNumber('estadia')->name('estadias.consumos');
    Route::post('estadias/{estadia}/anular', 'anular')->whereNumber('estadia')->name('estadias.anular');
    Route::post('sessoes/{sessao}/checkin', 'checkin')->whereNumber('sessao')->name('checkin');
    Route::post('sessoes/{sessao}/checkout/simular', 'simular')->whereNumber('sessao')->name('checkout.simular');
    Route::post('sessoes/{sessao}/checkout', 'fazerCheckout')->whereNumber('sessao')->name('checkout');
});

Route::prefix('pos/armazem')->name('pos.armazem.')->controller(POSArmazemController::class)->group(function () {
    Route::get('stock', 'stock')->name('stock');
    Route::get('vendas', 'vendas')->name('vendas.index');
    Route::post('vendas', 'vender')->name('vendas.store');
    Route::get('vendas/{guia}', 'venda')->whereNumber('guia')->name('vendas.show');
    Route::get('picking', 'fila')->name('picking.index');
    Route::get('picking/{encomenda}', 'lista')->whereNumber('encomenda')->name('picking.show');
    Route::post('picking/{encomenda}/expedir', 'expedir')->whereNumber('encomenda')->name('picking.expedir');
});
