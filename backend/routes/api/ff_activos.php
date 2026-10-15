<?php

use App\Http\Controllers\Api\Ativos\LocaisAtivosController;
use App\Http\Controllers\Api\Ativos\ManutencaoPlaneadaController;
use Illuminate\Support\Facades\Route;

// Versão Actual (ADR-071) — rotas do agente ff_activos; incluído por routes/api.php no grupo com empresa activa.
// Fase F: Edifícios e Locais, folhas de inventário físico (js/activos_edificios.js) e manutenção planeada — planos,
// ordens, necessidades e requisições ao armazém (js/activos_manutencao.js).

Route::prefix('ativos')->name('ativos.')->group(function () {
    Route::controller(LocaisAtivosController::class)->group(function () {
        Route::get('edificios', 'edificios')->name('edificios.index');
        Route::get('locais/opcoes', 'opcoes')->name('locais.opcoes');
        Route::get('edificios/{edificio}/planta', 'planta')->whereNumber('edificio')->name('edificios.planta');
        Route::post('edificios/{edificio}/andares', 'criarAndar')->whereNumber('edificio')->name('andares.store');
        Route::put('andares/{andar}', 'actualizarAndar')->whereNumber('andar')->name('andares.update');
        Route::delete('andares/{andar}', 'eliminarAndar')->whereNumber('andar')->name('andares.destroy');
        Route::post('edificios/{edificio}/portas', 'criarPortas')->whereNumber('edificio')->name('portas.store');
        Route::put('portas/{porta}', 'guardarPorta')->whereNumber('porta')->name('portas.update');
        Route::delete('portas/{porta}', 'eliminarPorta')->whereNumber('porta')->name('portas.destroy');
        Route::post('bens/{bem}/localizacao', 'moverBem')->whereNumber('bem')->name('bens.localizacao');

        Route::get('inventarios-fisicos', 'inventarios')->name('inventarios_fisicos.index');
        Route::post('edificios/{edificio}/inventarios-fisicos', 'gerarInventario')->whereNumber('edificio')->name('inventarios_fisicos.store');
        Route::get('inventarios-fisicos/{inventario}', 'inventario')->whereNumber('inventario')->name('inventarios_fisicos.show');
        Route::get('inventarios-fisicos/{inventario}/comparacao', 'comparacao')->whereNumber('inventario')->name('inventarios_fisicos.comparacao');
        Route::put('inventarios-fisicos/{inventario}/contagem', 'gravarContagem')->whereNumber('inventario')->name('inventarios_fisicos.contagem');
        Route::post('inventarios-fisicos/{inventario}/extras', 'acrescentarExtra')->whereNumber('inventario')->name('inventarios_fisicos.extras');
        Route::delete('inventarios-fisicos/linhas/{linha}', 'removerExtra')->whereNumber('linha')->name('inventarios_fisicos.linhas.destroy');
        Route::post('inventarios-fisicos/{inventario}/aplicar', 'aplicar')->whereNumber('inventario')->name('inventarios_fisicos.aplicar');
        Route::post('inventarios-fisicos/{inventario}/fechar', 'fechar')->whereNumber('inventario')->name('inventarios_fisicos.fechar');
        Route::post('inventarios-fisicos/{inventario}/anular', 'anular')->whereNumber('inventario')->name('inventarios_fisicos.anular');
    });

    Route::prefix('manutencao')->name('manutencao.')->controller(ManutencaoPlaneadaController::class)->group(function () {
        Route::get('resumo', 'resumo')->name('resumo');
        Route::get('artigos', 'artigos')->name('artigos');
        Route::get('armazens', 'armazens')->name('armazens');

        Route::get('planos', 'planos')->name('planos.index');
        Route::post('planos', 'criarPlano')->name('planos.store');
        Route::put('planos/{plano}', 'actualizarPlano')->whereNumber('plano')->name('planos.update');
        Route::post('planos/{plano}/alternar', 'alternarPlano')->whereNumber('plano')->name('planos.alternar');
        Route::delete('planos/{plano}', 'eliminarPlano')->whereNumber('plano')->name('planos.destroy');
        Route::post('planos/{plano}/ordens', 'ordensDoPlano')->whereNumber('plano')->name('planos.ordens');

        Route::post('ordens/gerar', 'gerarOrdens')->name('ordens.gerar');
        Route::get('ordens', 'ordens')->name('ordens.index');
        Route::post('ordens', 'criarOrdem')->name('ordens.store');
        Route::get('ordens/{ordem}', 'ordem')->whereNumber('ordem')->name('ordens.show');
        Route::post('ordens/{ordem}/iniciar', 'iniciar')->whereNumber('ordem')->name('ordens.iniciar');
        Route::put('ordens/{ordem}/tarefas', 'tarefas')->whereNumber('ordem')->name('ordens.tarefas');
        Route::post('ordens/{ordem}/itens', 'acrescentarItem')->whereNumber('ordem')->name('ordens.itens.store');
        Route::delete('itens/{item}', 'retirarItem')->whereNumber('item')->name('itens.destroy');
        Route::post('ordens/{ordem}/concluir', 'concluir')->whereNumber('ordem')->name('ordens.concluir');
        Route::post('ordens/{ordem}/cancelar', 'cancelar')->whereNumber('ordem')->name('ordens.cancelar');
        Route::get('ordens/{ordem}/plano-requisicao', 'planoRequisicao')->whereNumber('ordem')->name('ordens.plano_requisicao');
        Route::post('ordens/{ordem}/requisitar', 'requisitar')->whereNumber('ordem')->name('ordens.requisitar');

        Route::get('requisicoes', 'listarRequisicoes')->name('requisicoes.index');
        Route::get('requisicoes/{requisicao}', 'requisicao')->whereNumber('requisicao')->name('requisicoes.show');
        Route::post('requisicoes/{requisicao}/entregar', 'entregar')->whereNumber('requisicao')->name('requisicoes.entregar');
        Route::post('requisicoes/{requisicao}/cancelar', 'cancelarRequisicao')->whereNumber('requisicao')->name('requisicoes.cancelar');
    });
});
