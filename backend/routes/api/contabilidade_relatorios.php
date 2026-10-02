<?php

use App\Http\Controllers\Api\Contabilidade\CompensacaoController;
use App\Http\Controllers\Api\Contabilidade\DemonstracoesFinanceirasController;
use App\Http\Controllers\Api\Contabilidade\ImportacaoLancamentosController;
use App\Http\Controllers\Api\Contabilidade\NotasPorContaController;
use App\Http\Controllers\Api\Contabilidade\RelatorioContabilController;
use App\Http\Controllers\Api\Contabilidade\RelatorioContasController;
use App\Http\Controllers\Api\Contabilidade\TabelasAuxiliaresController;
use Illuminate\Support\Facades\Route;

// Incluído por routes/api.php dentro do grupo autenticado com empresa activa (prefixo /api).
// Contabilidade parte 2 — relatórios financeiros, Relatório e Contas, tabelas auxiliares e importação (ADR-055).
// (balancete, razão e desequilíbrios, plano de contas, diários GET/POST e lançamentos estão em routes/api.php)

Route::prefix('contabilidade')->name('contabilidade.')->group(function () {
    Route::prefix('relatorios')->name('relatorios.')->group(function () {
        Route::controller(DemonstracoesFinanceirasController::class)->group(function () {
            Route::get('balanco', 'balanco')->name('balanco');
            Route::get('demonstracao-resultados', 'demonstracaoResultados')->name('demonstracao-resultados');
            Route::get('fluxo-caixa', 'fluxoCaixa')->name('fluxo-caixa');
            Route::get('movimentos-sem-nota', 'movimentosSemNota')->name('movimentos-sem-nota');
            Route::get('notas/{tipo}/{nota}', 'detalheNota')->whereIn('tipo', ['demonstracao', 'fluxo'])->whereNumber('nota')->name('notas.detalhe');
        });
        Route::controller(RelatorioContabilController::class)->group(function () {
            Route::get('extrato', 'extrato')->name('extrato');
            Route::get('evolucao', 'evolucao')->name('evolucao');
            Route::get('iva', 'mapaIva')->name('iva');
            Route::post('iva/reconciliacao-agt', 'reconciliacaoAgt')->name('iva.reconciliacao-agt');
        });
    });

    Route::controller(CompensacaoController::class)->prefix('compensacoes')->name('compensacoes.')->group(function () {
        Route::post('/', 'store')->name('store');
        Route::post('regularizar', 'regularizar')->name('regularizar');
        Route::get('{codigo}', 'show')->where('codigo', '[A-Za-z0-9_\-]+')->name('show');
        Route::delete('{codigo}', 'destroy')->where('codigo', '[A-Za-z0-9_\-]+')->name('destroy');
    });

    Route::controller(RelatorioContasController::class)->prefix('relatorio-contas')->name('relatorio-contas.')->group(function () {
        Route::get('{ano}', 'show')->whereNumber('ano')->name('show');
        Route::put('{ano}', 'update')->whereNumber('ano')->name('update');
        Route::post('{ano}/concluir', 'concluir')->whereNumber('ano')->name('concluir');
        Route::post('{ano}/reabrir', 'reabrir')->whereNumber('ano')->name('reabrir');
    });

    // E-CON-1: «Sincronizar notas automática» (recoverDataMapping) — ferramenta de reparação, simulação por omissão
    Route::post('tabelas/notas-demonstracao/sincronizar-por-conta', [NotasPorContaController::class, 'sincronizar'])->name('tabelas.notas-demonstracao.sincronizar-por-conta');

    Route::controller(TabelasAuxiliaresController::class)->group(function () {
        Route::post('tabelas/centros-custo/sincronizar', 'sincronizarCentrosCusto')->name('tabelas.centros-custo.sincronizar');
        Route::prefix('tabelas/{tabela}')->whereIn('tabela', ['diarios', 'notas-demonstracao', 'notas-fluxo-caixa', 'centros-custo'])->name('tabelas.')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/', 'store')->name('store');
            Route::post('importar', 'importar')->name('importar');
            Route::post('copiar', 'copiar')->name('copiar');
            Route::put('{id}', 'update')->whereNumber('id')->name('update');
            Route::delete('{id}', 'destroy')->whereNumber('id')->name('destroy');
            Route::post('{id}/enviar', 'enviar')->whereNumber('id')->name('enviar');
        });

        Route::get('reciclagem', 'reciclagem')->name('reciclagem.index');
        Route::post('reciclagem/restaurar', 'restaurar')->name('reciclagem.restaurar');
        Route::post('reciclagem/eliminar', 'eliminarReciclagem')->name('reciclagem.eliminar');
        Route::delete('reciclagem', 'esvaziarReciclagem')->name('reciclagem.esvaziar');
    });

    Route::controller(ImportacaoLancamentosController::class)->group(function () {
        Route::post('lancamentos/importar', 'importar')->name('lancamentos.importar');
        Route::get('saldos-historicos/{ano}', 'saldosHistoricos')->whereNumber('ano')->name('saldos-historicos.show');
        Route::put('saldos-historicos/{ano}', 'gravarSaldosHistoricos')->whereNumber('ano')->name('saldos-historicos.update');
        Route::get('saldos-historicos/{ano}/modelo', 'modeloSaldosHistoricos')->whereNumber('ano')->name('saldos-historicos.modelo');
        Route::post('saldos-historicos/importar', 'importarSaldosHistoricos')->name('saldos-historicos.importar');
    });
});
