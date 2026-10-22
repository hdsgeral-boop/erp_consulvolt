<?php

// Incluído por routes/api.php no grupo autenticado COM empresa activa (prefixo /api).
// Assistente IA do MEU ERP (chat com histórico, anexos, consultas e propostas confirmadas pelo utilizador).

use App\Http\Controllers\Api\IA\AssistenteChatController;
use Illuminate\Support\Facades\Route;

Route::prefix('ia')->name('ia.')->controller(AssistenteChatController::class)->group(function () {
    Route::get('estado', 'estado')->name('estado');
    Route::get('regras', 'regras')->name('regras');
    Route::put('regras', 'guardarRegras')->name('regras.guardar');
    Route::get('conversas', 'conversas')->name('conversas.index');
    Route::get('conversas/{conversa}/mensagens', 'mensagens')->whereNumber('conversa')->name('conversas.mensagens');
    Route::put('conversas/{conversa}', 'renomear')->whereNumber('conversa')->name('conversas.renomear');
    Route::delete('conversas/{conversa}', 'eliminar')->whereNumber('conversa')->name('conversas.eliminar');
    Route::post('mensagens', 'enviar')->middleware('throttle:30,1')->name('mensagens.enviar');
    Route::post('accoes/{accao}/decisao', 'decidir')->whereNumber('accao')->name('accoes.decidir');
});
