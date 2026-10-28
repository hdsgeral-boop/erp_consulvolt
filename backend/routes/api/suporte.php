<?php

// Incluído por routes/api.php no grupo autenticado, SEM empresa activa (prefixo /api).
// Backoffice de suporte isolado (ADR-076, fase 4 fatia 3): super-administrador OU agente de suporte do tenant da
// plataforma (middleware `suporte`, negar por omissão — ver ExigirSuporte). Só tickets: nunca clientes, planos,
// licenciamento nem definições globais, que continuam exclusivos de /api/plataforma/* (middleware `plataforma`).
// Reaproveita os mesmos controladores de /api/plataforma/suporte/* — não decidem quem entra, só o middleware da rota
// decide — mas sem `store` nem `eliminar`: abrir e apagar um ticket continuam reservados à gestão da plataforma.

use App\Http\Controllers\Api\Plataforma\ConversaSuportePlataformaController;
use App\Http\Controllers\Api\Plataforma\DashboardSuporteController;
use App\Http\Controllers\Api\Plataforma\NotaInternaSuporteController;
use App\Http\Controllers\Api\Plataforma\RespostaRapidaSuporteController;
use App\Http\Controllers\Api\Plataforma\SuporteController;
use Illuminate\Support\Facades\Route;

Route::prefix('suporte')->name('suporte_agente.')->middleware('suporte')->group(function () {
    Route::get('tickets', [SuporteController::class, 'index'])->name('tickets.index');
    Route::put('tickets/{ticket}', [SuporteController::class, 'atualizar'])->whereNumber('ticket')->name('tickets.atualizar');
    Route::get('tickets/{ticket}/mensagens', [ConversaSuportePlataformaController::class, 'index'])->whereNumber('ticket')->name('tickets.mensagens');
    Route::post('tickets/{ticket}/mensagens', [ConversaSuportePlataformaController::class, 'responder'])->whereNumber('ticket')->name('tickets.responder');
    Route::get('anexos/{anexo}', [ConversaSuportePlataformaController::class, 'baixarAnexo'])->whereNumber('anexo')->name('anexos.show');
    Route::get('agentes', [SuporteController::class, 'agentes'])->name('agentes.index');
    Route::get('metricas', [DashboardSuporteController::class, 'metricas'])->name('metricas');
    Route::get('tickets/{ticket}/notas', [NotaInternaSuporteController::class, 'index'])->whereNumber('ticket')->name('tickets.notas.index');
    Route::post('tickets/{ticket}/notas', [NotaInternaSuporteController::class, 'store'])->whereNumber('ticket')->name('tickets.notas.store');
    Route::controller(RespostaRapidaSuporteController::class)->prefix('respostas-rapidas')->name('respostas_rapidas.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::put('{resposta}', 'update')->whereNumber('resposta')->name('update');
        Route::delete('{resposta}', 'destroy')->whereNumber('resposta')->name('destroy');
    });
});
