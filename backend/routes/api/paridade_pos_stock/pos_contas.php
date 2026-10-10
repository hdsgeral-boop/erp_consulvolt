<?php

// Incluído por routes/api/paridade_pos_stock.php (grupo autenticado com empresa activa, prefixo /api).
// POS › Integração contabilística como no sistema anterior (RELATORIO.md 3.5): pré-visualização (nada é gravado) e
// integração de várias sessões seleccionadas.

use App\Http\Controllers\Api\POS\ParidadeContasPOSController;
use Illuminate\Support\Facades\Route;

Route::prefix('pos/integracao')->name('paridade.pos.integracao.')->controller(ParidadeContasPOSController::class)->group(function () {
    Route::post('pre-visualizacao', 'previsualizar')->name('previsualizar');
    Route::post('contabilizar', 'contabilizar')->name('contabilizar');
});
