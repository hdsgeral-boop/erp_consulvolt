<?php

use App\Http\Controllers\Api\Sistema\ConfiguracaoSistemaController;
use App\Http\Controllers\Api\Sistema\CopiaEmpresaController;
use App\Http\Controllers\Api\Sistema\EmpresaController;
use App\Http\Controllers\Api\Sistema\ManutencaoDadosController;
use App\Http\Controllers\Api\Sistema\MigracaoDadosController;
use App\Http\Controllers\Api\Sistema\MoedaController;
use App\Http\Controllers\Api\Sistema\PerfilController;
use App\Http\Controllers\Api\Sistema\SubstituicaoContaController;
use App\Http\Controllers\Api\Sistema\UnidadeNegocioController;
use App\Http\Controllers\Api\Sistema\UtilizadorController;
use Illuminate\Support\Facades\Route;

// Incluído por routes/api.php dentro do grupo autenticado com empresa activa (prefixo /api).
// Administração do sistema (ADR-058): utilizadores, perfis, empresas, moedas e câmbios, unidades de negócio,
// substituir conta, manutenção de dados, cópias de segurança e migração de dados.

Route::prefix('sistema')->name('sistema.')->group(function () {
    Route::controller(UtilizadorController::class)->prefix('utilizadores')->name('utilizadores.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::get('colaboradores', 'colaboradores')->name('colaboradores');
        Route::get('{utilizador}', 'show')->whereNumber('utilizador')->name('show');
        Route::put('{utilizador}', 'update')->whereNumber('utilizador')->name('update');
        Route::delete('{utilizador}', 'destroy')->whereNumber('utilizador')->name('destroy');
        Route::put('{utilizador}/estado', 'estado')->whereNumber('utilizador')->name('estado');
        Route::post('{utilizador}/palavra-passe', 'reporPalavraPasse')->whereNumber('utilizador')->name('palavra_passe');
    });

    Route::controller(PerfilController::class)->prefix('perfis')->name('perfis.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::get('catalogo', 'catalogo')->name('catalogo');
        Route::get('matriz', 'matriz')->name('matriz');
        Route::post('avaliar', 'avaliar')->name('avaliar');
        Route::post('modelos', 'criarModelos')->name('modelos.criar');
        Route::post('modelos/actualizar', 'actualizarModelos')->name('modelos.actualizar');
        Route::get('{perfil}', 'show')->whereNumber('perfil')->name('show');
        Route::put('{perfil}', 'update')->whereNumber('perfil')->name('update');
        Route::delete('{perfil}', 'destroy')->whereNumber('perfil')->name('destroy');
        Route::post('{perfil}/duplicar', 'duplicar')->whereNumber('perfil')->name('duplicar');
    });

    Route::controller(EmpresaController::class)->group(function () {
        Route::get('gestao-empresas', 'gestao')->name('gestao_empresas.index');
        Route::get('gestao-empresas/{empresa}', 'ficha')->whereNumber('empresa')->name('gestao_empresas.show');
        Route::post('empresas', 'store')->name('empresas.store');
        Route::put('empresas/{empresa}', 'update')->whereNumber('empresa')->name('empresas.update');
        Route::put('empresas/{empresa}/estado', 'estado')->whereNumber('empresa')->name('empresas.estado');
        Route::put('empresa-ativa/horas-extra', 'horasExtra')->name('empresa_ativa.horas_extra');
    });

    Route::controller(MoedaController::class)->group(function () {
        Route::get('moedas', 'index')->name('moedas.index');
        Route::post('moedas', 'store')->name('moedas.store');
        Route::put('moedas/funcional', 'funcional')->name('moedas.funcional');
        Route::put('moedas/{moeda}', 'update')->whereNumber('moeda')->name('moedas.update');
        Route::get('cambios', 'cambios')->name('cambios.index');
        Route::post('cambios', 'guardarCambio')->name('cambios.store');
        Route::get('cambios/consultar', 'consultar')->name('cambios.consultar');
        Route::post('cambios/importar', 'importar')->name('cambios.importar');
        Route::get('cambios/bai', 'previsualizarBai')->name('cambios.bai');
        Route::post('cambios/bai', 'gravarBai')->name('cambios.bai.gravar');
        Route::put('cambios/{cambio}', 'guardarCambio')->whereNumber('cambio')->name('cambios.update');
        Route::delete('cambios/{cambio}', 'eliminarCambio')->whereNumber('cambio')->name('cambios.destroy');
    });

    Route::controller(UnidadeNegocioController::class)->prefix('unidades-negocio')->name('unidades_negocio.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::get('{unidade}', 'show')->whereNumber('unidade')->name('show');
        Route::put('{unidade}', 'update')->whereNumber('unidade')->name('update');
        Route::delete('{unidade}', 'destroy')->whereNumber('unidade')->name('destroy');
    });

    Route::controller(SubstituicaoContaController::class)->prefix('plano-contas/substituir')->name('plano_contas.substituir.')->group(function () {
        Route::post('simular', 'simular')->name('simular');
        Route::post('/', 'executar')->name('executar');
    });

    Route::controller(ManutencaoDadosController::class)->prefix('manutencao')->name('manutencao.')->group(function () {
        Route::get('acoes', 'acoes')->name('acoes');
        Route::post('impacto', 'impacto')->name('impacto');
        Route::get('pedidos', 'index')->name('pedidos.index');
        Route::post('pedidos', 'store')->name('pedidos.store');
        Route::get('pedidos/{pedido}', 'show')->whereNumber('pedido')->name('pedidos.show');
        Route::post('pedidos/{pedido}/aprovar', 'aprovar')->whereNumber('pedido')->name('pedidos.aprovar');
        Route::post('pedidos/{pedido}/rejeitar', 'rejeitar')->whereNumber('pedido')->name('pedidos.rejeitar');
        Route::post('pedidos/{pedido}/executar', 'executar')->whereNumber('pedido')->name('pedidos.executar');
        Route::post('pedidos/{pedido}/cancelar', 'cancelar')->whereNumber('pedido')->name('pedidos.cancelar');
    });

    Route::put('configuracoes/logotipo-login', [ConfiguracaoSistemaController::class, 'gravarLogotipoLogin'])->name('configuracoes.logotipo_login');

    Route::controller(CopiaEmpresaController::class)->prefix('copias')->name('copias.')->group(function () {
        Route::get('exportar', 'exportar')->name('exportar');
        Route::post('importar', 'importar')->name('importar');
        Route::post('clonar', 'clonar')->name('clonar');
    });

    Route::controller(MigracaoDadosController::class)->prefix('migracao')->name('migracao.')->group(function () {
        Route::get('modelos', 'modelos')->name('modelos');
        Route::get('modelos/{entidade}', 'modeloExcel')->where('entidade', '[a-z_]+')->name('modelos.excel');
        Route::post('importar/{entidade}', 'importar')->where('entidade', '[a-z_]+')->name('importar');
        Route::post('edicao-massa/{entidade}', 'editarEmMassa')->where('entidade', '[a-z_]+')->name('edicao_massa');
    });
});
