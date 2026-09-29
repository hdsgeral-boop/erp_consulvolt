<?php

use App\Http\Controllers\Api\Autenticacao\AutenticacaoController;
use App\Http\Controllers\Api\Contabilidade\DiarioController;
use App\Http\Controllers\Api\Contabilidade\LancamentoController;
use App\Http\Controllers\Api\Contabilidade\PlanoContasController;
use App\Http\Controllers\Api\Contabilidade\RelatorioContabilController;
use App\Http\Controllers\Api\SaudeController;
use App\Http\Controllers\Api\Sistema\EmpresaController;
use App\Http\Controllers\Api\Sistema\LogAuditoriaController;
use App\Http\Controllers\Api\Sistema\ValidacaoDadosController;
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
            Route::get('validacoes', [ValidacaoDadosController::class, 'index'])->name('validacoes.index');
            Route::get('validacoes/{codigo}', [ValidacaoDadosController::class, 'show'])->where('codigo', '[a-z_]+')->name('validacoes.show');
        });

        Route::prefix('contabilidade')->name('contabilidade.')->group(function () {
            Route::get('plano-contas', [PlanoContasController::class, 'index'])->name('plano-contas.index');
            Route::post('plano-contas', [PlanoContasController::class, 'store'])->name('plano-contas.store');
            Route::put('plano-contas/{conta}', [PlanoContasController::class, 'update'])->whereNumber('conta')->name('plano-contas.update');
            Route::delete('plano-contas/{conta}', [PlanoContasController::class, 'destroy'])->whereNumber('conta')->name('plano-contas.destroy');

            Route::get('diarios', [DiarioController::class, 'index'])->name('diarios.index');
            Route::post('diarios', [DiarioController::class, 'store'])->name('diarios.store');

            Route::get('lancamentos', [LancamentoController::class, 'index'])->name('lancamentos.index');
            Route::post('lancamentos', [LancamentoController::class, 'store'])->name('lancamentos.store');
            Route::get('lancamentos/{lancamento}', [LancamentoController::class, 'show'])->whereNumber('lancamento')->name('lancamentos.show');
            Route::post('lancamentos/{lancamento}/estornar', [LancamentoController::class, 'estornar'])->whereNumber('lancamento')->name('lancamentos.estornar');

            Route::get('relatorios/balancete', [RelatorioContabilController::class, 'balancete'])->name('relatorios.balancete');
            Route::get('relatorios/razao', [RelatorioContabilController::class, 'razao'])->name('relatorios.razao');
            Route::get('relatorios/desequilibrios', [RelatorioContabilController::class, 'desequilibrios'])->name('relatorios.desequilibrios');
        });
    });
});
