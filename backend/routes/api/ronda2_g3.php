<?php

use App\Http\Controllers\Api\Compras\ComprasController;
use App\Http\Controllers\Api\Logistica\ImportacaoProdutosController;
use App\Http\Controllers\Api\Sistema\ToleranciaCambioController;
use App\Http\Controllers\Api\Tesouraria\ImportacaoTesourariaController;
use App\Http\Controllers\Api\Tesouraria\OperacoesTesourariaController;
use App\Http\Controllers\Api\Vendas\DocumentoVendaController;
use App\Http\Controllers\Api\Vendas\ReciboVendaController;
use Illuminate\Support\Facades\Route;

// Incluído por routes/api.php dentro do grupo autenticado com empresa activa (prefixo /api).
// Ronda 2 da paridade (R2-G3): Vendas, Compras, Tesouraria, Armazém, Projectos e CRM.

Route::prefix('sistema/cambios-manuais')->name('sistema.cambios_manuais.')->controller(ToleranciaCambioController::class)->group(function () {
    Route::get('tolerancia', 'show')->name('tolerancia.show');            // decisão 9
    Route::put('tolerancia', 'update')->name('tolerancia.update');
    Route::post('validar', 'validar')->name('validar');
});

Route::prefix('compras')->name('compras.')->controller(ComprasController::class)->group(function () {
    Route::put('propostas/{id}/iva', 'ivaProposta')->whereNumber('id')->name('propostas.iva');      // M-17
    Route::put('encomendas/{id}/iva', 'ivaEncomenda')->whereNumber('id')->name('encomendas.iva');
});

Route::prefix('tesouraria')->name('tesouraria.')->group(function () {
    // A-12: importação com o modelo do legado; acções em lote sobre a selecção
    Route::get('documentos/modelo-importacao', [ImportacaoTesourariaController::class, 'modelo'])->name('documentos.modelo_importacao');
    Route::post('documentos/importar', [ImportacaoTesourariaController::class, 'importar'])->middleware('throttle:pesado')->name('documentos.importar');
    Route::post('documentos/anular', [ImportacaoTesourariaController::class, 'anularLote'])->name('documentos.anular_lote');
    Route::post('documentos/desintegrar', [ImportacaoTesourariaController::class, 'desintegrarLote'])->name('documentos.desintegrar_lote');
    // M-08: reconciliação bancária — histórico, detalhe, rascunhos e edição do extracto
    Route::controller(OperacoesTesourariaController::class)->group(function () {
        Route::get('reconciliacao/historico', 'historicoReconciliacoes')->name('reconciliacao.historico');
        Route::get('reconciliacao/rascunhos', 'rascunhos')->name('reconciliacao.rascunhos.index');
        Route::post('reconciliacao/rascunhos', 'gravarRascunho')->name('reconciliacao.rascunhos.store');
        Route::put('reconciliacao/rascunhos/{id}', 'gravarRascunho')->whereNumber('id')->name('reconciliacao.rascunhos.update');
        Route::delete('reconciliacao/rascunhos/{id}', 'eliminarRascunho')->whereNumber('id')->name('reconciliacao.rascunhos.destroy');
        Route::get('reconciliacao/{codigo}/detalhe', 'detalheReconciliacao')->where('codigo', '[A-Za-z0-9_\-]+')->name('reconciliacao.detalhe');
        Route::put('extrato/{id}', 'editarLinhaExtrato')->whereNumber('id')->name('extrato.update');
    });
});

Route::prefix('logistica/importacao')->name('logistica.importacao.')->controller(ImportacaoProdutosController::class)->group(function () {
    Route::get('{entidade}/modelo', 'modelo')->whereIn('entidade', ['produtos', 'categorias'])->name('modelo');     // M-07
    Route::post('{entidade}', 'importar')->whereIn('entidade', ['produtos', 'categorias'])->middleware('throttle:pesado')->name('importar');
});

Route::prefix('vendas')->name('vendas.')->group(function () {
    Route::post('documentos/faturar-guias', [DocumentoVendaController::class, 'faturarGuias'])->name('documentos.faturar_guias');        // M-18
    Route::post('documentos/contabilizar', [DocumentoVendaController::class, 'contabilizarLote'])->name('documentos.contabilizar_lote');   // M-06
    Route::post('documentos/descontabilizar', [DocumentoVendaController::class, 'descontabilizarLote'])->name('documentos.descontabilizar_lote');
    Route::post('recibos/contabilizar', [ReciboVendaController::class, 'contabilizarLote'])->name('recibos.contabilizar_lote');
    Route::post('recibos/descontabilizar', [ReciboVendaController::class, 'descontabilizarLote'])->name('recibos.descontabilizar_lote');
    Route::post('recibos/{recibo}/alocar', [ReciboVendaController::class, 'alocar'])->whereNumber('recibo')->name('recibos.alocar');      // M-18
});
