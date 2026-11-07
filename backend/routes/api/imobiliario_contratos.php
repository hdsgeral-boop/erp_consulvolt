<?php

// Incluído por routes/api.php dentro do grupo autenticado com empresa activa (prefixo /api), a seguir a imobiliario.php.
// Gestão Imobiliária, Onda 2 (F3): contratos, rendas, facturação e pagamento, conta corrente e mora, caução, avisos, subarrendamento
// e configuração de contabilização.

use App\Http\Controllers\Api\Imobiliario\ConfiguracaoImobiliarioController;
use App\Http\Controllers\Api\Imobiliario\ContratosController;
use App\Http\Controllers\Api\Imobiliario\RendasController;
use App\Http\Controllers\Api\Imobiliario\SubarrendamentoController;
use Illuminate\Support\Facades\Route;

Route::prefix('imobiliario')->name('imobiliario.')->group(function () {
    Route::controller(ContratosController::class)->group(function () {
        Route::get('contratos/listas', 'listas')->name('contratos.listas');
        Route::get('contratos/resumo', 'resumo')->name('contratos.resumo');
        Route::get('contratos/avisos', 'avisos')->name('contratos.avisos');
        Route::get('contratos/contas', 'contas')->name('contratos.contas');
        Route::get('contratos', 'index')->name('contratos.index');
        Route::post('contratos', 'store')->name('contratos.store');
        Route::get('contratos/{contrato}', 'show')->whereNumber('contrato')->name('contratos.show');
        Route::put('contratos/{contrato}', 'update')->whereNumber('contrato')->name('contratos.update');
        Route::delete('contratos/{contrato}', 'destroy')->whereNumber('contrato')->name('contratos.destroy');
        Route::post('contratos/{contrato}/activar', 'activar')->whereNumber('contrato')->name('contratos.activar');
        Route::post('contratos/{contrato}/suspender', 'suspender')->whereNumber('contrato')->name('contratos.suspender');
        Route::post('contratos/{contrato}/terminar', 'terminar')->whereNumber('contrato')->name('contratos.terminar');
        Route::post('contratos/{contrato}/renovar', 'renovar')->whereNumber('contrato')->name('contratos.renovar');
        Route::post('contratos/{contrato}/actualizar-renda', 'actualizarRenda')->whereNumber('contrato')->name('contratos.actualizar_renda');
        Route::post('contratos/{contrato}/rendas/gerar', 'gerarRendas')->whereNumber('contrato')->name('contratos.rendas_gerar');
        Route::get('contratos/{contrato}/conta-corrente', 'contaCorrente')->whereNumber('contrato')->name('contratos.conta_corrente');
        Route::get('contratos/{contrato}/caucao', 'caucao')->whereNumber('contrato')->name('contratos.caucao');
        Route::post('contratos/{contrato}/caucao', 'movimentarCaucao')->whereNumber('contrato')->name('contratos.caucao_movimentar');
        Route::post('caucao/{movimento}/anular', 'anularMovimento')->whereNumber('movimento')->name('caucao.anular');
    });

    Route::controller(RendasController::class)->group(function () {
        Route::get('rendas', 'index')->name('rendas.index');
        Route::post('rendas/gerar-tudo', 'gerarTudo')->name('rendas.gerar_tudo');
        Route::post('rendas/aprovar', 'aprovar')->name('rendas.aprovar');
        Route::post('rendas/facturar', 'facturar')->name('rendas.facturar');
        Route::post('rendas/pagar', 'pagar')->name('rendas.pagar');
        Route::post('rendas/{renda}/anular', 'anular')->whereNumber('renda')->name('rendas.anular');
        Route::post('rendas/{renda}/recalcular', 'recalcular')->whereNumber('renda')->name('rendas.recalcular');
        Route::post('rendas/{renda}/extras', 'extra')->whereNumber('renda')->name('rendas.extras');
        Route::post('rendas/{renda}/desligar-pagamento', 'desligarPagamento')->whereNumber('renda')->name('rendas.desligar_pagamento');
        Route::post('rendas/{renda}/retencao', 'retencao')->whereNumber('renda')->name('rendas.retencao');
    });

    Route::controller(SubarrendamentoController::class)->group(function () {
        Route::get('subarrendamento/margem', 'margem')->name('subarrendamento.margem');
        Route::get('subarrendamento/cadeia/{fracao}', 'cadeia')->whereNumber('fracao')->name('subarrendamento.cadeia');
    });

    Route::controller(ConfiguracaoImobiliarioController::class)->group(function () {
        Route::get('configuracao', 'show')->name('configuracao.show');
        Route::get('configuracao/artigos', 'artigos')->name('configuracao.artigos');
        Route::put('configuracao', 'update')->name('configuracao.update');
    });
});
