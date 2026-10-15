<?php

use App\Http\Controllers\Api\Reparticao\MapasPorUnidadeController;
use App\Http\Controllers\Api\Reparticao\ReparticaoController;
use Illuminate\Support\Facades\Route;

// Versão Actual (ADR-071) — rotas do agente fc_reparticao; incluído por routes/api.php no grupo com empresa activa.

// R1/R2/R4: critérios de repartição, pré-visualização/repartição de um valor e alocação gravada em cada objecto
Route::prefix('reparticao')->name('reparticao.')->controller(ReparticaoController::class)->group(function () {
    Route::get('formas', 'formas')->name('formas');
    Route::get('criterios', 'index')->name('criterios.index');
    Route::post('criterios', 'store')->name('criterios.store');
    Route::get('criterios/{criterio}', 'show')->whereNumber('criterio')->name('criterios.show');
    Route::put('criterios/{criterio}', 'update')->whereNumber('criterio')->name('criterios.update');
    Route::delete('criterios/{criterio}', 'destroy')->whereNumber('criterio')->name('criterios.destroy');
    Route::post('simular', 'simular')->name('simular');
    $tipos = ['colaborador', 'pedido_compra', 'encomenda_compra', 'fatura_compra', 'venda', 'recibo_venda'];
    Route::get('alocacoes/{tipo}/{id}', 'alocacao')->whereIn('tipo', $tipos)->whereNumber('id')->name('alocacoes.show');
    Route::put('alocacoes/{tipo}/{id}', 'gravarAlocacao')->whereIn('tipo', $tipos)->whereNumber('id')->name('alocacoes.update');
});

// Balanço e DR com uma coluna por unidade de negócio
Route::prefix('contabilidade/relatorios')->name('contabilidade.relatorios.')->controller(MapasPorUnidadeController::class)->group(function () {
    Route::get('balanco/por-unidade', 'balanco')->name('balanco.por-unidade');
    Route::get('demonstracao-resultados/por-unidade', 'demonstracaoResultados')->name('demonstracao-resultados.por-unidade');
});
