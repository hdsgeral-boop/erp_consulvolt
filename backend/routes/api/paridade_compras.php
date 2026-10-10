<?php

use App\Http\Controllers\Api\Compras\ParidadeComprasController;
use App\Http\Controllers\Api\Terceiros\ParidadeTerceirosController;
use Illuminate\Support\Facades\Route;

// Incluído por routes/api/paridade_vendas_compras.php, dentro do grupo autenticado com empresa activa (prefixo /api).
// Paridade das listas de Compras (secção 5) e dos Terceiros — clientes 4.4 e fornecedores 5.6.1 — com o sistema anterior
// (verificacao_visual/RELATORIO.md, 2026-10-08).

Route::prefix('compras')->name('compras.')->controller(ParidadeComprasController::class)->group(function () {
    Route::get('fluxo-documental/{tipo}/{id}', 'fluxoDocumental')->whereIn('tipo', ['pedido', 'proposta', 'encomenda', 'rececao', 'fatura'])
        ->whereNumber('id')->name('fluxo_documental');                                                               // 5.1.1–5.5.1
    Route::post('encomendas/{id}/contrato', 'contratoEncomenda')->whereNumber('id')->name('encomendas.contrato');      // 5.3.1 «Associar»
    Route::put('faturas/{id}', 'editarFatura')->whereNumber('id')->name('faturas.update');                           // 5.5.1 «Editar Fatura»
});

Route::prefix('terceiros')->name('terceiros.')->controller(ParidadeTerceirosController::class)->group(function () {
    Route::post('edicao-massa', 'edicaoMassa')->name('edicao_massa');                                               // 4.4.3 / 5.6.1
    Route::get('importacao/modelo', 'modeloImportacao')->name('importacao.modelo');                                 // 4.4.3 «Importar»
    Route::post('importacao', 'importar')->middleware('throttle:pesado')->name('importacao');
});
