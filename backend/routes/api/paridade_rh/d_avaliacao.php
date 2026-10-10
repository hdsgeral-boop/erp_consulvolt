<?php

// Incluído por routes/api/paridade_rh.php (grupo autenticado com empresa activa, prefixo /api).
// RH › Acompanhamento do Ciclo 360º: participação, reuniões de acompanhamento e comunicado (relatório 6.5.10).

use App\Http\Controllers\Api\RH\ParidadeAvaliacaoRHController;
use Illuminate\Support\Facades\Route;

Route::prefix('rh/paridade/avaliacao')->name('rh.paridade.d.')->controller(ParidadeAvaliacaoRHController::class)->group(function () {
    Route::get('ciclos/{ciclo}/acompanhamento', 'acompanhamento')->whereNumber('ciclo')->name('acompanhamento');
});
