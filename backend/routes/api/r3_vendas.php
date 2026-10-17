<?php

// Incluído por routes/api.php dentro do grupo autenticado com empresa activa (prefixo /api).
// Ronda 3 — agente r3_vendas: catálogo do POS por serviço e pesquisa de clientes nos documentos de venda.

use App\Http\Controllers\Api\POS\CatalogoServicosPOSController;
use App\Http\Controllers\Api\Vendas\PesquisaClientesController;
use Illuminate\Support\Facades\Route;

Route::prefix('pos')->name('pos.')->controller(CatalogoServicosPOSController::class)->group(function () {
    Route::get('terminais/{terminal}/catalogo', 'catalogo')->whereNumber('terminal')->name('terminais.catalogo');
    Route::get('produtos/servicos', 'marcacoes')->name('produtos.servicos');
    Route::put('produtos/{produto}/servicos', 'definir')->whereNumber('produto')->name('produtos.servicos.update');
});

Route::prefix('vendas/clientes')->name('vendas.clientes.')->controller(PesquisaClientesController::class)->group(function () {
    Route::get('pesquisa', 'pesquisar')->name('pesquisa');
    Route::post('{terceiro}/papel-cliente', 'tornarCliente')->whereNumber('terceiro')->name('papel-cliente');
});
