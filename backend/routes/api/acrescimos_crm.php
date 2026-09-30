<?php

// Incluído por routes/api.php dentro do grupo autenticado com empresa activa (prefixo /api).

use App\Http\Controllers\Api\Acrescimos\AcrescimosController;
use App\Http\Controllers\Api\CRM\AtividadesCRMController;
use App\Http\Controllers\Api\CRM\ConfiguracaoCRMController;
use App\Http\Controllers\Api\CRM\ContasCRMController;
use App\Http\Controllers\Api\CRM\OportunidadesCRMController;
use Illuminate\Support\Facades\Route;

// Acréscimos e diferimentos (ADR-053)
Route::prefix('acrescimos')->name('acrescimos.')->controller(AcrescimosController::class)->group(function () {
    Route::get('definicoes', 'obterDefinicoes')->name('definicoes.show');
    Route::put('definicoes', 'guardarDefinicoes')->name('definicoes.update');
    Route::get('itens', 'itens')->name('itens.index');
    Route::post('itens', 'guardarItem')->name('itens.store');
    Route::get('itens/{item}', 'item')->whereNumber('item')->name('itens.show');
    Route::put('itens/{item}', 'guardarItem')->whereNumber('item')->name('itens.update');
    Route::delete('itens/{item}', 'eliminarItem')->whereNumber('item')->name('itens.destroy');
    Route::post('itens/{item}/regularizar', 'regularizar')->whereNumber('item')->name('itens.regularizar');
    Route::post('itens/{item}/terminar', 'terminar')->whereNumber('item')->name('itens.terminar');
    Route::post('itens/{item}/desfazer-pedido', 'desfazerPedido')->whereNumber('item')->name('itens.desfazer');
    Route::post('quotas', 'simularQuotas')->name('quotas');
    Route::get('proposta', 'obterProposta')->name('proposta.show');
    Route::post('proposta/contabilizar', 'contabilizar')->name('proposta.contabilizar');
    Route::get('lancamentos', 'lancamentos')->name('lancamentos.index');
    Route::post('lancamentos/{lancamento}/descontabilizar', 'descontabilizar')->whereNumber('lancamento')->name('lancamentos.descontabilizar');
    Route::get('reconciliacao', 'reconciliacao')->name('reconciliacao');
    Route::get('recolha', 'candidatos')->name('recolha.index');
    Route::get('recolha/acrescimos-abertos', 'acrescimosAbertos')->name('recolha.abertos');
});

// CRM (ADR-054)
Route::prefix('crm')->name('crm.')->group(function () {
    Route::controller(ConfiguracaoCRMController::class)->group(function () {
        Route::get('configuracao', 'obterConfiguracao')->name('configuracao.show');
        Route::put('configuracao', 'guardarConfiguracao')->name('configuracao.update');
        Route::get('funis', 'funis')->name('funis.index');
        Route::post('funis', 'guardarFunil')->name('funis.store');
        Route::get('funis/{funil}', 'funil')->whereNumber('funil')->name('funis.show');
        Route::put('funis/{funil}', 'guardarFunil')->whereNumber('funil')->name('funis.update');
        Route::delete('funis/{funil}', 'eliminarFunil')->whereNumber('funil')->name('funis.destroy');
        Route::get('modelos-email', 'modelos')->name('modelos.index');
        Route::post('modelos-email', 'guardarModelo')->name('modelos.store');
        Route::put('modelos-email/{modelo}', 'guardarModelo')->whereNumber('modelo')->name('modelos.update');
        Route::delete('modelos-email/{modelo}', 'eliminarModelo')->whereNumber('modelo')->name('modelos.destroy');
        Route::get('sequencias', 'sequencias')->name('sequencias.index');
        Route::post('sequencias', 'guardarSequencia')->name('sequencias.store');
        Route::put('sequencias/{sequencia}', 'guardarSequencia')->whereNumber('sequencia')->name('sequencias.update');
        Route::delete('sequencias/{sequencia}', 'eliminarSequencia')->whereNumber('sequencia')->name('sequencias.destroy');
    });
    Route::controller(ContasCRMController::class)->group(function () {
        Route::get('contas', 'index')->name('contas.index');
        Route::post('contas', 'guardar')->name('contas.store');
        Route::post('contas/do-cliente', 'doCliente')->name('contas.do-cliente');
        Route::get('contas/{conta}', 'show')->whereNumber('conta')->name('contas.show');
        Route::put('contas/{conta}', 'guardar')->whereNumber('conta')->name('contas.update');
        Route::post('contas/{conta}/converter-em-cliente', 'converterEmCliente')->whereNumber('conta')->name('contas.converter');
        Route::get('contas/{conta}/contactos', 'contactos')->whereNumber('conta')->name('contactos.index');
        Route::post('contas/{conta}/contactos', 'guardarContacto')->whereNumber('conta')->name('contactos.store');
        Route::put('contas/{conta}/contactos/{contacto}', 'guardarContacto')->whereNumber(['conta', 'contacto'])->name('contactos.update');
        Route::delete('contas/{conta}/contactos/{contacto}', 'eliminarContacto')->whereNumber(['conta', 'contacto'])->name('contactos.destroy');
    });
    Route::controller(OportunidadesCRMController::class)->group(function () {
        Route::get('oportunidades', 'index')->name('oportunidades.index');
        Route::post('oportunidades', 'guardar')->name('oportunidades.store');
        Route::get('oportunidades/{oportunidade}', 'show')->whereNumber('oportunidade')->name('oportunidades.show');
        Route::put('oportunidades/{oportunidade}', 'guardar')->whereNumber('oportunidade')->name('oportunidades.update');
        Route::delete('oportunidades/{oportunidade}', 'eliminar')->whereNumber('oportunidade')->name('oportunidades.destroy');
        Route::post('oportunidades/{oportunidade}/etapa', 'moverEtapa')->whereNumber('oportunidade')->name('oportunidades.etapa');
        Route::get('oportunidades/{oportunidade}/conversao', 'conversao')->whereNumber('oportunidade')->name('oportunidades.conversao');
        Route::post('oportunidades/{oportunidade}/documentos', 'ligarVenda')->whereNumber('oportunidade')->name('oportunidades.documentos');
        Route::get('funis/{funil}/quadro', 'quadro')->whereNumber('funil')->name('funis.quadro');
        Route::get('previsao', 'previsao')->name('previsao');
        Route::get('indicadores', 'indicadores')->name('indicadores');
    });
    Route::controller(AtividadesCRMController::class)->group(function () {
        Route::get('atividades', 'index')->name('atividades.index');
        Route::post('atividades', 'guardar')->name('atividades.store');
        Route::put('atividades/{atividade}', 'guardar')->whereNumber('atividade')->name('atividades.update');
        Route::delete('atividades/{atividade}', 'eliminar')->whereNumber('atividade')->name('atividades.destroy');
        Route::post('atividades/{atividade}/concluir', 'concluir')->whereNumber('atividade')->name('atividades.concluir');
        Route::post('atividades/{atividade}/reabrir', 'reabrir')->whereNumber('atividade')->name('atividades.reabrir');
        Route::get('agenda', 'agenda')->name('agenda');
        Route::post('emails/preparar', 'prepararEmail')->name('emails.preparar');
        Route::post('emails', 'enviarEmail')->name('emails.enviar');
        Route::get('campanhas/destinatarios', 'destinatarios')->name('campanhas.destinatarios');
        Route::post('campanhas/enviar', 'enviarCampanha')->name('campanhas.enviar');
    });
});
