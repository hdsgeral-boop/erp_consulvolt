<?php

// Incluído por routes/api/paridade_geral_activos.php (grupo autenticado com empresa activa, prefixo /api).
// Orçamento — paridade com o sistema anterior (verificacao_visual/RELATORIO.md, secção 11). Só leituras.

use App\Http\Controllers\Api\Orcamento\ParidadeOrcamentoController;
use Illuminate\Support\Facades\Route;

Route::prefix('orcamento/paridade')->name('orcamento.paridade.')->controller(ParidadeOrcamentoController::class)->group(function () {
    Route::get('orcamentos', 'orcamentos')->name('orcamentos');
    Route::get('orcamentos/{orcamento}/desvios/{rubrica}', 'causas')->whereNumber(['orcamento', 'rubrica'])->name('causas');
});
