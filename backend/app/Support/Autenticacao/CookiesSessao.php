<?php

namespace App\Support\Autenticacao;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * Cookie HttpOnly do token de sessão + cookie não-HttpOnly do padrão duplo-submit do CSRF (Fase D, reforço de
 * autenticação). O Sanctum continua "só token" (config/sanctum.php guard=[]): isto só muda a ENTREGA do mesmo
 * token Bearer opaco — de corpo JSON para cookie — para nunca ficar acessível a JavaScript (XSS). A leitura do
 * cookie está em AppServiceProvider::configurarSanctum (Sanctum::getAccessTokenFromRequestUsing) e a verificação
 * do CSRF em VerificarCsrfDuploSubmit. Aceita também RedirectResponse (partilha o mesmo ResponseTrait/withCookie
 * do JsonResponse) porque o login Google (AutenticacaoGoogleController) termina num redirect, não numa resposta JSON.
 */
final class CookiesSessao
{
    public static function anexar(JsonResponse|RedirectResponse $resposta, string $token, Carbon $expiraEm): JsonResponse|RedirectResponse
    {
        $minutos = max(1, (int) now()->diffInMinutes($expiraEm));

        return $resposta
            ->withCookie(Cookie::make(config('erp.sessao.cookie_token'), $token, $minutos, null, null, null, true))
            ->withCookie(Cookie::make(config('erp.sessao.cookie_csrf'), Str::random(40), $minutos, null, null, null, false));
    }

    public static function limpar(JsonResponse $resposta): JsonResponse
    {
        return $resposta
            ->withCookie(Cookie::forget(config('erp.sessao.cookie_token')))
            ->withCookie(Cookie::forget(config('erp.sessao.cookie_csrf')));
    }
}
