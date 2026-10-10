<?php

// Incluído por routes/api.php dentro do grupo autenticado com empresa activa (prefixo /api).
// Paridade visual e funcional com o sistema anterior (verificacao_visual/RELATORIO.md, secções 7 e 8):
// Tesouraria, Contabilidade (lançamentos, mapas, rotinas, tabelas) e Acréscimos e Diferimentos.
// As autorizações (permissões do catálogo) são verificadas nos controladores/serviços, como no resto da API.

use App\Http\Controllers\Api\Contabilidade\LancamentoController;
use App\Http\Controllers\Api\Tesouraria\ParidadeTesourariaController;
use Illuminate\Support\Facades\Route;

// --- Tesouraria (7.*) ---------------------------------------------------------------------------------------------
Route::prefix('tesouraria')->name('tesouraria.')->controller(ParidadeTesourariaController::class)->group(function () {
    Route::get('resumo', 'resumo')->name('resumo');                                                                         // 7.0.1 indicadores
    Route::get('reparacao/integracoes', 'integracoesOrfas')->middleware('throttle:pesado')->name('reparacao.integracoes');    // 7.4.1 pré-visualização
    Route::post('reparacao/integracoes', 'reporIntegracoes')->middleware('throttle:pesado')->name('reparacao.integracoes.repor');
});

// --- Contabilidade — Lançamentos (8.1.*) ---------------------------------------------------------------------------
Route::prefix('contabilidade/lancamentos')->name('contabilidade.lancamentos.')->controller(LancamentoController::class)->group(function () {
    Route::post('descontabilizar', 'descontabilizar')->middleware('throttle:pesado')->name('descontabilizar');              // 8.1.1 Descontabilizar Selecionados
    Route::post('classificacao/previsao', 'previsaoClassificacao')->middleware('throttle:pesado')->name('classificacao.previsao'); // 8.1.1 Alterar em massa (n.º de linhas)
    Route::get('transferir/destinos', 'destinosTransferencia')->name('transferir.destinos');                                 // 8.1.3 empresas de destino
    Route::get('{lancamento}/origem', 'origem')->whereNumber('lancamento')->name('origem');                                  // 8.1.3 documento de origem
    Route::post('{lancamento}/corrigir', 'corrigir')->whereNumber('lancamento')->name('corrigir');                           // 8.1.3 editar lançamento completo
});

// --- Contabilidade — Mapas, Rotinas, Relatório e Contas, Tabelas auxiliares, Acréscimos e Diferimentos (8.2–8.7) ------
