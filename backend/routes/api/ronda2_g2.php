<?php

// Incluído por routes/api.php dentro do grupo autenticado com empresa activa (prefixo /api).
// Ronda 2 (grupo 2): RH (A-08 cálculo, A-09 importações e contratos em massa, A-10 recibos PDF/ZIP, M-12 relógio,
// M-13 produtividade), decisões 1, 3, 5, 6 e 7 (tabela de IRT, configuração de RH, feriados nacionais).

use App\Http\Controllers\Api\Ativos\AtivosController;
use App\Http\Controllers\Api\RH\AvaliacaoEquipaController;
use App\Http\Controllers\Api\RH\ConfiguracaoRHController;
use App\Http\Controllers\Api\RH\FolhaSalarialController;
use App\Http\Controllers\Api\RH\ImportacaoRHController;
use Illuminate\Support\Facades\Route;

Route::prefix('rh/salarios')->name('rh.salarios.')->group(function () {
    Route::delete('periodos/{id}', [FolhaSalarialController::class, 'eliminarPeriodo'])->whereNumber('id')->name('periodos.destroy');
    Route::post('periodos/{id}/copiar', [FolhaSalarialController::class, 'copiar'])->whereNumber('id')->name('periodos.copiar');
    Route::post('periodos/{id}/lancamentos/lote', [FolhaSalarialController::class, 'lote'])->whereNumber('id')->name('lancamentos.lote');
    Route::put('periodos/{id}/lancamentos/lote', [FolhaSalarialController::class, 'editarLote'])->whereNumber('id')->name('lancamentos.lote.editar');
    Route::delete('periodos/{id}/lancamentos/lote', [FolhaSalarialController::class, 'removerLote'])->whereNumber('id')->name('lancamentos.lote.remover');
    Route::post('periodos/{id}/importar-excel', [ImportacaoRHController::class, 'calculo'])->whereNumber('id')->middleware('throttle:pesado')->name('periodos.importar-excel');
    Route::get('periodos/{id}/recibos/{colaborador}/pdf', [ConfiguracaoRHController::class, 'reciboPdf'])->whereNumber(['id', 'colaborador'])->name('recibos.pdf');
    Route::get('periodos/{id}/recibos-zip', [ConfiguracaoRHController::class, 'recibosZip'])->whereNumber('id')->middleware('throttle:pesado')->name('recibos.zip');
});

Route::prefix('rh')->name('rh.')->group(function () {
    Route::get('configuracao', [ConfiguracaoRHController::class, 'config'])->name('configuracao');
    Route::put('configuracao', [ConfiguracaoRHController::class, 'gravarConfig'])->name('configuracao.gravar');
    Route::put('tabela-irt', [ConfiguracaoRHController::class, 'gravarTabelaIrt'])->name('tabela-irt.gravar');
    Route::delete('tabela-irt', [ConfiguracaoRHController::class, 'reporTabelaIrt'])->name('tabela-irt.repor');
    Route::get('assiduidade/feriados-nacionais', [ConfiguracaoRHController::class, 'feriadosNacionais'])->name('assiduidade.feriados-nacionais');
    Route::post('assiduidade/registos/importar-relogio', [ConfiguracaoRHController::class, 'importarRelogio'])->middleware('throttle:externo')->name('assiduidade.registos.relogio');

    Route::get('importacoes/modelos/{entidade}', [ImportacaoRHController::class, 'modelo'])->where('entidade', '[a-z_]+')->name('importacoes.modelo');
    Route::post('importacoes/colaboradores', [ImportacaoRHController::class, 'colaboradores'])->middleware('throttle:pesado')->name('importacoes.colaboradores');
    Route::post('importacoes/contratos', [ImportacaoRHController::class, 'contratos'])->middleware('throttle:pesado')->name('importacoes.contratos');
    Route::post('produtividade/periodos/{periodo}/importar', [ImportacaoRHController::class, 'produtividade'])->whereNumber('periodo')->name('produtividade.importar');
    Route::get('avaliacao/equipa', [AvaliacaoEquipaController::class, 'equipa'])->name('avaliacao.equipa');
    Route::get('avaliacao/autoavaliacoes', [AvaliacaoEquipaController::class, 'autoavaliacoes'])->name('avaliacao.autoavaliacoes');
    Route::get('avaliacao/chefias', [AvaliacaoEquipaController::class, 'chefias'])->name('avaliacao.chefias');
    Route::get('contratos/simulacao', [ImportacaoRHController::class, 'simularContratos'])->name('contratos.simulacao');
    Route::post('contratos/massa', [ImportacaoRHController::class, 'contratosMassa'])->name('contratos.massa');
});

// Activos: importação .xlsx e modelo (paridade com downloadAssetExcelTemplate/handleAssetExcelUpload)
Route::post('ativos/bens/importar/folha', [AtivosController::class, 'lerFolhaBens'])->middleware('throttle:pesado')->name('ativos.bens.importar.folha');
Route::get('ativos/bens/importar/modelo', [AtivosController::class, 'modeloBens'])->name('ativos.bens.importar.modelo');
