<?php

// Incluído por routes/api.php dentro do grupo autenticado com empresa activa (prefixo /api).
// POS parte 3a — lavandaria e alfaiataria (ADR-049).

use App\Http\Controllers\Api\POS\LavandariaController;
use Illuminate\Support\Facades\Route;

Route::prefix('pos/lavandaria')->name('pos.lavandaria.')->controller(LavandariaController::class)->group(function () {
    Route::get('definicoes', 'definicoes')->name('definicoes.show');
    Route::put('definicoes', 'guardarDefinicoes')->name('definicoes.update');
    Route::get('pecas', 'pecas')->name('pecas.index');
    Route::post('pecas', 'guardarPeca')->name('pecas.store');
    Route::put('pecas/{peca}', 'guardarPeca')->whereNumber('peca')->name('pecas.update');
    Route::get('servicos', 'servicos')->name('servicos.index');
    Route::post('servicos', 'guardarServico')->name('servicos.store');
    Route::put('servicos/{servico}', 'guardarServico')->whereNumber('servico')->name('servicos.update');

    Route::get('ordens', 'ordens')->name('ordens.index');
    Route::get('ordens/{ordem}', 'ordem')->whereNumber('ordem')->name('ordens.show');
    Route::post('ordens/estado', 'alterarEstado')->name('ordens.estado');
    Route::post('ordens/atribuir', 'atribuir')->name('ordens.atribuir');
    Route::post('ordens/{ordem}/linhas', 'accaoLinhas')->whereNumber('ordem')->name('ordens.linhas');
    Route::post('ordens/{ordem}/anular', 'anular')->whereNumber('ordem')->name('ordens.anular');
    Route::post('ordens/{ordem}/linhas/{linha}/materiais', 'material')->whereNumber(['ordem', 'linha'])->name('ordens.materiais');
    Route::post('ordens/{ordem}/linhas/{linha}/reclamacoes', 'registarReclamacao')->whereNumber(['ordem', 'linha'])->name('ordens.reclamacoes');
    Route::post('ordens/{ordem}/entrega/simulacao', 'simularEntrega')->whereNumber('ordem')->name('ordens.entrega.simulacao');
    Route::post('orcamentos', 'orcamentos')->name('orcamentos.decidir');

    // operações de caixa: na sessão aberta de um terminal de lavandaria
    Route::post('sessoes/{sessao}/ordens', 'registar')->whereNumber('sessao')->name('ordens.store');
    Route::post('sessoes/{sessao}/ordens/{ordem}/pagamentos', 'receber')->whereNumber(['sessao', 'ordem'])->name('ordens.receber');
    Route::post('sessoes/{sessao}/ordens/{ordem}/faturar', 'faturar')->whereNumber(['sessao', 'ordem'])->name('ordens.faturar');
    Route::post('sessoes/{sessao}/ordens/{ordem}/entregar', 'entregar')->whereNumber(['sessao', 'ordem'])->name('ordens.entregar');
    Route::post('pagamentos/{pagamento}/anular', 'anularRecibo')->whereNumber('pagamento')->name('pagamentos.anular');

    Route::get('reclamacoes', 'reclamacoesLista')->name('reclamacoes.index');
    Route::post('reclamacoes/{reclamacao}/decidir', 'decidirReclamacao')->whereNumber('reclamacao')->name('reclamacoes.decidir');
    Route::post('reclamacoes/{reclamacao}/pagar', 'pagarReclamacao')->whereNumber('reclamacao')->name('reclamacoes.pagar');

    Route::get('relatorio', 'relatorioPeriodo')->name('relatorio');
});
