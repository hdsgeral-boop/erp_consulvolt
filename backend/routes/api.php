<?php

use App\Http\Controllers\Api\Autenticacao\AutenticacaoController;
use App\Http\Controllers\Api\Contabilidade\DiarioController;
use App\Http\Controllers\Api\Contabilidade\LancamentoController;
use App\Http\Controllers\Api\Contabilidade\PlanoContasController;
use App\Http\Controllers\Api\Contabilidade\RelatorioContabilController;
use App\Http\Controllers\Api\Logistica\CategoriaProdutoController;
use App\Http\Controllers\Api\Logistica\ProdutoController;
use App\Http\Controllers\Api\SaudeController;
use App\Http\Controllers\Api\Sistema\EmpresaController;
use App\Http\Controllers\Api\Sistema\LogAuditoriaController;
use App\Http\Controllers\Api\Sistema\ValidacaoDadosController;
use App\Http\Controllers\Api\Terceiros\TerceiroController;
use App\Http\Controllers\Api\Vendas\ConfigVendasController;
use App\Http\Controllers\Api\Vendas\DocumentoVendaController;
use App\Http\Controllers\Api\Vendas\ReciboVendaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API REST — ERP Consulvolt (prefixo /api)
|--------------------------------------------------------------------------
| Convenção (directiva, secção 4):
|   GET    /api/{modulo}/{recurso}            listagem paginada com filtros
|   POST   /api/{modulo}/{recurso}            criação (FormRequest)
|   GET    /api/{modulo}/{recurso}/{id}       detalhe
|   PUT    /api/{modulo}/{recurso}/{id}       actualização
|   DELETE /api/{modulo}/{recurso}/{id}       remoção lógica / estorno
|   POST   /api/{modulo}/{recurso}/{id}/acao  operação de negócio (anular, contabilizar, liquidar…)
|
| Middleware: auth:sanctum (token Bearer) · empresa (cabeçalho X-Empresa-Id obrigatório).
*/

Route::get('saude', SaudeController::class)->name('saude');

Route::prefix('autenticacao')->name('autenticacao.')->group(function () {
    Route::post('entrar', [AutenticacaoController::class, 'entrar'])->middleware('throttle:entrar')->name('entrar');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('sair', [AutenticacaoController::class, 'sair'])->name('sair');
        Route::get('eu', [AutenticacaoController::class, 'eu'])->name('eu');
    });
});

Route::middleware('auth:sanctum')->group(function () {

    // Rotas sem empresa activa (antes de escolher empresa)
    Route::prefix('sistema')->name('sistema.')->group(function () {
        Route::get('empresas', [EmpresaController::class, 'index'])->name('empresas.index');
        Route::get('empresas/{empresa}', [EmpresaController::class, 'show'])->whereNumber('empresa')->name('empresas.show');
    });

    // Rotas com empresa activa (X-Empresa-Id)
    Route::middleware('empresa')->group(function () {
        Route::prefix('sistema')->name('sistema.')->group(function () {
            Route::get('logs', [LogAuditoriaController::class, 'index'])->name('logs.index');
            Route::get('validacoes', [ValidacaoDadosController::class, 'index'])->name('validacoes.index');
            Route::get('validacoes/{codigo}', [ValidacaoDadosController::class, 'show'])->where('codigo', '[a-z_]+')->name('validacoes.show');
        });

        Route::prefix('terceiros')->name('terceiros.')->group(function () {
            Route::get('/', [TerceiroController::class, 'index'])->name('index');
            Route::post('/', [TerceiroController::class, 'store'])->name('store');
            Route::get('{terceiro}', [TerceiroController::class, 'show'])->whereNumber('terceiro')->name('show');
            Route::put('{terceiro}', [TerceiroController::class, 'update'])->whereNumber('terceiro')->name('update');
            Route::delete('{terceiro}', [TerceiroController::class, 'destroy'])->whereNumber('terceiro')->name('destroy');
        });

        Route::prefix('logistica')->name('logistica.')->group(function () {
            Route::get('produtos', [ProdutoController::class, 'index'])->name('produtos.index');
            Route::get('produtos/catalogo', [ProdutoController::class, 'catalogo'])->name('produtos.catalogo');
            Route::post('produtos', [ProdutoController::class, 'store'])->name('produtos.store');
            Route::get('produtos/{produto}', [ProdutoController::class, 'show'])->whereNumber('produto')->name('produtos.show');
            Route::put('produtos/{produto}', [ProdutoController::class, 'update'])->whereNumber('produto')->name('produtos.update');
            Route::post('produtos/{produto}/bloquear', [ProdutoController::class, 'bloquear'])->whereNumber('produto')->name('produtos.bloquear');
            Route::delete('produtos/{produto}', [ProdutoController::class, 'destroy'])->whereNumber('produto')->name('produtos.destroy');

            Route::get('categorias-produtos', [CategoriaProdutoController::class, 'index'])->name('categorias.index');
            Route::post('categorias-produtos', [CategoriaProdutoController::class, 'store'])->name('categorias.store');
            Route::put('categorias-produtos/{categoria}', [CategoriaProdutoController::class, 'update'])->whereNumber('categoria')->name('categorias.update');
            Route::delete('categorias-produtos/{categoria}', [CategoriaProdutoController::class, 'destroy'])->whereNumber('categoria')->name('categorias.destroy');
        });

        Route::prefix('vendas')->name('vendas.')->group(function () {
            Route::get('documentos', [DocumentoVendaController::class, 'index'])->name('documentos.index');
            Route::post('documentos', [DocumentoVendaController::class, 'store'])->name('documentos.store');
            Route::get('documentos/{venda}', [DocumentoVendaController::class, 'show'])->whereNumber('venda')->name('documentos.show');
            Route::post('documentos/{venda}/converter', [DocumentoVendaController::class, 'converter'])->whereNumber('venda')->name('documentos.converter');
            Route::post('documentos/{venda}/anular', [DocumentoVendaController::class, 'anular'])->whereNumber('venda')->name('documentos.anular');
            Route::post('documentos/{venda}/contabilizar', [DocumentoVendaController::class, 'contabilizar'])->whereNumber('venda')->name('documentos.contabilizar');
            Route::post('documentos/{venda}/descontabilizar', [DocumentoVendaController::class, 'descontabilizar'])->whereNumber('venda')->name('documentos.descontabilizar');

            Route::get('recibos', [ReciboVendaController::class, 'index'])->name('recibos.index');
            Route::post('recibos', [ReciboVendaController::class, 'store'])->name('recibos.store');
            Route::get('recibos/{recibo}', [ReciboVendaController::class, 'show'])->whereNumber('recibo')->name('recibos.show');
            Route::post('recibos/{recibo}/anular', [ReciboVendaController::class, 'anular'])->whereNumber('recibo')->name('recibos.anular');
            Route::post('recibos/{recibo}/contabilizar', [ReciboVendaController::class, 'contabilizar'])->whereNumber('recibo')->name('recibos.contabilizar');
            Route::post('recibos/{recibo}/descontabilizar', [ReciboVendaController::class, 'descontabilizar'])->whereNumber('recibo')->name('recibos.descontabilizar');

            Route::get('configuracao/contas', [ConfigVendasController::class, 'contas'])->name('configuracao.contas');
            Route::put('configuracao/contas', [ConfigVendasController::class, 'definirContas'])->name('configuracao.contas.definir');
            Route::get('configuracao/series', [ConfigVendasController::class, 'series'])->name('configuracao.series');
        });

        Route::prefix('contabilidade')->name('contabilidade.')->group(function () {
            Route::get('plano-contas', [PlanoContasController::class, 'index'])->name('plano-contas.index');
            Route::post('plano-contas', [PlanoContasController::class, 'store'])->name('plano-contas.store');
            Route::put('plano-contas/{conta}', [PlanoContasController::class, 'update'])->whereNumber('conta')->name('plano-contas.update');
            Route::delete('plano-contas/{conta}', [PlanoContasController::class, 'destroy'])->whereNumber('conta')->name('plano-contas.destroy');

            Route::get('diarios', [DiarioController::class, 'index'])->name('diarios.index');
            Route::post('diarios', [DiarioController::class, 'store'])->name('diarios.store');

            Route::get('lancamentos', [LancamentoController::class, 'index'])->name('lancamentos.index');
            Route::post('lancamentos', [LancamentoController::class, 'store'])->name('lancamentos.store');
            Route::get('lancamentos/{lancamento}', [LancamentoController::class, 'show'])->whereNumber('lancamento')->name('lancamentos.show');
            Route::post('lancamentos/{lancamento}/estornar', [LancamentoController::class, 'estornar'])->whereNumber('lancamento')->name('lancamentos.estornar');

            Route::get('relatorios/balancete', [RelatorioContabilController::class, 'balancete'])->name('relatorios.balancete');
            Route::get('relatorios/razao', [RelatorioContabilController::class, 'razao'])->name('relatorios.razao');
            Route::get('relatorios/desequilibrios', [RelatorioContabilController::class, 'desequilibrios'])->name('relatorios.desequilibrios');
        });
    });
});
