<?php

use App\Http\Controllers\Api\Simulacoes\PreVisualizacaoContabilizacaoController;
use Illuminate\Support\Facades\Route;

// Incluído por routes/api.php dentro do grupo autenticado com empresa activa (prefixo /api).
// Simulações e pré-visualizações (nada é gravado): lançamento a gerar antes de contabilizar (vendas e compras).

Route::controller(PreVisualizacaoContabilizacaoController::class)->group(function () {
    Route::get('vendas/documentos/{venda}/contabilizacao/pre-visualizacao', 'venda')->whereNumber('venda')->name('vendas.documentos.contabilizacao.previsualizacao');
    Route::get('compras/faturas/{id}/contabilizacao/pre-visualizacao', 'faturaCompra')->whereNumber('id')->name('compras.faturas.contabilizacao.previsualizacao');
});
