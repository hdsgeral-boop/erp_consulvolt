<?php

use App\Http\Controllers\Api\Autenticacao\AutenticacaoController;
use App\Http\Controllers\Api\Compras\ComprasController;
use App\Http\Controllers\Api\Compras\ContratosComprasController;
use App\Http\Controllers\Api\Contabilidade\DiarioController;
use App\Http\Controllers\Api\Contabilidade\LancamentoController;
use App\Http\Controllers\Api\Contabilidade\PlanoContasController;
use App\Http\Controllers\Api\Contabilidade\RelatorioContabilController;
use App\Http\Controllers\Api\Logistica\CategoriaProdutoController;
use App\Http\Controllers\Api\Logistica\ProdutoController;
use App\Http\Controllers\Api\Logistica\StockController;
use App\Http\Controllers\Api\Orcamento\OrcamentoController;
use App\Http\Controllers\Api\Orcamento\PlaneamentoOrcamentalController;
use App\Http\Controllers\Api\POS\POSController;
use App\Http\Controllers\Api\RH\AssiduidadeController;
use App\Http\Controllers\Api\RH\AvaliacaoController;
use App\Http\Controllers\Api\RH\CadastrosRHController;
use App\Http\Controllers\Api\RH\ColaboradorController;
use App\Http\Controllers\Api\RH\ContratoTrabalhoController;
use App\Http\Controllers\Api\RH\EstruturaController;
use App\Http\Controllers\Api\RH\FeriasProdutividadeController;
use App\Http\Controllers\Api\RH\FolhaSalarialController;
use App\Http\Controllers\Api\RH\PagamentoSalariosController;
use App\Http\Controllers\Api\RH\PortalColaboradorController;
use App\Http\Controllers\Api\SaudeController;
use App\Http\Controllers\Api\Sistema\ConfiguracaoSistemaController;
use App\Http\Controllers\Api\Sistema\EmpresaController;
use App\Http\Controllers\Api\Sistema\IdentidadeEmpresaController;
use App\Http\Controllers\Api\Sistema\LogAuditoriaController;
use App\Http\Controllers\Api\Sistema\MenuController;
use App\Http\Controllers\Api\Sistema\ValidacaoDadosController;
use App\Http\Controllers\Api\Terceiros\TerceiroController;
use App\Http\Controllers\Api\Tesouraria\OperacoesTesourariaController;
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

// sem o throttle da API (R5): o limitador usa o Redis e, com ele em baixo, a saúde respondia 500 em vez do 503 com o detalhe por componente
Route::get('saude', SaudeController::class)->withoutMiddleware('throttle:api')->name('saude');
Route::get('sistema/logotipo-login', [ConfiguracaoSistemaController::class, 'logotipoLogin'])->name('sistema.logotipo_login');   // ecrã de entrada (sem sessão), ADR-058
require __DIR__.'/api/bi_odata.php';   // feed OData do Power BI: autenticação por token de leitura da empresa (ronda 2, R2-G4)

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
            Route::get('menu', MenuController::class)->name('menu');
            Route::get('identidade', IdentidadeEmpresaController::class)->name('identidade');
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

            Route::controller(StockController::class)->group(function () {
                Route::get('armazens', 'armazens')->name('armazens.index');
                Route::post('armazens', 'guardarArmazem')->name('armazens.store');
                Route::put('armazens/{armazem}', 'guardarArmazem')->whereNumber('armazem')->name('armazens.update');
                Route::delete('armazens/{armazem}', 'eliminarArmazem')->whereNumber('armazem')->name('armazens.destroy');
                Route::get('stock', 'stock')->name('stock.index');
                Route::get('movimentos', 'movimentos')->name('movimentos.index');
                Route::get('produtos/{produto}/extracto', 'extracto')->whereNumber('produto')->name('produtos.extracto');
                Route::post('transferencias', 'transferir')->name('transferencias.store');
                Route::post('ajustes', 'ajustar')->name('ajustes.store');
                Route::get('guias-saida', 'guias')->name('guias.index');
                Route::post('guias-saida', 'emitirGuia')->name('guias.store');
                Route::get('guias-saida/{guia}', 'guia')->whereNumber('guia')->name('guias.show');
                Route::post('guias-saida/{guia}/contabilizar', 'contabilizarGuia')->whereNumber('guia')->name('guias.contabilizar');
                Route::post('guias-saida/{guia}/descontabilizar', 'descontabilizarGuia')->whereNumber('guia')->name('guias.descontabilizar');
                Route::post('guias-saida/{guia}/anular', 'anularGuia')->whereNumber('guia')->name('guias.anular');
                Route::get('configuracao/contas', 'contas')->name('configuracao.contas');
                Route::put('configuracao/contas', 'definirContas')->name('configuracao.contas.definir');
                Route::get('inventarios', 'sessoes')->name('inventarios.index');
                Route::post('inventarios', 'abrirSessao')->name('inventarios.store');
                Route::get('inventarios/{sessao}', 'sessao')->whereNumber('sessao')->name('inventarios.show');
                Route::post('inventarios/{sessao}/contagem', 'contar')->whereNumber('sessao')->name('inventarios.contar');
                Route::post('inventarios/{sessao}/concluir-contagem', 'concluirContagem')->whereNumber('sessao')->name('inventarios.concluir');
                Route::post('inventarios/{sessao}/revisao', 'rever')->whereNumber('sessao')->name('inventarios.rever');
                Route::post('inventarios/{sessao}/voltar-contagem', 'voltarContagem')->whereNumber('sessao')->name('inventarios.voltar');
                Route::post('inventarios/{sessao}/aprovar', 'aprovar')->whereNumber('sessao')->name('inventarios.aprovar');
                Route::post('inventarios/{sessao}/reabrir', 'reabrir')->whereNumber('sessao')->name('inventarios.reabrir');
                Route::post('inventarios/{sessao}/anular', 'anular')->whereNumber('sessao')->name('inventarios.anular');
            });
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

        Route::prefix('compras')->name('compras.')->controller(ContratosComprasController::class)->group(function () {
            Route::get('contratos', 'index')->name('contratos.index');
            Route::post('contratos', 'gravar')->name('contratos.store');
            Route::get('contratos/{id}', 'show')->whereNumber('id')->name('contratos.show');
            Route::put('contratos/{id}', 'gravar')->whereNumber('id')->name('contratos.update');
            Route::post('contratos/{id}/encomendas', 'associar')->whereNumber('id')->name('contratos.associar');
            Route::delete('contratos/{id}/encomendas/{encomenda}', 'desassociar')->whereNumber(['id', 'encomenda'])->name('contratos.desassociar');
            Route::post('contratos/{id}/marcos', 'gravarMarco')->whereNumber('id')->name('contratos.marcos.store');
            Route::put('contratos/{id}/marcos/{marco}', 'gravarMarco')->whereNumber(['id', 'marco'])->name('contratos.marcos.update');
            Route::delete('contratos/{id}/marcos/{marco}', 'eliminarMarco')->whereNumber(['id', 'marco'])->name('contratos.marcos.destroy');
            Route::post('contratos/{id}/marcos/{marco}/fatura', 'faturarMarco')->whereNumber(['id', 'marco'])->name('contratos.marcos.fatura');
            Route::post('contratos/{id}/cancelar', 'cancelar')->whereNumber('id')->name('contratos.cancelar');
            Route::get('encomendas-clientes', 'encomendasClientes')->name('encomendas-clientes.index');
            Route::post('encomendas-clientes/pedido', 'gerarPedido')->name('encomendas-clientes.pedido');
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

        Route::prefix('tesouraria')->name('tesouraria.')->controller(OperacoesTesourariaController::class)->group(function () {
            Route::get('extrato', 'extrato')->name('extrato.index');
            Route::post('extrato/importar', 'importarExtrato')->name('extrato.importar');
            Route::post('extrato/{id}/anular', 'anularLinhaExtrato')->whereNumber('id')->name('extrato.anular');
            Route::get('reconciliacao/sugestoes', 'sugestoes')->name('reconciliacao.sugestoes');
            Route::post('reconciliacao', 'confirmar')->name('reconciliacao.confirmar');
            Route::get('reconciliacao', 'reconciliacoes')->name('reconciliacao.index');
            Route::post('reconciliacao/{codigo}/anular', 'anularReconciliacao')->where('codigo', '[A-Za-z0-9_\-]+')->name('reconciliacao.anular');
            Route::get('reconciliacao/mapa', 'mapa')->name('reconciliacao.mapa');

            Route::get('caixa/sessoes', 'sessoes')->name('caixa.sessoes');
            Route::post('caixa/sessoes', 'abrirSessao')->name('caixa.abrir');
            Route::get('caixa/sessoes/{id}', 'sessao')->whereNumber('id')->name('caixa.sessao');
            Route::post('caixa/sessoes/{id}/movimentos', 'registarMovimento')->whereNumber('id')->name('caixa.movimento');
            Route::delete('caixa/sessoes/{id}/movimentos/{movimento}', 'removerMovimento')->whereNumber(['id', 'movimento'])->name('caixa.movimento.remover');
            Route::post('caixa/sessoes/{id}/fechar', 'fecharSessao')->whereNumber('id')->name('caixa.fechar');
            Route::post('caixa/sessoes/{id}/reabrir', 'reabrirSessao')->whereNumber('id')->name('caixa.reabrir');
            Route::put('caixa/sessoes/{id}/movimentos/classificacao', 'classificarMovimentos')->whereNumber('id')->name('caixa.movimentos.classificacao');
            Route::post('caixa/sessoes/{id}/contabilizar', 'contabilizarSessao')->whereNumber('id')->name('caixa.contabilizar');
            Route::post('caixa/sessoes/{id}/descontabilizar', 'descontabilizarSessao')->whereNumber('id')->name('caixa.descontabilizar');
            Route::delete('caixa/sessoes/{id}', 'eliminarSessao')->whereNumber('id')->name('caixa.eliminar');

            Route::get('conferencias', 'conferencias')->name('conferencias.index');
            Route::post('conferencias', 'gravarConferencia')->name('conferencias.store');
            Route::put('conferencias/{id}', 'gravarConferencia')->whereNumber('id')->name('conferencias.update');
            Route::post('conferencias/{id}/finalizar', 'finalizarConferencia')->whereNumber('id')->name('conferencias.finalizar');
            Route::post('conferencias/{id}/assinar', 'assinarConferencia')->whereNumber('id')->name('conferencias.assinar');
            Route::post('conferencias/{id}/reabrir', 'reabrirConferencia')->whereNumber('id')->name('conferencias.reabrir');

            Route::get('configuracao/contas', 'contas')->name('configuracao.contas');
            Route::put('configuracao/contas', 'definirContas')->name('configuracao.contas.definir');
        });

        Route::prefix('rh/salarios')->name('rh.salarios.')->controller(FolhaSalarialController::class)->group(function () {
            Route::get('periodos', 'periodos')->name('periodos.index');
            Route::post('periodos', 'abrir')->name('periodos.abrir');
            Route::get('periodos/{id}', 'periodo')->whereNumber('id')->name('periodos.show');
            Route::get('periodos/{id}/lancamentos', 'lancamentos')->whereNumber('id')->name('lancamentos.index');
            Route::post('periodos/{id}/lancamentos', 'gravarLancamento')->whereNumber('id')->name('lancamentos.store');
            Route::delete('periodos/{id}/lancamentos/{lancamento}', 'removerLancamento')->whereNumber(['id', 'lancamento'])->name('lancamentos.destroy');
            Route::post('periodos/{id}/importar-contratos', 'importarContratos')->whereNumber('id')->name('periodos.importar');
            Route::post('periodos/{id}/encerrar', 'encerrar')->whereNumber('id')->name('periodos.encerrar');
            Route::post('periodos/{id}/validar', 'validar')->whereNumber('id')->name('periodos.validar');
            Route::post('periodos/{id}/reabrir', 'reabrir')->whereNumber('id')->name('periodos.reabrir');
            Route::post('periodos/{id}/contabilizar', 'contabilizar')->whereNumber('id')->name('periodos.contabilizar');
            Route::post('periodos/{id}/descontabilizar', 'descontabilizar')->whereNumber('id')->name('periodos.descontabilizar');
            Route::get('periodos/{id}/recibos/{colaborador}', 'recibo')->whereNumber(['id', 'colaborador'])->name('recibos.show');
            Route::get('verificacao-legado', 'verificacaoLegado')->name('verificacao-legado');
        });

        Route::prefix('rh/salarios')->name('rh.salarios.')->controller(PagamentoSalariosController::class)->group(function () {
            Route::get('periodos/{id}/ordem-pagamento', 'ordem')->whereNumber('id')->name('ordem-pagamento');
            Route::post('periodos/{id}/cartas', 'emitir')->whereNumber('id')->name('cartas.emitir');
            Route::get('cartas', 'cartas')->name('cartas.index');
            Route::get('cartas/{carta}', 'carta')->whereNumber('carta')->name('cartas.show');
            Route::delete('cartas/{carta}', 'eliminar')->whereNumber('carta')->name('cartas.destroy');
            Route::post('cartas/{carta}/pagamento', 'pagar')->whereNumber('carta')->name('cartas.pagar');
        });

        Route::prefix('rh/assiduidade')->name('rh.assiduidade.')->controller(AssiduidadeController::class)->group(function () {
            Route::get('configuracao', 'config')->name('config');
            Route::put('configuracao', 'gravarConfig')->name('config.gravar');
            Route::get('registos', 'registos')->name('registos.index');
            Route::post('registos', 'gravarRegisto')->name('registos.gravar');
            Route::delete('registos/{registo}', 'eliminarRegisto')->whereNumber('registo')->name('registos.destroy');
            Route::post('registos/importar', 'importar')->name('registos.importar');
            Route::get('fechos', 'fechos')->name('fechos.index');
            Route::get('meses/{mes}', 'mes')->where('mes', '\d{4}-\d{2}')->name('meses.show');
            Route::post('meses/{mes}/detectar-faltas', 'detectar')->where('mes', '\d{4}-\d{2}')->name('meses.detectar');
            Route::post('meses/{mes}/fechar', 'fechar')->where('mes', '\d{4}-\d{2}')->name('meses.fechar');
            Route::post('meses/{mes}/reabrir', 'reabrir')->where('mes', '\d{4}-\d{2}')->name('meses.reabrir');
            Route::get('tipos-ausencia', 'catalogo')->name('ausencias.catalogo');
            Route::get('ausencias', 'ausencias')->name('ausencias.index');
            Route::post('ausencias', 'criarAusencia')->name('ausencias.store');
            Route::post('ausencias/{ausencia}/justificar', 'justificar')->whereNumber('ausencia')->name('ausencias.justificar');
            Route::post('ausencias/{ausencia}/decidir', 'decidir')->whereNumber('ausencia')->name('ausencias.decidir');
            Route::post('ausencias/{ausencia}/cancelar', 'cancelar')->whereNumber('ausencia')->name('ausencias.cancelar');
        });
        Route::post('rh/salarios/periodos/{id}/importar-efectividade', [AssiduidadeController::class, 'lancar'])->whereNumber('id')->name('rh.salarios.importar-efectividade');

        Route::prefix('rh')->name('rh.')->controller(FeriasProdutividadeController::class)->group(function () {
            Route::get('ferias', 'ferias')->name('ferias.index');
            Route::post('ferias', 'gravarFerias')->name('ferias.store');
            Route::put('ferias/{periodo}', 'gravarFerias')->whereNumber('periodo')->name('ferias.update');
            Route::post('ferias/{periodo}/estado', 'estadoFerias')->whereNumber('periodo')->name('ferias.estado');
            Route::delete('ferias/{periodo}', 'eliminarFerias')->whereNumber('periodo')->name('ferias.destroy');
            Route::get('produtividade/itens', 'itens')->name('produtividade.itens.index');
            Route::post('produtividade/itens', 'gravarItem')->name('produtividade.itens.store');
            Route::put('produtividade/itens/{item}', 'gravarItem')->whereNumber('item')->name('produtividade.itens.update');
            Route::delete('produtividade/itens/{item}', 'eliminarItem')->whereNumber('item')->name('produtividade.itens.destroy');
            Route::get('produtividade/periodos', 'periodos')->name('produtividade.periodos.index');
            Route::post('produtividade/periodos', 'gravarPeriodo')->name('produtividade.periodos.store');
            Route::get('produtividade/periodos/{periodo}', 'periodo')->whereNumber('periodo')->name('produtividade.periodos.show');
            Route::put('produtividade/periodos/{periodo}', 'gravarPeriodo')->whereNumber('periodo')->name('produtividade.periodos.update');
            Route::post('produtividade/periodos/{periodo}/fechar', 'fecharPeriodo')->whereNumber('periodo')->name('produtividade.periodos.fechar');
            Route::post('produtividade/periodos/{periodo}/reabrir', 'reabrirPeriodo')->whereNumber('periodo')->name('produtividade.periodos.reabrir');
            Route::post('produtividade/periodos/{periodo}/registos', 'gravarRegisto')->whereNumber('periodo')->name('produtividade.registos.store');
            Route::put('produtividade/periodos/{periodo}/registos/{registo}', 'gravarRegisto')->whereNumber(['periodo', 'registo'])->name('produtividade.registos.update');
            Route::delete('produtividade/periodos/{periodo}/registos/{registo}', 'eliminarRegisto')->whereNumber(['periodo', 'registo'])->name('produtividade.registos.destroy');
        });
        Route::post('rh/salarios/periodos/{id}/importar-produtividade', [FeriasProdutividadeController::class, 'lancar'])->whereNumber('id')->name('rh.salarios.importar-produtividade');

        Route::prefix('rh')->name('rh.')->controller(EstruturaController::class)->group(function () {
            Route::get('estrutura', 'arvore')->name('estrutura.arvore');
            Route::post('estrutura/unidades', 'guardarUnidade')->name('estrutura.unidades.store');
            Route::put('estrutura/unidades/{unidade}', 'guardarUnidade')->whereNumber('unidade')->name('estrutura.unidades.update');
            Route::delete('estrutura/unidades/{unidade}', 'eliminarUnidade')->whereNumber('unidade')->name('estrutura.unidades.destroy');
            Route::post('estrutura/postos', 'guardarPosto')->name('estrutura.postos.store');
            Route::put('estrutura/postos/{posto}', 'guardarPosto')->whereNumber('posto')->name('estrutura.postos.update');
            Route::delete('estrutura/postos/{posto}', 'eliminarPosto')->whereNumber('posto')->name('estrutura.postos.destroy');
            Route::post('estrutura/afectacao', 'afectar')->name('estrutura.afectar');
            Route::get('estrutura/chefia/{colaborador}', 'chefia')->whereNumber('colaborador')->name('estrutura.chefia');
            Route::get('cargos', 'cargos')->name('cargos.index');
            Route::post('cargos', 'guardarCargo')->name('cargos.store');
            Route::put('cargos/{cargo}', 'guardarCargo')->whereNumber('cargo')->name('cargos.update');
            Route::delete('cargos/{cargo}', 'eliminarCargo')->whereNumber('cargo')->name('cargos.destroy');
        });
        Route::prefix('rh/portal')->name('rh.portal.')->controller(PortalColaboradorController::class)->group(function () {
            Route::get('resumo', 'resumo')->name('resumo');
            Route::get('meus-pedidos', 'meusPedidos')->name('meus-pedidos');
            Route::get('recibos', 'recibos')->name('recibos');
            Route::post('pedidos', 'criarPedido')->name('pedidos.store');
            Route::post('pedidos/{pedido}/cancelar', 'cancelar')->whereNumber('pedido')->name('pedidos.cancelar');
            Route::get('aprovacoes', 'paraMim')->name('aprovacoes');
            Route::get('pedidos', 'pedidos')->name('pedidos.index');
            Route::post('pedidos/{pedido}/decidir', 'decidir')->whereNumber('pedido')->name('pedidos.decidir');
            Route::get('pedidos/{pedido}/proposta', 'propostaDocumento')->whereNumber('pedido')->name('pedidos.proposta');
            Route::post('pedidos/{pedido}/emitir', 'emitir')->whereNumber('pedido')->name('pedidos.emitir');
            Route::post('ligacoes', 'ligar')->name('ligacoes');
            Route::get('modelos', 'modelos')->name('modelos.index');
            Route::put('modelos', 'gravarModelo')->name('modelos.gravar');
            Route::delete('modelos/{codigo}', 'reporModelo')->where('codigo', '[A-Z0-9_]+')->name('modelos.repor');
        });

        Route::prefix('rh/avaliacao')->name('rh.avaliacao.')->controller(AvaliacaoController::class)->group(function () {
            Route::get('itens', 'itens')->name('itens.index');
            Route::post('itens', 'guardarItem')->name('itens.store');
            Route::put('itens/{item}', 'guardarItem')->whereNumber('item')->name('itens.update');
            Route::delete('itens/{item}', 'eliminarItem')->whereNumber('item')->name('itens.destroy');
            Route::get('avaliacoes', 'avaliacoes')->name('avaliacoes.index');
            Route::post('avaliacoes', 'gravar')->name('avaliacoes.gravar');
            Route::post('avaliacoes/{avaliacao}/reabrir', 'reabrir')->whereNumber('avaliacao')->name('avaliacoes.reabrir');
            Route::delete('avaliacoes/{avaliacao}', 'eliminar')->whereNumber('avaliacao')->name('avaliacoes.destroy');
            Route::post('avaliacoes/{avaliacao}/conhecimento', 'conhecimento')->whereNumber('avaliacao')->name('avaliacoes.conhecimento');
            Route::post('avaliacoes/{avaliacao}/contestar', 'contestar')->whereNumber('avaliacao')->name('avaliacoes.contestar');
            Route::post('avaliacoes/{avaliacao}/parecer', 'parecer')->whereNumber('avaliacao')->name('avaliacoes.parecer');
            Route::post('avaliacoes/{avaliacao}/decidir-contestacao', 'decidirContestacao')->whereNumber('avaliacao')->name('avaliacoes.decidir');
            Route::get('avaliacoes/{avaliacao}/resultado-360', 'resultado360')->whereNumber('avaliacao')->name('avaliacoes.resultado360');
            Route::get('ciclos', 'ciclos')->name('ciclos.index');
            Route::post('ciclos', 'gravarCiclo')->name('ciclos.store');
            Route::put('ciclos/{ciclo}', 'gravarCiclo')->whereNumber('ciclo')->name('ciclos.update');
            Route::post('ciclos/{ciclo}/abrir', 'abrirCiclo')->whereNumber('ciclo')->name('ciclos.abrir');
            Route::post('ciclos/{ciclo}/fechar', 'fecharCiclo')->whereNumber('ciclo')->name('ciclos.fechar');
            Route::post('ciclos/{ciclo}/confirmar-comunicado', 'confirmarComunicado')->whereNumber('ciclo')->name('ciclos.comunicado');
            Route::get('ciclos/{ciclo}/bonificacoes', 'bonificacoes')->whereNumber('ciclo')->name('bonificacoes.index');
            Route::post('ciclos/{ciclo}/bonificacoes/calcular', 'calcularBonificacoes')->whereNumber('ciclo')->name('bonificacoes.calcular');
            Route::post('ciclos/{ciclo}/bonificacoes/aprovar', 'aprovarBonificacoes')->whereNumber('ciclo')->name('bonificacoes.aprovar');
            Route::post('ciclos/{ciclo}/bonificacoes/lancar', 'lancarBonificacoes')->whereNumber('ciclo')->name('bonificacoes.lancar');
            Route::post('bonificacoes/{bonificacao}/anular', 'anularBonificacao')->whereNumber('bonificacao')->name('bonificacoes.anular');
            Route::post('feedbacks', 'registarFeedback')->name('feedbacks.store');
            Route::post('feedbacks/{feedback}/confirmar', 'confirmarFeedback')->whereNumber('feedback')->name('feedbacks.confirmar');
            Route::get('360/tarefas', 'tarefas360')->name('360.tarefas');
            Route::post('360/respostas', 'responder360')->name('360.responder');
            Route::get('autoavaliacao', 'autoavaliacao')->name('autoavaliacao.show');
            Route::put('autoavaliacao', 'gravarAutoavaliacao')->name('autoavaliacao.gravar');
            Route::post('ascendente', 'responderAscendente')->name('ascendente.responder');
            Route::get('ascendente/{colaborador}', 'resultadosAscendente')->whereNumber('colaborador')->name('ascendente.resultados');
        });

        Route::prefix('pos')->name('pos.')->controller(POSController::class)->group(function () {
            Route::get('terminais', 'terminais')->name('terminais.index');
            Route::post('terminais', 'guardarTerminal')->name('terminais.store');
            Route::get('terminais/{terminal}', 'terminal')->whereNumber('terminal')->name('terminais.show');
            Route::put('terminais/{terminal}', 'guardarTerminal')->whereNumber('terminal')->name('terminais.update');
            Route::delete('terminais/{terminal}', 'eliminarTerminal')->whereNumber('terminal')->name('terminais.destroy');
            Route::post('terminais/{terminal}/copiar-meios', 'copiarMeios')->whereNumber('terminal')->name('terminais.copiar');
            Route::post('terminais/{terminal}/ativo', 'ativarTerminal')->whereNumber('terminal')->name('terminais.ativo');
            Route::post('terminais/{terminal}/sessoes', 'abrirSessao')->whereNumber('terminal')->name('sessoes.abrir');
            Route::get('definicoes', 'definicoes')->name('definicoes.show');
            Route::put('definicoes', 'guardarDefinicoes')->name('definicoes.update');
            Route::get('sessoes', 'sessoes')->name('sessoes.index');
            Route::get('sessoes/{sessao}', 'sessao')->whereNumber('sessao')->name('sessoes.show');
            Route::get('sessoes/{sessao}/relatorio-x', 'relatorioX')->whereNumber('sessao')->name('sessoes.x');
            Route::post('sessoes/{sessao}/fechar', 'fecharSessao')->whereNumber('sessao')->name('sessoes.fechar');
            Route::post('sessoes/{sessao}/vendas', 'vender')->whereNumber('sessao')->name('sessoes.vender');
            Route::post('sessoes/{sessao}/contabilizar', 'contabilizar')->whereNumber('sessao')->name('sessoes.contabilizar');
            Route::post('sessoes/{sessao}/descontabilizar', 'descontabilizar')->whereNumber('sessao')->name('sessoes.descontabilizar');
            Route::post('sessoes/{sessao}/deliberacao', 'deliberar')->whereNumber('sessao')->name('sessoes.deliberar');
            Route::post('sessoes/{sessao}/deliberacao/anular', 'anularDeliberacao')->whereNumber('sessao')->name('sessoes.deliberacao.anular');
        });
        // POS: prestação de contas e relatórios, lavandaria, hotelaria e POS armazém (ficheiros próprios)
        require __DIR__.'/api/pos_prestacao.php';
        require __DIR__.'/api/pos_lavandaria.php';
        require __DIR__.'/api/pos_hotelaria.php';
        // Activos, Projectos, Acréscimos e diferimentos e CRM (ficheiros próprios)
        require __DIR__.'/api/ativos.php';
        require __DIR__.'/api/projetos.php';
        require __DIR__.'/api/acrescimos_crm.php';
        // Contabilidade parte 2, encerramento/rotinas/consolidação e administração do sistema (ficheiros próprios)
        require __DIR__.'/api/contabilidade_relatorios.php';
        require __DIR__.'/api/contabilidade_encerramento.php';
        require __DIR__.'/api/sistema_admin.php';
        // Painéis/BI e relatórios de gestão/fluxo de processos (ficheiros próprios)
        require __DIR__.'/api/gestao_paineis.php';
        require __DIR__.'/api/gestao_relatorios.php';
        // Afinação da Fase 5 (endpoints novos por grupo de módulos)
        require __DIR__.'/api/afinacao_a.php';
        require __DIR__.'/api/afinacao_b.php';
        require __DIR__.'/api/afinacao_c.php';
        // Ronda 2, grupo 3 (Vendas, Compras, Tesouraria, Armazém, Projectos e CRM)
        require __DIR__.'/api/ronda2_g3.php';
        // Ronda 2, grupo 4 (transversais e integrações): BAI automático, Power BI, assistente IA, preferências, operações
        require __DIR__.'/api/integracoes.php';
        // Ronda 2, grupo 1 (Contabilidade, POS, Gestão, Acréscimos, Estrutura)
        require __DIR__.'/api/ronda2_g1.php';
        // Ronda 2, grupo 2 (RH, Configurações, Activos, Orçamento)
        require __DIR__.'/api/ronda2_g2.php';
        // Simulações e pré-visualizações (nada gravado): lançamento antes de contabilizar
        require __DIR__.'/api/simulacoes.php';

        Route::prefix('orcamento')->name('orcamento.')->controller(OrcamentoController::class)->group(function () {
            Route::get('rubricas', 'rubricas')->name('rubricas.index');
            Route::post('rubricas', 'guardarRubrica')->name('rubricas.store');
            Route::post('rubricas/base', 'criarRubricasBase')->name('rubricas.base');
            Route::put('rubricas/{rubrica}', 'guardarRubrica')->whereNumber('rubrica')->name('rubricas.update');
            Route::delete('rubricas/{rubrica}', 'eliminarRubrica')->whereNumber('rubrica')->name('rubricas.destroy');
            Route::get('orcamentos', 'orcamentos')->name('orcamentos.index');
            Route::post('orcamentos', 'criar')->name('orcamentos.store');
            Route::get('orcamentos/{orcamento}', 'orcamento')->whereNumber('orcamento')->name('orcamentos.show');
            Route::put('orcamentos/{orcamento}/valores', 'gravarValores')->whereNumber('orcamento')->name('orcamentos.valores');
            Route::post('orcamentos/{orcamento}/submeter', 'submeter')->whereNumber('orcamento')->name('orcamentos.submeter');
            Route::post('orcamentos/{orcamento}/aprovar', 'aprovar')->whereNumber('orcamento')->name('orcamentos.aprovar');
            Route::post('orcamentos/{orcamento}/devolver', 'devolver')->whereNumber('orcamento')->name('orcamentos.devolver');
            Route::post('orcamentos/{orcamento}/nova-versao', 'novaVersao')->whereNumber('orcamento')->name('orcamentos.versao');
            Route::delete('orcamentos/{orcamento}', 'eliminar')->whereNumber('orcamento')->name('orcamentos.destroy');
            Route::post('orcamentos/{orcamento}/repartir', 'repartir')->whereNumber('orcamento')->name('orcamentos.repartir');
            Route::post('orcamentos/{orcamento}/contributos', 'pedirContributos')->whereNumber('orcamento')->name('orcamentos.contributos');
            Route::post('orcamentos/{orcamento}/consolidar', 'consolidar')->whereNumber('orcamento')->name('orcamentos.consolidar');
            Route::get('orcamentos/{orcamento}/controlo', 'controlo')->whereNumber('orcamento')->name('orcamentos.controlo');
            Route::post('verificar', 'verificar')->name('verificar');
            Route::get('pedidos-excesso', 'pedidosExcesso')->name('excesso.index');
            Route::post('pedidos-excesso', 'pedirExcesso')->name('excesso.store');
            Route::post('pedidos-excesso/{pedido}/decidir', 'decidirExcesso')->whereNumber('pedido')->name('excesso.decidir');
            Route::get('alertas', 'alertas')->name('alertas');
            Route::get('monitor', 'monitor')->name('monitor');
        });

        Route::prefix('orcamento')->name('orcamento.')->controller(PlaneamentoOrcamentalController::class)->group(function () {
            Route::get('previsoes', 'previsoes')->name('previsoes.index');
            Route::post('previsoes', 'criarPrevisao')->name('previsoes.store');
            Route::get('previsoes/{previsao}', 'previsao')->whereNumber('previsao')->name('previsoes.show');
            Route::put('previsoes/{previsao}', 'gravarPrevisao')->whereNumber('previsao')->name('previsoes.update');
            Route::post('previsoes/{previsao}/revisao', 'novaRevisao')->whereNumber('previsao')->name('previsoes.revisao');
            Route::post('previsoes/{previsao}/publicar', 'publicarPrevisao')->whereNumber('previsao')->name('previsoes.publicar');
            Route::delete('previsoes/{previsao}', 'eliminarPrevisao')->whereNumber('previsao')->name('previsoes.destroy');
            Route::get('orcamentos/{orcamento}/cenarios', 'cenarios')->whereNumber('orcamento')->name('cenarios.index');
            Route::post('orcamentos/{orcamento}/cenarios/padrao', 'cenariosPadrao')->whereNumber('orcamento')->name('cenarios.padrao');
            Route::post('cenarios', 'gravarCenario')->name('cenarios.store');
            Route::get('cenarios/{cenario}', 'cenario')->whereNumber('cenario')->name('cenarios.show');
            Route::put('cenarios/{cenario}', 'gravarCenario')->whereNumber('cenario')->name('cenarios.update');
            Route::delete('cenarios/{cenario}', 'eliminarCenario')->whereNumber('cenario')->name('cenarios.destroy');
            Route::post('cenarios/{cenario}/gerar-versao', 'orcamentoDeCenario')->whereNumber('cenario')->name('cenarios.versao');
            Route::get('orcamentos/{orcamento}/desvios/{rubrica}', 'desvios')->whereNumber(['orcamento', 'rubrica'])->name('desvios');
        });

        Route::prefix('rh')->name('rh.')->group(function () {
            Route::controller(ColaboradorController::class)->group(function () {
                Route::get('colaboradores', 'index')->name('colaboradores.index');
                Route::post('colaboradores', 'store')->name('colaboradores.store');
                Route::get('colaboradores/{colaborador}', 'show')->whereNumber('colaborador')->name('colaboradores.show');
                Route::put('colaboradores/{colaborador}', 'update')->whereNumber('colaborador')->name('colaboradores.update');
                Route::delete('colaboradores/{colaborador}', 'destroy')->whereNumber('colaborador')->name('colaboradores.destroy');
                Route::get('coordenadas-bancarias', 'coordenadas')->name('coordenadas.index');
                Route::put('colaboradores/{colaborador}/coordenada-bancaria', 'gravarCoordenada')->whereNumber('colaborador')->name('coordenadas.gravar');
                Route::delete('colaboradores/{colaborador}/coordenada-bancaria', 'eliminarCoordenada')->whereNumber('colaborador')->name('coordenadas.destroy');
            });
            Route::controller(ContratoTrabalhoController::class)->group(function () {
                Route::get('contratos', 'index')->name('contratos.index');
                Route::post('contratos', 'store')->name('contratos.store');
                Route::get('contratos/{contrato}', 'show')->whereNumber('contrato')->name('contratos.show');
                Route::put('contratos/{contrato}', 'update')->whereNumber('contrato')->name('contratos.update');
                Route::post('contratos/{contrato}/terminar', 'terminar')->whereNumber('contrato')->name('contratos.terminar');
                Route::delete('contratos/{contrato}', 'destroy')->whereNumber('contrato')->name('contratos.destroy');
            });
            Route::controller(CadastrosRHController::class)->group(function () {
                Route::get('infotipos', 'infotipos')->name('infotipos.index');
                Route::post('infotipos', 'guardarInfotipo')->name('infotipos.store');
                Route::put('infotipos/{infotipo}', 'guardarInfotipo')->whereNumber('infotipo')->name('infotipos.update');
                Route::delete('infotipos/{infotipo}', 'eliminarInfotipo')->whereNumber('infotipo')->name('infotipos.destroy');
                Route::get('tipos-organizacao', 'tiposOrganizacao')->name('tipos-organizacao.index');
                Route::post('tipos-organizacao', 'guardarTipoOrganizacao')->name('tipos-organizacao.store');
                Route::put('tipos-organizacao/{tipo}', 'guardarTipoOrganizacao')->whereNumber('tipo')->name('tipos-organizacao.update');
                Route::delete('tipos-organizacao/{tipo}', 'eliminarTipoOrganizacao')->whereNumber('tipo')->name('tipos-organizacao.destroy');
                Route::get('bancos', 'bancos')->name('bancos.index');
                Route::post('bancos', 'guardarBanco')->name('bancos.store');
                Route::put('bancos/{banco}', 'guardarBanco')->whereNumber('banco')->name('bancos.update');
                Route::delete('bancos/{banco}', 'eliminarBanco')->whereNumber('banco')->name('bancos.destroy');
                Route::get('mapeamentos-contabeis', 'mapeamentos')->name('mapeamentos.index');
                Route::put('mapeamentos-contabeis', 'gravarMapeamentos')->name('mapeamentos.gravar');
            });
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
