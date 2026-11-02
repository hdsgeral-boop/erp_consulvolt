<?php

use App\Http\Controllers\Api\Autenticacao\AutenticacaoGoogleController;
use Illuminate\Support\Facades\Route;

// O frontend é um SPA React servido pelo Nginx; o Laravel expõe apenas a API (/api) e o health-check (/up).

// Login com o Google: as únicas duas rotas fora de /api, de propósito — o handshake OAuth (parâmetro `state`
// anti-CSRF do Socialite) precisa da sessão Laravel nativa (grupo `web`), não do token Bearer/cookie do ERP.
// `callback` é a URL a registar na Google Cloud Console ("Authorized redirect URIs").
Route::prefix('auth/google')->name('auth.google.')->middleware('throttle:6,1')->group(function () {
    Route::get('redirecionar', [AutenticacaoGoogleController::class, 'redirecionar'])->name('redirecionar');
    Route::get('callback', [AutenticacaoGoogleController::class, 'callback'])->name('callback');
});
