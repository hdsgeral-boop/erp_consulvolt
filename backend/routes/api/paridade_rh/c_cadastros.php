<?php

// Incluído por routes/api/paridade_rh.php (grupo autenticado com empresa activa, prefixo /api).
// RH › Funções, Infotipos e Coordenadas bancárias: Template, Importar Excel e Copiar de outra empresa (relatório 6.5.2–6.5.4).

use App\Http\Controllers\Api\RH\ParidadeCadastrosRHController;
use Illuminate\Support\Facades\Route;

Route::prefix('rh/paridade')->name('rh.paridade.c.')->controller(ParidadeCadastrosRHController::class)->group(function () {
    Route::get('cadastros/{entidade}/modelo', 'modelo')->where('entidade', 'funcoes|infotipos|bancos')->name('modelo');
    Route::post('cadastros/{entidade}/importar', 'importar')->where('entidade', 'funcoes|infotipos|bancos')->middleware('throttle:pesado')->name('importar');
    Route::post('cadastros/{entidade}/copiar', 'copiar')->where('entidade', 'funcoes|infotipos|bancos')->middleware('throttle:pesado')->name('copiar');
    Route::get('empresas-origem', 'empresasOrigem')->name('empresas-origem');
    Route::get('regras-horas-extra', 'regrasHorasExtra')->name('regras-horas-extra');
});
