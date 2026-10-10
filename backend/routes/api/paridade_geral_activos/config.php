<?php

use App\Http\Controllers\Api\Sistema\ParidadeConfigController;
use Illuminate\Support\Facades\Route;

// Configurações — paridade com o sistema anterior (verificação visual de 2026-10-08, secção 14).
// Incluído por routes/api/paridade_geral_activos.php (grupo autenticado com empresa activa, prefixo /api).
// Só escritas na empresa activa; nenhuma operação global. O modelo Excel reutiliza /sistema/migracao/modelos/plano_contas.
Route::prefix('config/plano-contas')->name('config.paridade.plano_contas.')->controller(ParidadeConfigController::class)->group(function () {
    Route::get('uso', 'uso')->name('uso');                                   // config_plano_view | contab_plano_gerir | lancamentos_view
    Route::post('copiar/simular', 'simularCopia')->name('copiar.simular');   // contab_plano_gerir
    Route::post('copiar', 'copiar')->middleware('throttle:pesado')->name('copiar');        // contab_plano_gerir + acesso à origem
    Route::post('importar', 'importar')->middleware('throttle:pesado')->name('importar');  // contab_plano_gerir
});
