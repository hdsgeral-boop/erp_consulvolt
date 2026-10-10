<?php

// Incluído por routes/api/paridade_pos_stock.php (grupo autenticado com empresa activa, prefixo /api).
// Frente de caixa e Terminais POS — paridade com o legado (verificacao_visual/RELATORIO.md 3.1.5, 3.1.9, 3.3.2, 3.3.5).
// As imagens dos produtos vêm de GET /api/vendas/produtos/imagens (módulo de Vendas).

use App\Http\Controllers\Api\POS\FrentePOSController;
use Illuminate\Support\Facades\Route;

Route::prefix('pos/frente')->name('paridade.pos.frente.')->controller(FrentePOSController::class)->group(function () {
    Route::get('terminais/{terminal}/stock', 'stock')->whereNumber('terminal')->name('stock');
    Route::get('sessoes-antigas', 'sessoesAntigas')->name('sessoes_antigas');
    Route::get('series', 'series')->name('series');
});
