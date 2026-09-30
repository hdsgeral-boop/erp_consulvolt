<?php

use App\Http\Controllers\Api\Autenticacao\AutenticacaoController;
use App\Http\Controllers\Api\Compras\ComprasController;
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
use App\Http\Controllers\Api\Tesouraria\TesourariaController;
use App\Http\Controllers\Api\Vendas\ConfigVendasController;
use App\Http\Controllers\Api\Vendas\DocumentoVendaController;
use App\Http\Controllers\Api\Vendas\FaturacaoEletronicaController;
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
            Route::post('configuracao/series', [FaturacaoEletronicaController::class, 'criarSerie'])->name('configuracao.series.criar');
            Route::put('configuracao/series/{serie}', [FaturacaoEletronicaController::class, 'atualizarSerie'])->whereNumber('serie')->name('configuracao.series.atualizar');
            Route::delete('configuracao/series/{serie}', [FaturacaoEletronicaController::class, 'eliminarSerie'])->whereNumber('serie')->name('configuracao.series.eliminar');
            Route::post('configuracao/series/{serie}/solicitar-agt', [FaturacaoEletronicaController::class, 'solicitarSerieAgt'])->whereNumber('serie')->name('configuracao.series.solicitar-agt');

            // Facturação electrónica AGT (ADR-030)
            Route::get('faturacao-eletronica/configuracao', [FaturacaoEletronicaController::class, 'configuracao'])->name('fe.configuracao');
            Route::put('faturacao-eletronica/configuracao', [FaturacaoEletronicaController::class, 'gravarConfiguracao'])->name('fe.configuracao.gravar');
            Route::get('faturacao-eletronica/ligacao', [FaturacaoEletronicaController::class, 'ligacao'])->name('fe.ligacao');
            Route::get('faturacao-eletronica/resumo', [FaturacaoEletronicaController::class, 'resumo'])->name('fe.resumo');
            Route::post('faturacao-eletronica/enviar', [FaturacaoEletronicaController::class, 'enviar'])->name('fe.enviar');
            Route::post('faturacao-eletronica/consultar', [FaturacaoEletronicaController::class, 'consultar'])->name('fe.consultar');
            Route::post('documentos/{venda}/revalidar', [FaturacaoEletronicaController::class, 'revalidar'])->whereNumber('venda')->name('documentos.revalidar');
            Route::get('documentos/{venda}/pedido-assinado', [FaturacaoEletronicaController::class, 'pedidoAssinado'])->whereNumber('venda')->name('documentos.pedido-assinado');
            Route::get('documentos/{venda}/qr', [FaturacaoEletronicaController::class, 'qr'])->whereNumber('venda')->name('documentos.qr');
            Route::get('saft', [FaturacaoEletronicaController::class, 'saft'])->name('saft');
        });

        Route::prefix('compras')->name('compras.')->controller(ComprasController::class)->group(function () {
            Route::get('pedidos', 'pedidos')->name('pedidos.index');
            Route::post('pedidos', 'criarPedido')->name('pedidos.store');
            Route::get('pedidos/{id}', 'pedido')->whereNumber('id')->name('pedidos.show');
            Route::post('pedidos/{id}/decidir', 'decidirPedido')->whereNumber('id')->name('pedidos.decidir');
            Route::post('pedidos/{id}/anular', 'anularPedido')->whereNumber('id')->name('pedidos.anular');
            Route::get('pedidos/{id}/comparacao', 'compararPropostas')->whereNumber('id')->name('pedidos.comparacao');
            Route::get('deliberacao/escaloes', 'escaloes')->name('deliberacao.escaloes');
            Route::put('deliberacao/escaloes', 'definirEscaloes')->name('deliberacao.escaloes.definir');

            Route::get('propostas', 'propostas')->name('propostas.index');
            Route::post('propostas', 'criarProposta')->name('propostas.store');
            Route::get('propostas/{id}', 'proposta')->whereNumber('id')->name('propostas.show');
            Route::post('propostas/{id}/propor', 'proporAdjudicacao')->whereNumber('id')->name('propostas.propor');
            Route::post('propostas/{id}/cancelar-proposta', 'cancelarProposta')->whereNumber('id')->name('propostas.cancelar');
            Route::post('propostas/{id}/adjudicar', 'adjudicar')->whereNumber('id')->name('propostas.adjudicar');
            Route::post('propostas/{id}/anular', 'anularProposta')->whereNumber('id')->name('propostas.anular');

            Route::get('encomendas', 'encomendas')->name('encomendas.index');
            Route::get('encomendas/{id}', 'encomenda')->whereNumber('id')->name('encomendas.show');
            Route::post('encomendas/{id}/anular', 'anularEncomenda')->whereNumber('id')->name('encomendas.anular');
            Route::post('encomendas/{encomenda}/rececoes', 'registarRececao')->whereNumber('encomenda')->name('rececoes.store');
            Route::post('encomendas/{encomenda}/faturas', 'faturarEncomenda')->whereNumber('encomenda')->name('faturas.encomenda');

            Route::get('rececoes', 'rececoes')->name('rececoes.index');
            Route::get('rececoes/{id}', 'rececao')->whereNumber('id')->name('rececoes.show');
            Route::post('rececoes/{id}/validar', 'validarRececao')->whereNumber('id')->name('rececoes.validar');
            Route::post('rececoes/{id}/reverter-validacao', 'reverterRececao')->whereNumber('id')->name('rececoes.reverter');
            Route::post('rececoes/{id}/anular', 'anularRececao')->whereNumber('id')->name('rececoes.anular');

            Route::get('faturas', 'faturasLista')->name('faturas.index');
            Route::post('faturas', 'criarFaturaDireta')->name('faturas.store');
            Route::get('faturas/{id}', 'fatura')->whereNumber('id')->name('faturas.show');
            Route::post('faturas/{id}/anular', 'anularFatura')->whereNumber('id')->name('faturas.anular');
            Route::post('faturas/{id}/contabilizar', 'contabilizarFatura')->whereNumber('id')->name('faturas.contabilizar');
            Route::post('faturas/{id}/descontabilizar', 'descontabilizarFatura')->whereNumber('id')->name('faturas.descontabilizar');

            Route::get('configuracao/contas', 'contas')->name('configuracao.contas');
            Route::put('configuracao/contas', 'definirContas')->name('configuracao.contas.definir');
        });

        Route::prefix('tesouraria')->name('tesouraria.')->controller(TesourariaController::class)->group(function () {
            Route::get('documentos', 'index')->name('documentos.index');
            Route::post('documentos', 'store')->name('documentos.store');
            Route::get('documentos/{id}', 'show')->whereNumber('id')->name('documentos.show');
            Route::put('documentos/{id}', 'update')->whereNumber('id')->name('documentos.update');
            Route::post('documentos/{id}/anular', 'anular')->whereNumber('id')->name('documentos.anular');
            Route::post('documentos/{id}/integrar', 'integrar')->whereNumber('id')->name('documentos.integrar');
            Route::post('documentos/{id}/desintegrar', 'desintegrar')->whereNumber('id')->name('documentos.desintegrar');
            Route::get('pendentes', 'pendentes')->name('pendentes');
            Route::get('meios-pagamento', 'meios')->name('meios.index');
            Route::post('meios-pagamento', 'gravarMeio')->name('meios.store');
            Route::put('meios-pagamento/{id}', 'gravarMeio')->whereNumber('id')->name('meios.update');
            Route::delete('meios-pagamento/{id}', 'eliminarMeio')->whereNumber('id')->name('meios.destroy');
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
