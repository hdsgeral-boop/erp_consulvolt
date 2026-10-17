<?php

use App\Http\Controllers\Api\Projetos\FolhaMedicaoController;
use App\Http\Controllers\Api\Projetos\ProjetoController;
use Illuminate\Support\Facades\Route;

// Ronda 3 — agente r3_rh_proj; incluído por routes/api.php no grupo autenticado com empresa activa.
// Folha de medição por pessoa: modelo Excel e importação.

Route::prefix('projetos/{projeto}')->whereNumber('projeto')->name('projetos.r3.')->controller(FolhaMedicaoController::class)->group(function () {
    Route::get('medicoes/modelo', 'modelo')->name('medicoes.modelo');
    Route::post('medicoes/importar', 'importar')->name('medicoes.importar');
});

// Separador «Actividade»: opções dos filtros (acções e utilizadores distintos)
Route::get('projetos/{projeto}/atividade/filtros', [ProjetoController::class, 'atividadeFiltros'])->whereNumber('projeto')->name('projetos.r3.atividade.filtros');
