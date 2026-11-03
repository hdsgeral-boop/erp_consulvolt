<?php

use App\Http\Controllers\Api\Estrutura\EntregaveisController;
use App\Http\Controllers\Api\RH\EstruturaController;
use App\Http\Controllers\Api\RH\PortalEntregaveisController;
use Illuminate\Support\Facades\Route;

// Fase J (ADR-078) — Estrutura Orgânica: tarefas das unidades e entregáveis mensuráveis, validação pelo responsável, impedimentos,
// atrasos, ponte CRM e ponte para a produtividade; portal do colaborador (entregáveis, aprovações, equipa, análise do pedido,
// compras) e arrumação do organigrama. Incluído por routes/api.php no grupo com empresa activa (prefixo /api).

Route::prefix('rh/estrutura')->name('rh.estrutura.')->group(function () {
    Route::post('unidades/{unidade}/arrumar', [EstruturaController::class, 'arrumar'])->whereNumber('unidade')->name('unidades.arrumar');

    Route::controller(EntregaveisController::class)->group(function () {
        Route::get('unidades/{unidade}/tarefas', 'daUnidade')->whereNumber('unidade')->name('unidades.tarefas');
        Route::post('tarefas', 'gravarTarefa')->name('tarefas.store');
        Route::put('tarefas/{tarefa}', 'gravarTarefa')->whereNumber('tarefa')->name('tarefas.update');
        Route::delete('tarefas/{tarefa}', 'eliminarTarefa')->whereNumber('tarefa')->name('tarefas.destroy');
        Route::post('tarefas/{tarefa}/entregaveis', 'gravarEntregavel')->whereNumber('tarefa')->name('tarefas.entregaveis.store');
        Route::get('entregaveis/atrasos', 'atrasos')->name('entregaveis.atrasos');
        Route::get('entregaveis/sugerir-codigo', 'sugerirCodigo')->name('entregaveis.sugerir-codigo');
        Route::put('entregaveis/{entregavel}', 'actualizarEntregavel')->whereNumber('entregavel')->name('entregaveis.update');
        Route::delete('entregaveis/{entregavel}', 'eliminarEntregavel')->whereNumber('entregavel')->name('entregaveis.destroy');
        Route::post('entregaveis/{entregavel}/copiar', 'copiar')->whereNumber('entregavel')->name('entregaveis.copiar');
        Route::post('entregaveis/{entregavel}/criar-item', 'criarItem')->whereNumber('entregavel')->name('entregaveis.criar-item');
        Route::get('entregaveis/{entregavel}/execucoes', 'execucoes')->whereNumber('entregavel')->name('entregaveis.execucoes.index');
        Route::post('entregaveis/{entregavel}/execucoes', 'gravarExecucao')->whereNumber('entregavel')->name('entregaveis.execucoes.store');
        Route::put('execucoes/{execucao}', 'actualizarExecucao')->whereNumber('execucao')->name('execucoes.update');
        Route::delete('execucoes/{execucao}', 'eliminarExecucao')->whereNumber('execucao')->name('execucoes.destroy');
        Route::post('execucoes/{execucao}/validar', 'validar')->whereNumber('execucao')->name('execucoes.validar');
        Route::post('execucoes/{execucao}/reenviar', 'reenviar')->whereNumber('execucao')->name('execucoes.reenviar');
        Route::post('execucoes/{execucao}/repor', 'repor')->whereNumber('execucao')->name('execucoes.repor');
        Route::get('entregaveis/{entregavel}/impedimentos', 'impedimentos')->whereNumber('entregavel')->name('entregaveis.impedimentos.index');
        Route::post('entregaveis/{entregavel}/impedimentos', 'registarImpedimento')->whereNumber('entregavel')->name('entregaveis.impedimentos.store');
        Route::post('impedimentos/{impedimento}/decidir', 'decidirImpedimento')->whereNumber('impedimento')->name('impedimentos.decidir');
        Route::get('entregaveis/{entregavel}/crm/estado', 'crmEstado')->whereNumber('entregavel')->name('entregaveis.crm.estado');
        Route::post('entregaveis/{entregavel}/crm/sincronizar', 'crmSincronizar')->whereNumber('entregavel')->name('entregaveis.crm.sincronizar');
    });
});

Route::prefix('rh/produtividade')->name('rh.produtividade.')->controller(EntregaveisController::class)->group(function () {
    Route::get('periodos/{periodo}/entregaveis/analise', 'analise')->whereNumber('periodo')->name('periodos.entregaveis.analise');
    Route::post('periodos/{periodo}/entregaveis/converter', 'converter')->whereNumber('periodo')->name('periodos.entregaveis.converter');
    Route::post('periodos/{periodo}/entregaveis/anular', 'anular')->whereNumber('periodo')->name('periodos.entregaveis.anular');
    Route::get('elegibilidade', 'elegibilidade')->name('elegibilidade.index');
    Route::post('elegibilidade', 'gravarElegibilidade')->name('elegibilidade.store');
    Route::delete('elegibilidade/{id}', 'eliminarElegibilidade')->whereNumber('id')->name('elegibilidade.destroy');
});

Route::prefix('rh/portal')->name('rh.portal.')->controller(PortalEntregaveisController::class)->group(function () {
    Route::get('entregaveis', 'entregaveis')->name('entregaveis.index');
    Route::post('entregaveis/{entregavel}/execucoes', 'registarEntrega')->whereNumber('entregavel')->name('entregaveis.execucoes.store');
    Route::post('execucoes/{execucao}/reenviar', 'reenviar')->whereNumber('execucao')->name('entregaveis.execucoes.reenviar');
    Route::post('entregaveis/{entregavel}/impedimentos', 'registarImpedimento')->whereNumber('entregavel')->name('entregaveis.impedimentos.store');
    Route::get('aprovacoes/entregas', 'entregasParaValidar')->name('aprovacoes.entregas');
    Route::post('execucoes/{execucao}/validar', 'validar')->whereNumber('execucao')->name('aprovacoes.entregas.validar');
    Route::get('aprovacoes/impedimentos', 'impedimentosParaDecidir')->name('aprovacoes.impedimentos');
    Route::post('impedimentos/{impedimento}/decidir', 'decidirImpedimento')->whereNumber('impedimento')->name('aprovacoes.impedimentos.decidir');
    Route::get('aprovacoes/atrasos', 'atrasos')->name('aprovacoes.atrasos');
    Route::get('equipa', 'equipa')->name('equipa');
    Route::get('equipa/ferias', 'feriasEquipa')->name('equipa.ferias');
    Route::get('pedidos/{pedido}/analise', 'analisePedido')->whereNumber('pedido')->name('pedidos.analise');
    Route::post('compras/{pedido}/decidir', 'decidirCompra')->whereNumber('pedido')->name('compras.decidir');
});
