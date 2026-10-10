<?php

// Incluído por routes/api/paridade_pos_stock.php (grupo autenticado com empresa activa, prefixo /api).
// Logística — leituras de paridade com o sistema anterior (RELATORIO.md 2.2, 2.4 e 2.5).

use App\Http\Controllers\Api\Logistica\ParidadeStockController;
use Illuminate\Support\Facades\Route;

Route::prefix('logistica/paridade')->name('paridade.stock.')->controller(ParidadeStockController::class)->group(function () {
    Route::get('rececoes', 'rececoes')->name('rececoes');
    Route::get('movimentos', 'movimentos')->name('movimentos');
    Route::get('armazens/valor', 'valorArmazens')->name('armazens.valor');
});
