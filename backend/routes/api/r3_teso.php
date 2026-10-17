<?php

use App\Http\Controllers\Api\Tesouraria\ImportacaoCaixaController;
use Illuminate\Support\Facades\Route;

// Ronda 3 (r3_teso) — incluído por routes/api.php no grupo autenticado com empresa activa (prefixo /api).
// Folha de Caixa: modelo e importação de movimentos para a sessão aberta (autorização por permissão no controlador).
Route::prefix('tesouraria/caixa')->name('tesouraria.caixa.')->controller(ImportacaoCaixaController::class)->group(function () {
    Route::get('modelo-importacao', 'modelo')->name('modelo_importacao');
    Route::post('sessoes/{id}/importar', 'importar')->whereNumber('id')->middleware('throttle:pesado')->name('importar');
});
