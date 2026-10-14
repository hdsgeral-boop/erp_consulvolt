<?php

use App\Http\Controllers\Api\Tesouraria\ContasBancariasController;
use Illuminate\Support\Facades\Route;

// Fase A (Versão Actual, ADR-071) — rotas do agente fa_tesouraria; incluído por routes/api.php no grupo com empresa activa.

// Dados bancários das contas 42/43 do plano (leitura; a gravação vai com a conta em /contabilidade/plano-contas)
Route::prefix('tesouraria/contas-bancarias')->name('tesouraria.contas-bancarias.')->controller(ContasBancariasController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('sugestoes-bancos', 'sugestoesBancos')->name('sugestoes-bancos');
    Route::get('{codigo}', 'show')->where('codigo', '4[23][0-9A-Za-z.]*')->name('show');
});
