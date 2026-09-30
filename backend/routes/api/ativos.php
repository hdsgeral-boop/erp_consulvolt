<?php

// Incluído por routes/api.php dentro do grupo autenticado com empresa activa (prefixo /api).
// Activos (imobilizado) — ADR-051.

use App\Http\Controllers\Api\Ativos\AmortizacoesController;
use App\Http\Controllers\Api\Ativos\AtivosController;
use Illuminate\Support\Facades\Route;

Route::prefix('ativos')->name('ativos.')->group(function () {
    Route::controller(AtivosController::class)->group(function () {
        Route::get('categorias', 'categorias')->name('categorias.index');
        Route::post('categorias', 'guardarCategoria')->name('categorias.store');
        Route::put('categorias/{categoria}', 'guardarCategoria')->whereNumber('categoria')->name('categorias.update');
        Route::delete('categorias/{categoria}', 'eliminarCategoria')->whereNumber('categoria')->name('categorias.destroy');

        Route::get('bens', 'bens')->name('bens.index');
        Route::post('bens', 'guardarBem')->name('bens.store');
        Route::post('bens/importar', 'importarBens')->name('bens.importar');
        Route::post('bens/edicao-massa', 'editarBens')->name('bens.edicao_massa');
        Route::post('bens/eliminar', 'eliminarBens')->name('bens.eliminar_massa');
        Route::get('bens/{bem}', 'bem')->whereNumber('bem')->name('bens.show');
        Route::put('bens/{bem}', 'guardarBem')->whereNumber('bem')->name('bens.update');
        Route::delete('bens/{bem}', 'eliminarBem')->whereNumber('bem')->name('bens.destroy');
        Route::post('bens/{bem}/transferencias', 'transferir')->whereNumber('bem')->name('bens.transferir');

        Route::get('transferencias', 'transferencias')->name('transferencias.index');
        Route::get('afetacoes', 'afetacoes')->name('afetacoes.index');
        Route::post('afetacoes', 'guardarAfetacao')->name('afetacoes.store');
        Route::put('afetacoes/{afetacao}', 'guardarAfetacao')->whereNumber('afetacao')->name('afetacoes.update');
        Route::delete('afetacoes/{afetacao}', 'eliminarAfetacao')->whereNumber('afetacao')->name('afetacoes.destroy');

        Route::get('manutencoes', 'manutencoes')->name('manutencoes.index');
        Route::post('manutencoes', 'registarManutencao')->name('manutencoes.store');
        Route::post('manutencoes/{manutencao}/executar', 'executarManutencao')->whereNumber('manutencao')->name('manutencoes.executar');
        Route::delete('manutencoes/{manutencao}', 'eliminarManutencao')->whereNumber('manutencao')->name('manutencoes.destroy');

        Route::get('aquisicoes-pendentes', 'aquisicoesPendentes')->name('aquisicoes.index');
        Route::post('aquisicoes-pendentes/{linha}/inventariar', 'inventariar')->whereNumber('linha')->name('aquisicoes.inventariar');
        Route::post('aquisicoes-pendentes/{linha}/ligar', 'ligar')->whereNumber('linha')->name('aquisicoes.ligar');

        Route::get('abates', 'abatesLista')->name('abates.index');
        Route::post('abates/simulacao', 'simularAbate')->name('abates.simulacao');
        Route::post('abates', 'abater')->name('abates.store');
        Route::post('abates/{abate}/anular', 'anularAbate')->whereNumber('abate')->name('abates.anular');
    });

    Route::controller(AmortizacoesController::class)->group(function () {
        Route::get('amortizacoes', 'periodo')->name('amortizacoes.periodo');
        Route::get('amortizacoes/pendentes', 'pendentes')->name('amortizacoes.pendentes');
        Route::get('amortizacoes/pre-visualizacao', 'previsualizar')->name('amortizacoes.previsualizacao');
        Route::get('amortizacoes/verificacao', 'verificar')->name('amortizacoes.verificacao');
        Route::get('amortizacoes/por-integrar-no-ano', 'porIntegrarNoAno')->name('amortizacoes.por_integrar_ano');
        Route::post('amortizacoes/calcular', 'calcular')->name('amortizacoes.calcular');
        Route::put('amortizacoes/quota', 'definirQuota')->name('amortizacoes.quota');
        Route::post('amortizacoes/integrar', 'integrar')->name('amortizacoes.integrar');
        Route::post('amortizacoes/reabrir', 'reabrir')->name('amortizacoes.reabrir');
        Route::post('bens/{bem}/amortizacoes/integrar', 'integrarAtivo')->whereNumber('bem')->name('bens.amortizacoes.integrar');

        Route::get('mapas/amortizacoes', 'mapaAmortizacoes')->name('mapas.amortizacoes');
        Route::get('mapas/fiscal', 'mapaFiscal')->name('mapas.fiscal');
        Route::get('mapas/categorias', 'resumoCategorias')->name('mapas.categorias');
        Route::get('fluxo', 'fluxo')->name('fluxo');
    });
});
