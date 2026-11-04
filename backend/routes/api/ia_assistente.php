<?php

// Incluído por routes/api.php no grupo autenticado COM empresa activa (prefixo /api).
// Assistente IA do ERP (chat com histórico, anexos, consultas e propostas confirmadas pelo utilizador). Bloco B da integração
// da produção (ADR-081). `ia_chat_view` em todas as rotas (ver AssistenteChatController); limite de pedidos no envio.

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
    Route::post('mensagens', 'enviar')->middleware('throttle:ia-chat')->name('mensagens.enviar');
    Route::post('accoes/{accao}/decisao', 'decidir')->whereNumber('accao')->middleware('throttle:60,1')->name('accoes.decidir');
});
