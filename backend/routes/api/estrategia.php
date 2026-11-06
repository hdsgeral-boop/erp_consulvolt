<?php

// Incluído por routes/api.php dentro do grupo autenticado com empresa activa (prefixo /api).
// Planeamento Estratégico, Onda 1 (F0 + F1 + F2a): planos, perspectivas, objectivos, indicadores, metas e medição manual.
// Cada acção exige a sua permissão antes de validar (ver os controladores e os pedidos em Http/Requests/Estrategia).

use App\Http\Controllers\Api\Estrategia\EstruturaEstrategicaController;
use App\Http\Controllers\Api\Estrategia\IndicadoresEstrategicosController;
use App\Http\Controllers\Api\Estrategia\PlanosEstrategicosController;
use Illuminate\Support\Facades\Route;

Route::prefix('estrategia')->name('estrategia.')->group(function () {
    Route::get('catalogos', [IndicadoresEstrategicosController::class, 'catalogos'])->name('catalogos');

    Route::controller(PlanosEstrategicosController::class)->prefix('planos')->name('planos.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::get('{plano}', 'show')->whereNumber('plano')->name('show');
        Route::put('{plano}', 'update')->whereNumber('plano')->name('update');
        Route::delete('{plano}', 'destroy')->whereNumber('plano')->name('destroy');
        Route::post('{plano}/aprovar', 'aprovar')->whereNumber('plano')->name('aprovar');
        Route::post('{plano}/iniciar-execucao', 'iniciarExecucao')->whereNumber('plano')->name('iniciar_execucao');
        Route::post('{plano}/reabrir', 'reabrir')->whereNumber('plano')->name('reabrir');
        Route::post('{plano}/encerrar', 'encerrar')->whereNumber('plano')->name('encerrar');
    });

    Route::controller(EstruturaEstrategicaController::class)->prefix('planos/{plano}')->whereNumber('plano')->name('estrutura.')->group(function () {
        Route::post('perspectivas', 'criarPerspectiva')->name('perspectivas.store');
        Route::put('perspectivas/{id}', 'actualizarPerspectiva')->whereNumber('id')->name('perspectivas.update');
        Route::delete('perspectivas/{id}', 'apagarPerspectiva')->whereNumber('id')->name('perspectivas.destroy');
        Route::post('objectivos', 'criarObjectivo')->name('objectivos.store');
        Route::put('objectivos/{id}', 'actualizarObjectivo')->whereNumber('id')->name('objectivos.update');
        Route::delete('objectivos/{id}', 'apagarObjectivo')->whereNumber('id')->name('objectivos.destroy');
    });

    Route::controller(IndicadoresEstrategicosController::class)->group(function () {
        Route::get('planos/{plano}/indicadores', 'index')->whereNumber('plano')->name('indicadores.index');
        Route::post('planos/{plano}/indicadores', 'store')->whereNumber('plano')->name('indicadores.store');
        Route::put('planos/{plano}/indicadores/{indicador}', 'update')->whereNumber(['plano', 'indicador'])->name('indicadores.update');
        Route::delete('planos/{plano}/indicadores/{indicador}', 'destroy')->whereNumber(['plano', 'indicador'])->name('indicadores.destroy');
        Route::post('indicadores/{indicador}/testar', 'testar')->whereNumber('indicador')->name('indicadores.testar');
        Route::get('indicadores/{indicador}/metas', 'metas')->whereNumber('indicador')->name('indicadores.metas');
        Route::put('indicadores/{indicador}/metas', 'gravarMetas')->whereNumber('indicador')->name('indicadores.metas.update');
        Route::get('indicadores/{indicador}/serie', 'serie')->whereNumber('indicador')->name('indicadores.serie');
        Route::post('indicadores/{indicador}/medicoes', 'entregar')->whereNumber('indicador')->name('indicadores.medicoes.store');
    });
});
