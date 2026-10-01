<?php

use App\Http\Controllers\Api\Consolidacao\ConsolidacaoController;
use App\Http\Controllers\Api\Contabilidade\EncerramentoController;
use App\Http\Controllers\Api\Contabilidade\RotinasController;
use Illuminate\Support\Facades\Route;

// Incluído por routes/api.php dentro do grupo autenticado com empresa activa (prefixo /api).
// Encerramento do exercício e rotinas contabilísticas (ADR-056); consolidação de empresas (ADR-057).

Route::prefix('contabilidade/encerramento')->name('contabilidade.encerramento.')->controller(EncerramentoController::class)->group(function () {
    Route::get('/', 'exercicios')->name('index');
    Route::get('{ano}', 'estado')->whereNumber('ano')->name('show');
    Route::get('{ano}/passos/{passo}', 'previsualizar')->whereNumber(['ano', 'passo'])->name('passos.show');
    Route::post('{ano}/passos/{passo}', 'executarPasso')->whereNumber(['ano', 'passo'])->name('passos.executar');
    Route::get('{ano}/validacoes', 'validar')->whereNumber('ano')->name('validacoes');
    Route::post('{ano}/encerrar', 'encerrar')->whereNumber('ano')->name('encerrar');
    Route::post('{ano}/reabrir', 'reabrir')->whereNumber('ano')->name('reabrir');
    Route::post('{ano}/cancelar-apuramento', 'cancelarApuramento')->whereNumber('ano')->name('cancelar');
    Route::get('{ano}/mapa', 'mapa')->whereNumber('ano')->name('mapa');
});

Route::prefix('contabilidade/rotinas')->name('contabilidade.rotinas.')->controller(RotinasController::class)->group(function () {
    Route::get('capitalizacao/obras', 'obrasInternas')->name('capitalizacao.obras');
    Route::post('capitalizacao', 'capitalizar')->name('capitalizacao');
    Route::get('compensacao/pares', 'paresCompensacao')->name('compensacao.pares');
    Route::post('compensacao', 'compensar')->name('compensacao');
    Route::post('transferencia/saldos', 'saldosATransferir')->name('transferencia.saldos');
    Route::post('transferencia', 'transferirSaldos')->name('transferencia');
    Route::get('imposto-selo', 'resumoSelo')->name('selo.resumo');
    Route::post('imposto-selo', 'lancarSelo')->name('selo.lancar');
    Route::get('imposto-selo/historico', 'historicoSelo')->name('selo.historico');
    Route::post('actualizacao-massa', 'actualizarEmMassa')->name('actualizacao');
    Route::get('historico', 'historico')->name('historico');
    Route::post('anular', 'anular')->name('anular');
    Route::get('limpeza-reconciliacoes', 'limpezaReconciliacoes')->name('limpeza');
});

Route::prefix('consolidacao')->name('consolidacao.')->controller(ConsolidacaoController::class)->group(function () {
    Route::get('grupos', 'index')->name('grupos.index');
    Route::post('grupos', 'store')->name('grupos.store');
    Route::get('grupos/{grupo}', 'show')->whereNumber('grupo')->name('grupos.show');
    Route::put('grupos/{grupo}', 'update')->whereNumber('grupo')->name('grupos.update');
    Route::delete('grupos/{grupo}', 'destroy')->whereNumber('grupo')->name('grupos.destroy');
    Route::post('grupos/{grupo}/executar', 'executar')->whereNumber('grupo')->name('grupos.executar');
    Route::get('grupos/{grupo}/mapa', 'mapa')->whereNumber('grupo')->name('grupos.mapa');
    Route::get('execucoes/{execucao}', 'execucao')->whereNumber('execucao')->name('execucoes.show');
});
