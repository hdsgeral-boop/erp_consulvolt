<?php

// Incluído por routes/api.php dentro do grupo autenticado com empresa activa (prefixo /api).

use App\Http\Controllers\Api\POS\ConfigImpressaoPOSController;
use Illuminate\Support\Facades\Route;

// POS F7: configuração de impressão na BD (empresa + sobreposição por terminal)
Route::prefix('pos/impressao')->name('pos.impressao.')->controller(ConfigImpressaoPOSController::class)->group(function () {
    Route::get('config', 'mostrar')->name('config.show');
    Route::put('config', 'guardar')->name('config.update');
    Route::delete('config/terminal/{terminal}', 'removerTerminal')->whereNumber('terminal')->name('config.terminal.destroy');
});
