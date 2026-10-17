<?php

use App\Http\Controllers\Api\Gestao\CuboController;
use App\Http\Controllers\Api\Gestao\PaineisController;
use App\Services\Gestao\Paineis\ServicoPaineis;
use Illuminate\Support\Facades\Route;

// Incluído por routes/api.php dentro do grupo autenticado com empresa activa (prefixo /api).

// Painéis e Análise Dinâmica (ADR-059; o BI contabilístico foi retirado a pedido do utilizador, 17/10/2026)
Route::prefix('gestao')->name('gestao.')->group(function () {
    Route::controller(PaineisController::class)->group(function () {
        Route::get('inicio', 'inicio')->name('inicio');
        Route::get('paineis', 'lista')->name('paineis.index');
        Route::get('paineis/comparacao', 'comparacao')->name('paineis.comparacao');
        Route::get('paineis/{modulo}', 'painel')->whereIn('modulo', array_keys(ServicoPaineis::CATALOGO))->name('paineis.show');
    });
    Route::prefix('cubo')->name('cubo.')->controller(CuboController::class)->group(function () {
        Route::get('conjuntos', 'conjuntos')->name('conjuntos');
        Route::get('valores', 'valores')->name('valores');
        Route::post('consultar', 'consultar')->name('consultar');
    });
});
