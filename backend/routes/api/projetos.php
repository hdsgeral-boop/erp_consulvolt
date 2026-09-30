<?php

// Incluído por routes/api.php dentro do grupo autenticado com empresa activa (prefixo /api).
// Projectos / obras (ADR-052).

use App\Http\Controllers\Api\Projetos\EquipaController;
use App\Http\Controllers\Api\Projetos\ExecucaoController;
use App\Http\Controllers\Api\Projetos\PlaneamentoController;
use App\Http\Controllers\Api\Projetos\ProjetoController;
use Illuminate\Support\Facades\Route;

Route::prefix('projetos')->name('projetos.')->group(function () {
    Route::controller(ProjetoController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::get('ativos', 'ativos')->name('ativos');
        Route::get('gantt', 'gantt')->name('gantt');
        Route::get('fluxo', 'fluxo')->name('fluxo');
        Route::get('extracto', 'extracto')->name('extracto');
        Route::get('rentabilidade', 'rentabilidade')->name('rentabilidade');
        Route::get('configuracao', 'configuracao')->name('configuracao.show');
        Route::put('configuracao', 'guardarConfiguracao')->name('configuracao.update');
        Route::get('{projeto}', 'show')->whereNumber('projeto')->name('show');
        Route::put('{projeto}', 'update')->whereNumber('projeto')->name('update');
        Route::post('{projeto}/estado', 'estado')->whereNumber('projeto')->name('estado');
        Route::get('{projeto}/resumo', 'resumo')->whereNumber('projeto')->name('resumo');
        Route::get('{projeto}/atividade', 'atividade')->whereNumber('projeto')->name('atividade');
    });

    Route::prefix('{projeto}')->whereNumber('projeto')->group(function () {
        Route::controller(PlaneamentoController::class)->group(function () {
            Route::get('wbs', 'wbs')->name('wbs');
            Route::post('marcos', 'guardarMarco')->name('marcos.store');
            Route::put('marcos/{marco}', 'guardarMarco')->whereNumber('marco')->name('marcos.update');
            Route::delete('marcos/{marco}', 'eliminarMarco')->whereNumber('marco')->name('marcos.destroy');
            Route::post('tarefas', 'guardarTarefa')->name('tarefas.store');
            Route::put('tarefas/{tarefa}', 'guardarTarefa')->whereNumber('tarefa')->name('tarefas.update');
            Route::delete('tarefas/{tarefa}', 'eliminarTarefa')->whereNumber('tarefa')->name('tarefas.destroy');
            Route::post('tarefas/{tarefa}/execucao', 'execucao')->whereNumber('tarefa')->name('tarefas.execucao');
            Route::post('tarefas/{tarefa}/mover', 'mover')->whereNumber('tarefa')->name('tarefas.mover');
            Route::get('kanban', 'kanban')->name('kanban');
            Route::put('kanban/colunas', 'colunasKanban')->name('kanban.colunas');
            Route::post('kanban/mover', 'moverKanban')->name('kanban.mover');
        });

        Route::controller(EquipaController::class)->group(function () {
            Route::get('equipa', 'equipa')->name('equipa');
            Route::post('equipa/membros', 'guardarMembro')->name('equipa.membros.store');
            Route::put('equipa/membros/{membro}', 'guardarMembro')->whereNumber('membro')->name('equipa.membros.update');
            Route::post('equipa/membros/massa', 'acrescentarEmMassa')->name('equipa.membros.massa');
            Route::post('equipa/membros/alterar', 'alterarEmMassa')->name('equipa.membros.alterar');
            Route::post('equipa/membros/remover', 'remover')->name('equipa.membros.remover');
            Route::get('organigrama', 'organigrama')->name('organigrama');
            Route::post('organigrama/posicoes', 'guardarPosicao')->name('organigrama.posicoes.store');
            Route::post('organigrama/posicoes/lote', 'guardarPosicoes')->name('organigrama.posicoes.lote');
            Route::put('organigrama/posicoes/{posicao}', 'guardarPosicao')->whereNumber('posicao')->name('organigrama.posicoes.update');
            Route::delete('organigrama/posicoes/{posicao}', 'eliminarPosicao')->whereNumber('posicao')->name('organigrama.posicoes.destroy');
            Route::post('organigrama/posicoes/{posicao}/arrumar', 'arrumar')->whereNumber('posicao')->name('organigrama.posicoes.arrumar');
            Route::post('organigrama/posicoes/{posicao}/tarefas', 'associarTarefas')->whereNumber('posicao')->name('organigrama.posicoes.tarefas');
            Route::post('organigrama/alocar', 'alocar')->name('organigrama.alocar');
            Route::post('organigrama/disposicao', 'disposicao')->name('organigrama.disposicao');
            Route::post('organigrama/orcamento', 'mapearOrcamento')->name('organigrama.orcamento');
            Route::post('organigrama/modelo', 'modeloBase')->name('organigrama.modelo');
        });

        Route::controller(ExecucaoController::class)->group(function () {
            Route::get('orcamento', 'orcamento')->name('orcamento');
            Route::post('orcamento', 'guardarLinha')->name('orcamento.store');
            Route::put('orcamento/{linha}', 'guardarLinha')->whereNumber('linha')->name('orcamento.update');
            Route::delete('orcamento/{linha}', 'eliminarLinha')->whereNumber('linha')->name('orcamento.destroy');
            Route::get('custos-tarefas', 'custosPorTarefa')->name('custos-tarefas');
            Route::get('aditamentos', 'aditamentos')->name('aditamentos');
            Route::post('aditamentos', 'guardarAditamento')->name('aditamentos.store');
            Route::put('aditamentos/{aditamento}', 'guardarAditamento')->whereNumber('aditamento')->name('aditamentos.update');
            Route::delete('aditamentos/{aditamento}', 'eliminarAditamento')->whereNumber('aditamento')->name('aditamentos.destroy');
            Route::get('horas', 'horas')->name('horas');
            Route::post('horas', 'registarHoras')->name('horas.store');
            Route::delete('horas/{folha}', 'eliminarHoras')->whereNumber('folha')->name('horas.destroy');
            Route::post('equipamentos', 'registarEquipamento')->name('equipamentos.store');
            Route::delete('equipamentos/{movimento}', 'eliminarEquipamento')->whereNumber('movimento')->name('equipamentos.destroy');
            Route::get('requisicoes', 'requisicoes')->name('requisicoes');
            Route::post('requisicoes', 'requisitar')->name('requisicoes.store');
            Route::get('revisoes', 'revisoes')->name('revisoes');
            Route::get('revisoes/simulacao', 'simular')->name('revisoes.simulacao');
            Route::post('revisoes', 'executar')->name('revisoes.store');
            Route::get('revisoes/{revisao}', 'revisao')->whereNumber('revisao')->name('revisoes.show');
            Route::get('revisoes/{revisao}/faturacao', 'propostaFaturacao')->whereNumber('revisao')->name('revisoes.faturacao');
            Route::post('revisoes/{revisao}/faturar', 'faturar')->whereNumber('revisao')->name('revisoes.faturar');
        });
    });

    Route::post('folhas-horas/periodos/{periodo}/imputar', [ExecucaoController::class, 'imputarPeriodo'])->whereNumber('periodo')->name('horas.imputar');
    Route::post('folhas-horas/periodos/{periodo}/reverter', [ExecucaoController::class, 'reverterPeriodo'])->whereNumber('periodo')->name('horas.reverter');
});
