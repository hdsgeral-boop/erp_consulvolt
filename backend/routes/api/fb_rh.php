<?php

use App\Http\Controllers\Api\RH\ContratoEstadoController;
use App\Http\Controllers\Api\RH\EmprestimosController;
use App\Http\Controllers\Api\RH\EscalasController;
use App\Http\Controllers\Api\RH\PortalColaboradorController;
use Illuminate\Support\Facades\Route;

// Versão Actual (ADR-071) — rotas do agente fb_rh; incluído por routes/api.php no grupo com empresa activa.

// 1. Suspensão e cessação do contrato (contrato_estado.js; Lei 12/23)
Route::prefix('rh/contratos')->name('rh.contratos.')->controller(ContratoEstadoController::class)->group(function () {
    Route::get('fundamentos', 'fundamentos')->name('fundamentos');
    Route::get('{contrato}/estado', 'estado')->whereNumber('contrato')->name('estado');
    Route::get('{contrato}/compensacao', 'compensacao')->whereNumber('contrato')->name('compensacao');
    Route::post('{contrato}/suspender', 'suspender')->whereNumber('contrato')->name('suspender');
    Route::post('{contrato}/reactivar', 'reactivar')->whereNumber('contrato')->name('reactivar');
    Route::post('{contrato}/cessar', 'cessar')->whereNumber('contrato')->name('cessar');
    Route::post('{contrato}/anular-cessacao', 'anularCessacao')->whereNumber('contrato')->name('anular-cessacao');
});

// 2. Escala do mês (escalas.js; separador de RH › Efectividade)
Route::prefix('rh/assiduidade/escalas')->name('rh.assiduidade.escalas.')->controller(EscalasController::class)->group(function () {
    Route::get('modelo', 'modelo')->name('modelo');
    Route::get('{mes}', 'grelha')->where('mes', '\d{4}-\d{2}')->name('grelha');
    Route::post('aplicar', 'aplicar')->name('aplicar');
    Route::post('do-contrato', 'gerarDoContrato')->name('do-contrato');
    Route::post('copiar', 'copiar')->name('copiar');
    Route::post('importar', 'importar')->name('importar');
    Route::post('eliminar', 'eliminar')->name('eliminar');
    Route::post('limpar', 'limpar')->name('limpar');
});

// 4. Empréstimos a colaboradores (emprestimos.js; ecrã rh_emprestimos; «Importar Empréstimos» no cálculo)
Route::prefix('rh/emprestimos')->name('rh.emprestimos.')->controller(EmprestimosController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('configuracao', 'configuracao')->name('configuracao');
    Route::put('configuracao', 'gravarConfiguracao')->name('configuracao.gravar');
    Route::get('{emprestimo}', 'show')->whereNumber('emprestimo')->name('show');
    Route::post('{emprestimo}/cancelar', 'cancelar')->whereNumber('emprestimo')->name('cancelar');
    Route::post('{emprestimo}/conta-corrente', 'contaCorrente')->whereNumber('emprestimo')->name('conta-corrente');
});
Route::prefix('rh/salarios/periodos')->name('rh.salarios.emprestimos.')->controller(EmprestimosController::class)->group(function () {
    Route::get('{id}/emprestimos', 'prestacoesDoPeriodo')->whereNumber('id')->name('index');
    Route::post('{id}/importar-emprestimos', 'importar')->whereNumber('id')->name('importar');
    Route::post('{id}/anular-emprestimos', 'anular')->whereNumber('id')->name('anular');
});

// 5. Portal do colaborador: separadores Escala, Contrato e Empréstimos (portal_ui.js:398-620)
Route::prefix('rh/portal')->name('rh.portal.')->controller(PortalColaboradorController::class)->group(function () {
    Route::get('escala', 'escala')->name('escala');
    Route::get('contrato', 'contrato')->name('contrato');
    Route::get('emprestimos', 'emprestimos')->name('emprestimos');
    Route::get('emprestimos/simular', 'simularEmprestimo')->name('emprestimos.simular');
});
