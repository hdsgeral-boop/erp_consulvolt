<?php

use App\Http\Controllers\Api\Integracoes\AssistenteIAController;
use App\Http\Controllers\Api\Integracoes\CambiosBAIAutomaticosController;
use App\Http\Controllers\Api\Integracoes\PreferenciasOperacoesController;
use App\Http\Controllers\Api\Integracoes\TokensBIController;
use Illuminate\Support\Facades\Route;

// Incluído por routes/api.php dentro do grupo autenticado com empresa activa (prefixo /api).
// Funcionalidades transversais e integrações (ronda 2, R2-G4): câmbios do BAI automáticos, Power BI (tokens),
// assistente IA, preferências do utilizador e operações em segundo plano.

Route::prefix('sistema')->name('sistema.')->group(function () {
    Route::controller(CambiosBAIAutomaticosController::class)->prefix('cambios/bai')->name('cambios.bai.')->group(function () {
        Route::get('automatico', 'estado')->name('automatico');
        Route::put('automatico', 'configurar')->name('automatico.configurar');
        Route::post('automatico/obter', 'obter')->name('automatico.obter');
        Route::post('pendentes/validar', 'validar')->name('pendentes.validar');
        Route::post('pendentes/rejeitar', 'rejeitar')->name('pendentes.rejeitar');
    });

    // Preferências do próprio utilizador (M-19) e operações em segundo plano (M-05)
    Route::controller(PreferenciasOperacoesController::class)->group(function () {
        Route::get('preferencias/{tipo}', 'listar')->where('tipo', '[a-z_]{1,40}')->name('preferencias.index');
        Route::put('preferencias/{tipo}/{nome}', 'gravar')->where(['tipo' => '[a-z_]{1,40}', 'nome' => '[A-Za-z0-9_.:\-]{1,150}'])->name('preferencias.gravar');
        Route::delete('preferencias/{tipo}/{nome}', 'eliminar')->where(['tipo' => '[a-z_]{1,40}', 'nome' => '[A-Za-z0-9_.:\-]{1,150}'])->name('preferencias.eliminar');
        Route::get('operacoes/{id}', 'operacao')->where('id', '[A-Za-z0-9\-]{1,64}')->name('operacoes.show');
        Route::post('operacoes/{id}/cancelar', 'cancelarOperacao')->where('id', '[A-Za-z0-9\-]{1,64}')->name('operacoes.cancelar');
    });

    // Power BI: tokens de leitura do feed OData da empresa activa (config_backup)
    Route::controller(TokensBIController::class)->prefix('bi/tokens')->name('bi.tokens.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::post('{token}/revogar', 'revogar')->whereNumber('token')->name('revogar');
    });
});

// Assistente IA para lançamentos (decisão 26): só propõe; grava-se no formulário normal de lançamento
Route::prefix('contabilidade/assistente')->name('contabilidade.assistente.')->controller(AssistenteIAController::class)->group(function () {
    Route::get('/', 'estado')->name('estado');
    Route::put('configuracao', 'configurar')->name('configurar');
    Route::post('propor', 'propor')->middleware('throttle:20,1')->name('propor');
    Route::get('regras', 'regras')->name('regras.index');
    Route::post('regras', 'gravarRegra')->name('regras.store');
    Route::put('regras/{regra}', 'gravarRegra')->whereNumber('regra')->name('regras.update');
    Route::delete('regras/{regra}', 'eliminarRegra')->whereNumber('regra')->name('regras.destroy');
});
