<?php

use App\Http\Controllers\Api\Logistica\CategoriaProdutoController;
use App\Http\Controllers\Api\Logistica\NiveisStockController;
use Illuminate\Support\Facades\Route;

// Incluído por routes/api.php dentro do grupo autenticado com empresa activa (prefixo /api).
// Ronda 3 — produtos e categorias: contas na categoria, níveis de stock e sugestões de reposição.

Route::prefix('logistica')->name('logistica.')->group(function () {
    Route::get('categorias-produtos/{categoria}', [CategoriaProdutoController::class, 'show'])->whereNumber('categoria')->name('categorias.show');
    Route::get('niveis-stock', [NiveisStockController::class, 'index'])->name('niveis_stock.index');
    Route::post('niveis-stock/pedido-compra', [NiveisStockController::class, 'gerarPedidoCompra'])->name('niveis_stock.pedido_compra');
});
