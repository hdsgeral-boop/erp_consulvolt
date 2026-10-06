<?php

// Incluído por routes/api.php dentro do grupo autenticado com empresa activa (prefixo /api).
// Ronda 2 (grupo 1): Contabilidade (A-05 classificação, M-09 transferência, decisão 20 plano para o encerramento),
// POS (M-15 mesas, M-16 impressões/importação).

use App\Http\Controllers\Api\Contabilidade\EncerramentoController;
use App\Http\Controllers\Api\Contabilidade\LancamentoController;
use App\Http\Controllers\Api\POS\DocumentosLavandariaController;
use App\Http\Controllers\Api\POS\ImportacaoLavandariaController;
use App\Http\Controllers\Api\POS\MesasPOSController;
use Illuminate\Support\Facades\Route;

Route::prefix('contabilidade')->name('contabilidade.')->group(function () {
    Route::post('lancamentos/classificacao', [LancamentoController::class, 'classificacao'])->name('lancamentos.classificacao');
    Route::post('lancamentos/{lancamento}/transferir', [LancamentoController::class, 'transferir'])->whereNumber('lancamento')->name('lancamentos.transferir');
    Route::get('encerramento/{ano}/plano', [EncerramentoController::class, 'diagnosticoPlano'])->whereNumber('ano')->name('encerramento.plano');
    Route::post('encerramento/{ano}/plano/corrigir', [EncerramentoController::class, 'corrigirPlano'])->whereNumber('ano')->name('encerramento.plano.corrigir');
});

// M-15 — mesas do POS restaurante (decisão 14)
Route::prefix('pos')->name('pos.')->controller(MesasPOSController::class)->group(function () {
    Route::get('terminais/{terminal}/mesas', 'index')->whereNumber('terminal')->name('mesas.index');
    Route::post('terminais/{terminal}/mesas', 'store')->whereNumber('terminal')->name('mesas.store');
    Route::put('terminais/{terminal}/mesas/{mesa}', 'update')->whereNumber(['terminal', 'mesa'])->name('mesas.update');
    Route::delete('mesas/{mesa}', 'destroy')->whereNumber('mesa')->name('mesas.destroy');
    Route::get('mesas/{mesa}/conta', 'conta')->whereNumber('mesa')->name('mesas.conta');
    Route::put('mesas/{mesa}/conta', 'gravarConta')->whereNumber('mesa')->name('mesas.conta.gravar');
    Route::post('sessoes/{sessao}/mesas/{mesa}/cobrar', 'cobrar')->whereNumber(['sessao', 'mesa'])->name('mesas.cobrar');
});

// M-16 — importação das tabelas da lavandaria (peças, serviços, preços por peça e serviço)
Route::post('pos/lavandaria/importar', ImportacaoLavandariaController::class)->middleware('throttle:pesado')->name('pos.lavandaria.importar');
Route::get('pos/lavandaria/ordens/{ordem}/documentos/{venda}', DocumentosLavandariaController::class)->whereNumber(['ordem', 'venda'])->name('pos.lavandaria.ordens.documento');
