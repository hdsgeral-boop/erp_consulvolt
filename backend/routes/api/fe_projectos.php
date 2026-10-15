<?php

use App\Http\Controllers\Api\Projetos\ExecucaoMedidaController;
use App\Http\Controllers\Api\Projetos\OrcamentoQuantidadesController;
use App\Http\Controllers\Api\RH\ProdutividadeProjetosController;
use Illuminate\Support\Facades\Route;

// Versão Actual (ADR-071) — rotas do agente fe_projectos; incluído por routes/api.php no grupo com empresa activa.
// Fase E: metas por quantidade e execução medida, dependências, duplicar / mudar de nível, mapa de quantidades,
// orçamento mapeado pelas quantidades, separador Execução, carga de trabalho e ponte para a Produtividade do RH.

Route::prefix('projetos/{projeto}')->whereNumber('projeto')->name('projetos.fe.')->group(function () {
    Route::controller(ExecucaoMedidaController::class)->group(function () {
        Route::post('tarefas/{tarefa}/medicoes', 'registarMedicao')->whereNumber('tarefa')->name('medicoes.store');
        Route::get('medicoes/folha', 'folhaMedicao')->name('medicoes.folha');
        Route::post('medicoes/lote', 'registarLote')->name('medicoes.lote');
        Route::post('tarefas/por-quantidade', 'criarPorQuantidade')->name('tarefas.por-quantidade');
        Route::get('tarefas/candidatas-predecessora', 'candidatasPredecessora')->name('tarefas.candidatas');
        Route::post('{tipo}/{id}/duplicar', 'duplicar')->whereIn('tipo', ['tarefas', 'marcos'])->whereNumber('id')->name('duplicar');
        Route::post('{tipo}/{id}/nivel', 'mudarNivel')->whereIn('tipo', ['tarefas', 'marcos'])->whereNumber('id')->name('nivel');
        Route::get('execucao', 'execucao')->name('execucao');
        Route::get('mapa-quantidades', 'mapaQuantidades')->name('mapa-quantidades');
        Route::get('carga-trabalho', 'cargaTrabalho')->name('carga-trabalho');
        Route::get('revisoes/meses', 'mesesRevisao')->name('revisoes.meses');
    });
    Route::controller(OrcamentoQuantidadesController::class)->group(function () {
        Route::get('orcamento/quantidades', 'resumo')->name('orcamento.quantidades');
        Route::post('orcamento/{linha}/quantidades/prever', 'prever')->whereNumber('linha')->name('orcamento.quantidades.prever');
        Route::post('orcamento/{linha}/quantidades', 'mapear')->whereNumber('linha')->name('orcamento.quantidades.store');
        Route::delete('orcamento/{linha}/quantidades/{indice}', 'retirar')->whereNumber(['linha', 'indice'])->name('orcamento.quantidades.destroy');
    });
});

Route::prefix('rh/produtividade/projetos')->name('rh.produtividade.projetos.')->controller(ProdutividadeProjetosController::class)->group(function () {
    Route::get('recolha', 'recolha')->name('recolha');
    Route::post('analisar', 'analisar')->name('analisar');
    Route::post('enviar', 'enviar')->name('enviar');
});
