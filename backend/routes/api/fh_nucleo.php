<?php

use App\Http\Controllers\Api\Integracoes\FicheirosRegrasIAController;
use Illuminate\Support\Facades\Route;

// Versão Actual (ADR-071) — rotas do agente fh_nucleo; incluído por routes/api.php no grupo com empresa activa.

// Assistente IA › Regras em ficheiro (ecrã ia_assistente; tarefas ia_regras e ia_lancar). Nada é gravado no diário.
Route::prefix('contabilidade/assistente/ficheiros-regras')->name('contabilidade.assistente.ficheiros.')->controller(FicheirosRegrasIAController::class)->group(function () {
    Route::get('estado', 'estado')->name('estado');
    Route::get('modelo', 'modelo')->name('modelo');
    Route::get('/', 'index')->name('index');
    Route::post('/', 'store')->middleware('throttle:30,1')->name('store');
    Route::get('{regra}', 'show')->whereNumber('regra')->name('show');
    Route::get('{regra}/exportar', 'exportar')->whereNumber('regra')->name('exportar');
    Route::patch('{regra}', 'update')->whereNumber('regra')->name('update');
    Route::delete('{regra}', 'destroy')->whereNumber('regra')->name('destroy');
    Route::post('{regra}/calcular', 'calcular')->whereNumber('regra')->middleware('throttle:120,1')->name('calcular');
    Route::post('{regra}/extrair', 'extrair')->whereNumber('regra')->middleware('throttle:20,1')->name('extrair');
});
