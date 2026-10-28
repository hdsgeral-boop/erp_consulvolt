<?php

// Incluído por routes/api.php no grupo autenticado, SEM empresa activa (prefixo /api).
// Área da plataforma SaaS (ADR-075): só o super-administrador do tenant da plataforma, com o SaaS activo
// (middleware `plataforma`, negar por omissão). A licença/cobrança por empresa fica em /api/licenca.

use App\Http\Controllers\Api\Plataforma\AdministradoresController;
use App\Http\Controllers\Api\Plataforma\AgentesSuporteController;
use App\Http\Controllers\Api\Plataforma\AuditoriaPlataformaController;
use App\Http\Controllers\Api\Plataforma\ClientesController;
use App\Http\Controllers\Api\Plataforma\CodigosController;
use App\Http\Controllers\Api\Plataforma\ConversaSuportePlataformaController;
use App\Http\Controllers\Api\Plataforma\CupoesController;
use App\Http\Controllers\Api\Plataforma\DashboardSuporteController;
use App\Http\Controllers\Api\Plataforma\DefinicoesController;
use App\Http\Controllers\Api\Plataforma\MinhaContaController;
use App\Http\Controllers\Api\Plataforma\NotaInternaSuporteController;
use App\Http\Controllers\Api\Plataforma\PainelController;
use App\Http\Controllers\Api\Plataforma\PlanosPlataformaController;
use App\Http\Controllers\Api\Plataforma\PlataformaSaasController;
use App\Http\Controllers\Api\Plataforma\RespostaRapidaSuporteController;
use App\Http\Controllers\Api\Plataforma\SessoesController;
use App\Http\Controllers\Api\Plataforma\SuporteController;
use App\Http\Controllers\Api\Plataforma\TransferenciasBancariasController;
use App\Http\Controllers\Api\Sistema\GatewayPagamentoController;
use Illuminate\Support\Facades\Route;

Route::prefix('plataforma')->name('plataforma.')->middleware('plataforma')->group(function () {
    Route::get('painel', [PainelController::class, 'dashboard'])->name('painel');
    Route::get('relatorios/clientes.csv', [PainelController::class, 'relatorioClientes'])->name('relatorios.clientes');
    Route::get('relatorios/empresas.csv', [PainelController::class, 'relatorioEmpresas'])->name('relatorios.empresas');

    Route::controller(ClientesController::class)->group(function () {
        Route::get('clientes', 'index')->name('clientes.index');
        Route::post('clientes', 'store')->name('clientes.store');
        Route::get('clientes/{cliente}', 'mostrar')->whereNumber('cliente')->name('clientes.mostrar');
        Route::put('clientes/{cliente}', 'atualizar')->whereNumber('cliente')->name('clientes.atualizar');
        Route::put('clientes/{cliente}/estado', 'estado')->whereNumber('cliente')->name('clientes.estado');
        Route::post('clientes/{cliente}/arquivar', 'arquivar')->whereNumber('cliente')->name('clientes.arquivar');
        Route::get('clientes/{cliente}/utilizadores', 'utilizadores')->whereNumber('cliente')->name('clientes.utilizadores');
        Route::post('clientes/{cliente}/utilizadores/{utilizador}/redefinir', 'redefinirSenha')->whereNumber(['cliente', 'utilizador'])->name('clientes.utilizadores.redefinir');
        Route::put('clientes/{cliente}/empresas/{empresa}', 'atualizarEmpresa')->whereNumber(['cliente', 'empresa'])->name('clientes.empresas.atualizar');
        Route::get('empresas', 'empresas')->name('empresas.index');
    });

    Route::controller(SuporteController::class)->group(function () {
        Route::get('suporte', 'index')->name('suporte.index');
        Route::post('suporte', 'store')->name('suporte.store');
        Route::put('suporte/{ticket}', 'atualizar')->whereNumber('ticket')->name('suporte.atualizar');
        Route::delete('suporte/{ticket}', 'eliminar')->whereNumber('ticket')->name('suporte.eliminar');
    });

    Route::get('suporte/{ticket}/mensagens', [ConversaSuportePlataformaController::class, 'index'])->whereNumber('ticket')->name('suporte.mensagens');
    Route::post('suporte/{ticket}/mensagens', [ConversaSuportePlataformaController::class, 'responder'])->whereNumber('ticket')->name('suporte.responder');
    Route::get('suporte/anexos/{anexo}', [ConversaSuportePlataformaController::class, 'baixarAnexo'])->whereNumber('anexo')->name('suporte.anexos.show');
    Route::get('suporte/agentes', [SuporteController::class, 'agentes'])->name('suporte.agentes');
    Route::get('suporte/metricas', [DashboardSuporteController::class, 'metricas'])->name('suporte.metricas');
    Route::get('suporte/{ticket}/notas', [NotaInternaSuporteController::class, 'index'])->whereNumber('ticket')->name('suporte.notas.index');
    Route::post('suporte/{ticket}/notas', [NotaInternaSuporteController::class, 'store'])->whereNumber('ticket')->name('suporte.notas.store');
    Route::controller(RespostaRapidaSuporteController::class)->prefix('suporte/respostas-rapidas')->name('suporte.respostas_rapidas.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::put('{resposta}', 'update')->whereNumber('resposta')->name('update');
        Route::delete('{resposta}', 'destroy')->whereNumber('resposta')->name('destroy');
    });

    Route::controller(PlanosPlataformaController::class)->group(function () {
        Route::get('planos', 'index')->name('planos.index');
        Route::post('planos/{plano}/versoes', 'criarVersao')->whereNumber('plano')->name('planos.versoes.criar');
        Route::put('versoes/{versao}', 'atualizarVersao')->whereNumber('versao')->name('versoes.atualizar');
        Route::post('versoes/{versao}/publicar', 'publicar')->whereNumber('versao')->name('versoes.publicar');
        Route::put('clientes/{cliente}/plano', 'atribuirPlano')->whereNumber('cliente')->name('clientes.plano');
    });

    Route::get('codigos', [CodigosController::class, 'index'])->name('codigos.index');

    Route::get('auditoria', [AuditoriaPlataformaController::class, 'index'])->name('auditoria.index');

    Route::controller(AdministradoresController::class)->group(function () {
        Route::get('administradores', 'index')->name('administradores.index');
        Route::post('administradores', 'store')->name('administradores.store');
        Route::put('administradores/{administrador}/estado', 'estado')->whereNumber('administrador')->name('administradores.estado');
        Route::put('administradores/{administrador}', 'atualizar')->whereNumber('administrador')->name('administradores.atualizar');
        Route::delete('administradores/{administrador}', 'eliminar')->whereNumber('administrador')->name('administradores.eliminar');
    });

    Route::controller(AgentesSuporteController::class)->group(function () {
        Route::get('agentes-suporte', 'index')->name('agentes_suporte.index');
        Route::get('perfis-suporte', 'perfis')->name('perfis_suporte.index');
        Route::post('agentes-suporte', 'store')->name('agentes_suporte.store');
        Route::put('agentes-suporte/{agente}/estado', 'estado')->whereNumber('agente')->name('agentes_suporte.estado');
        Route::put('agentes-suporte/{agente}', 'atualizar')->whereNumber('agente')->name('agentes_suporte.atualizar');
        Route::delete('agentes-suporte/{agente}', 'eliminar')->whereNumber('agente')->name('agentes_suporte.eliminar');
    });

    Route::controller(SessoesController::class)->group(function () {
        Route::get('sessoes', 'index')->name('sessoes.index');
        Route::delete('sessoes/{id}', 'revogar')->whereNumber('id')->name('sessoes.revogar');
    });

    Route::controller(DefinicoesController::class)->group(function () {
        Route::get('definicoes', 'index')->name('definicoes.index');
        Route::put('definicoes', 'atualizar')->name('definicoes.atualizar');
    });

    Route::controller(PlataformaSaasController::class)->group(function () {
        Route::get('saas/estado', 'estadoGeral')->name('saas.estado');
        Route::put('saas/geral', 'atualizarGeral')->name('saas.geral');
        Route::get('saas/empresas', 'listarEmpresas')->name('saas.empresas');
        Route::put('saas/empresas/{empresa}', 'atualizarEmpresa')->whereNumber('empresa')->name('saas.empresas.atualizar');
    });

    Route::controller(CupoesController::class)->prefix('cupoes')->name('cupoes.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::put('{cupom}', 'update')->whereNumber('cupom')->name('update');
        Route::put('{cupom}/estado', 'estado')->whereNumber('cupom')->name('estado');
    });

    Route::controller(TransferenciasBancariasController::class)->prefix('transferencias')->name('transferencias.')->group(function () {
        Route::get('pendentes', 'pendentes')->name('pendentes');
        Route::get('comprovativo/{anexo}', 'comprovativo')->whereNumber('anexo')->name('comprovativo');
        Route::post('{subscricao}/confirmar', 'confirmar')->whereNumber('subscricao')->name('confirmar');
    });

    Route::get('pagamentos/config', [GatewayPagamentoController::class, 'obterConfig'])->name('pagamentos.config');
    Route::put('pagamentos/config', [GatewayPagamentoController::class, 'guardarConfig'])->name('pagamentos.config.guardar');
});

// "Minha conta" do cliente SaaS: só o super-administrador do próprio cliente (middleware dmin_cliente).
Route::prefix('minha-conta')->name('minha_conta.')->middleware('admin_cliente')->controller(MinhaContaController::class)->group(function () {
    Route::get('/', 'resumo')->name('resumo');
    Route::put('identidade', 'identidade')->name('identidade');
    Route::get('planos', 'planos')->name('planos');
    Route::get('suporte', 'suporte')->name('suporte.index');
    Route::post('suporte', 'abrirSuporte')->name('suporte.store');
    Route::get('suporte/{ticket}/mensagens', 'mensagens')->whereNumber('ticket')->name('suporte.mensagens');
    Route::post('suporte/{ticket}/mensagens', 'responder')->whereNumber('ticket')->name('suporte.responder');
    Route::post('suporte/{ticket}/avaliacao', 'avaliacao')->whereNumber('ticket')->name('suporte.avaliacao');
    Route::get('suporte/anexos/{anexo}', 'baixarAnexo')->whereNumber('anexo')->name('suporte.anexos.show');
});
