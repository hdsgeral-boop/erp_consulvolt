<?php

// Incluído por routes/api.php dentro do grupo autenticado com empresa activa (prefixo /api).
// Ronda 4 — agente r4_agt: ligação de cada empresa à AGT (credenciais e chaves cifradas, webhook), monitorização do
// envio, registo de comunicações, documentos de correcção (E46) e documentos integrados no SAF-T.

use App\Http\Controllers\Api\Vendas\LigacaoAgtController;
use Illuminate\Support\Facades\Route;

Route::prefix('vendas')->name('vendas.')->controller(LigacaoAgtController::class)->group(function () {
    Route::get('faturacao-eletronica/ligacao-empresa', 'obter')->name('fe.ligacao-empresa');
    Route::put('faturacao-eletronica/ligacao-empresa', 'gravar')->name('fe.ligacao-empresa.gravar');
    Route::put('faturacao-eletronica/ligacao-empresa/credenciais', 'gravarCredenciais')->name('fe.ligacao-empresa.credenciais');
    Route::delete('faturacao-eletronica/ligacao-empresa/credenciais', 'removerCredenciais')->name('fe.ligacao-empresa.credenciais.remover');
    Route::post('faturacao-eletronica/ligacao-empresa/chave', 'gravarChave')->name('fe.ligacao-empresa.chave');
    Route::delete('faturacao-eletronica/ligacao-empresa/chave', 'removerChave')->name('fe.ligacao-empresa.chave.remover');
    Route::post('faturacao-eletronica/ligacao-empresa/webhook', 'gerarWebhook')->name('fe.ligacao-empresa.webhook');
    Route::delete('faturacao-eletronica/ligacao-empresa/webhook', 'desativarWebhook')->name('fe.ligacao-empresa.webhook.remover');
    Route::post('faturacao-eletronica/ligacao-empresa/testar', 'testar')->name('fe.ligacao-empresa.testar');
    Route::get('faturacao-eletronica/monitor', 'monitor')->name('fe.monitor');
    Route::get('faturacao-eletronica/comunicacoes', 'comunicacoes')->name('fe.comunicacoes');
    Route::post('documentos/{venda}/correccao-agt', 'corrigir')->whereNumber('venda')->name('documentos.correccao-agt');
    Route::get('saft/documentos-importados', 'documentosImportados')->name('saft.documentos-importados');
});
