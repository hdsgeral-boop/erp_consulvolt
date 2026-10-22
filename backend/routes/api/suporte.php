<?php

// Incluído por routes/api.php no grupo autenticado, SEM empresa activa (prefixo /api).
// Backoffice de suporte isolado (ADR-076, fase 4 fatia 3): super-administrador OU agente de suporte do tenant da
// plataforma (middleware `suporte`, negar por omissão — ver ExigirSuporte). Só tickets: nunca clientes, planos,
// licenciamento nem definições globais, que continuam exclusivos de /api/plataforma/* (middleware `plataforma`).
// Reaproveita os mesmos controladores de /api/plataforma/suporte/* — não decidem quem entra, só o middleware da rota
// decide — mas sem `store` nem `eliminar`: abrir e apagar um ticket continuam reservados à gestão da plataforma.

use App\Http\Controllers\Api\Plataforma\ConversaSuportePlataformaController;
use App\Http\Controllers\Api\Plataforma\SuporteController;
use Illuminate\Support\Facades\Route;

Route::prefix('suporte')->name('suporte_agente.')->middleware('suporte')->group(function () {
    Route::get('tickets', [SuporteController::class, 'index'])->name('tickets.index');
    Route::put('tickets/{ticket}', [SuporteController::class, 'atualizar'])->whereNumber('ticket')->name('tickets.atualizar');
    Route::get('tickets/{ticket}/mensagens', [ConversaSuportePlataformaController::class, 'index'])->whereNumber('ticket')->name('tickets.mensagens');
    Route::post('tickets/{ticket}/mensagens', [ConversaSuportePlataformaController::class, 'responder'])->whereNumber('ticket')->name('tickets.responder');
});
