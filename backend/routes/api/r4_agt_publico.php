<?php

// Incluído por routes/api.php FORA do grupo autenticado (prefixo /api). Ronda 4 — agente r4_agt.
// Notificações da AGT por empresa: sem sessão, autenticadas por HMAC + carimbo + nonce (ServicoWebhookAgt).

use App\Http\Controllers\Api\Vendas\WebhookAgtController;
use Illuminate\Support\Facades\Route;

Route::post('agt/webhook/{token}', WebhookAgtController::class)->where('token', '[A-Za-z0-9_-]{20,100}')
    ->middleware('throttle:60,1')->name('agt.webhook');
