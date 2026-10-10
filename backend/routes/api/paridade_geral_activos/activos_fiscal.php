<?php

// Incluído por routes/api/paridade_geral_activos.php (grupo autenticado com empresa activa, prefixo /api).
// Activos — Amortizações (ferramentas em massa do legado) e Mapa Fiscal. O Mapa Fiscal usa GET /ativos/mapas/fiscal (ativos.php).

use App\Http\Controllers\Api\Ativos\AmortizacoesController;
use Illuminate\Support\Facades\Route;

Route::prefix('ativos')->name('ativos.')->controller(AmortizacoesController::class)->group(function () {
    Route::get('amortizacoes/periodos-registados', 'periodosRegistados')->name('amortizacoes.periodos_registados');
});
