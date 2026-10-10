<?php

use App\Http\Controllers\Api\Vendas\ImagemProdutoController;
use App\Http\Controllers\Api\Vendas\ParidadeVendasController;
use Illuminate\Support\Facades\Route;

// Incluído por routes/api.php dentro do grupo autenticado com empresa activa (prefixo /api).
// Paridade visual e funcional de Vendas (secção 4) e Compras (secção 5) com o sistema anterior
// (verificacao_visual/RELATORIO.md, 2026-10-08). Autorização por permissão em cada método do controlador.

Route::prefix('vendas')->name('vendas.paridade.')->group(function () {
    Route::controller(ParidadeVendasController::class)->group(function () {
        Route::get('cabecalho', 'cabecalho')->name('cabecalho');                                         // 4.0.2
        Route::get('documentos/contagens', 'contagens')->name('documentos.contagens');                   // 4.8.4
        Route::post('documentos/{venda}/recalcular-estado', 'recalcularEstado')->whereNumber('venda')->name('documentos.recalcular_estado');   // 4.2.5
        Route::post('documentos/{venda}/pedido-compra', 'pedidoCompra')->whereNumber('venda')->name('documentos.pedido_compra');              // 4.8.3
        Route::get('relatorios/alertas-stock', 'alertasStock')->name('relatorios.alertas_stock');       // 4.3.7
        Route::get('saft/analise', 'analiseSaft')->name('saft.analise');                                // 4.6.1
        Route::get('faturacao-eletronica/diagnostico', 'diagnosticoAgt')->name('fe.diagnostico');               // 4.7.1
        Route::get('meios-pagamento', 'meiosPagamento')->name('meios_pagamento');                       // 4.2.12
        Route::get('guias-saida-pendentes', 'guiasPendentes')->name('guias_saida_pendentes');           // 4.2.9
        Route::get('produtos/stock', 'stockProdutos')->name('produtos.stock');                          // 4.1.3
        Route::post('produtos/editar-em-massa', 'editarProdutosEmMassa')->name('produtos.editar_massa'); // 4.1.2
    });
    // 4.1.5 — imagem do produto (também usada pelo POS)
    Route::controller(ImagemProdutoController::class)->group(function () {
        Route::get('produtos/imagens', 'index')->name('produtos.imagens');
        Route::get('produtos/{produto}/imagem', 'show')->whereNumber('produto')->name('produtos.imagem');
        Route::post('produtos/{produto}/imagem', 'store')->whereNumber('produto')->middleware('throttle:pesado')->name('produtos.imagem.guardar');
        Route::delete('produtos/{produto}/imagem', 'destroy')->whereNumber('produto')->name('produtos.imagem.remover');
    });
});

// Compras e terceiros (ficheiro próprio)
if (is_file(__DIR__.'/paridade_compras.php')) {
    require __DIR__.'/paridade_compras.php';
}
