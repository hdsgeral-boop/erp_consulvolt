<?php

use App\Http\Controllers\Api\Estrutura\ParidadeEstruturaController;
use Illuminate\Support\Facades\Route;

// Estrutura orgânica — paridade com o sistema anterior (verificação visual de 2026-10-08, secção 10).
// Incluído por routes/api/paridade_geral_activos.php (grupo autenticado com empresa activa, prefixo /api).
Route::prefix('estrutura')->name('estrutura.paridade.')->controller(ParidadeEstruturaController::class)->group(function () {
    Route::get('salarios', 'salarios')->name('salarios');            // est_ver_salarios
    Route::post('disposicao', 'disposicao')->name('disposicao');     // est_editar
});
