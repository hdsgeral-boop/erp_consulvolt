<?php

// Incluído por routes/api.php dentro do grupo autenticado com empresa activa (prefixo /api).

use App\Http\Controllers\Api\POS\ConfigImpressaoPOSController;
use App\Http\Controllers\Api\POS\ExportacaoRelatoriosPOSController;
use App\Http\Controllers\Api\POS\PdfPOSController;
use Illuminate\Support\Facades\Route;

// POS F7: configuração de impressão na BD (empresa + sobreposição por terminal)
Route::prefix('pos/impressao')->name('pos.impressao.')->controller(ConfigImpressaoPOSController::class)->group(function () {
    Route::get('config', 'mostrar')->name('config.show');
    Route::put('config', 'guardar')->name('config.update');
    Route::delete('config/terminal/{terminal}', 'removerTerminal')->whereNumber('terminal')->name('config.terminal.destroy');
});

// POS F7: PDF A4 dos impressos gerado no servidor (descarga; limite de pedidos por minuto)
Route::post('pos/impressao/pdf', [PdfPOSController::class, 'gerar'])->middleware(['licenca_exportacao', 'throttle:20,1'])->name('pos.impressao.pdf');

// POS F7: Excel dos relatórios do POS (mesmos filtros e permissões de /pos/relatorios)
Route::get('pos/relatorios/excel', [ExportacaoRelatoriosPOSController::class, 'excel'])->middleware(['licenca_exportacao', 'throttle:20,1'])->name('pos.relatorios.excel');
