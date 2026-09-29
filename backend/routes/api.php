<?php

use App\Http\Controllers\Api\Autenticacao\AutenticacaoController;
use App\Http\Controllers\Api\SaudeController;
use App\Http\Controllers\Api\Sistema\EmpresaController;
use App\Http\Controllers\Api\Sistema\LogAuditoriaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API REST — ERP Consulvolt (prefixo /api)
|--------------------------------------------------------------------------
| Convenção (directiva, secção 4):
|   GET    /api/{modulo}/{recurso}            listagem paginada com filtros
|   POST   /api/{modulo}/{recurso}            criação (FormRequest)
|   GET    /api/{modulo}/{recurso}/{id}       detalhe
|   PUT    /api/{modulo}/{recurso}/{id}       actualização
|   DELETE /api/{modulo}/{recurso}/{id}       remoção lógica / estorno
|   POST   /api/{modulo}/{recurso}/{id}/acao  operação de negócio (anular, contabilizar, liquidar…)
|
| Middleware: auth:sanctum (token Bearer) · empresa (cabeçalho X-Empresa-Id obrigatório).
*/

Route::get('saude', SaudeController::class)->name('saude');

Route::prefix('autenticacao')->name('autenticacao.')->group(function () {
    Route::post('entrar', [AutenticacaoController::class, 'entrar'])->middleware('throttle:entrar')->name('entrar');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('sair', [AutenticacaoController::class, 'sair'])->name('sair');
        Route::get('eu', [AutenticacaoController::class, 'eu'])->name('eu');
    });
});

Route::middleware('auth:sanctum')->group(function () {

    // Rotas sem empresa activa (antes de escolher empresa)
    Route::prefix('sistema')->name('sistema.')->group(function () {
        Route::get('empresas', [EmpresaController::class, 'index'])->name('empresas.index');
        Route::get('empresas/{empresa}', [EmpresaController::class, 'show'])->whereNumber('empresa')->name('empresas.show');
    });

    // Rotas com empresa activa (X-Empresa-Id)
    Route::middleware('empresa')->group(function () {
        Route::prefix('sistema')->name('sistema.')->group(function () {
            Route::get('logs', [LogAuditoriaController::class, 'index'])->name('logs.index');
        });
    });
});
