<?php

namespace App\Http\Middleware;

use App\Models\TokenAcesso;
use App\Services\Autenticacao\ServicoSessoes;
use App\Support\Autenticacao\CookiesSessao;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rotação do token de sessão (ADR-082): na resposta a um pedido autenticado pelo cookie, se o token tem mais de
 * `erp.sessao.rotacao_minutos`, troca-o por um novo e entrega-o em Set-Cookie. O anterior só é aceite durante a tolerância
 * (pedidos paralelos em voo); depois disso a sua apresentação é reutilização (TokenAcesso::findToken). Clientes que usam
 * `Authorization: Bearer` não rodam (não têm como receber o cookie novo).
 */
final class RotacionarToken
{
    public function __construct(private readonly ServicoSessoes $sessoes) {}

    public function handle(Request $request, Closure $next): Response
    {
        $resposta = $next($request);

        if ($request->bearerToken() || CookiesSessao::token($request) === null) {
            return $resposta;
        }

        $utilizador = $request->user('sanctum');
        $token = $utilizador?->currentAccessToken();
        if (! $token instanceof TokenAcesso || ! $this->sessoes->deveRotacionar($token)) {
            return $resposta;
        }

        // um pedido que já definiu cookies de sessão (login/saída) não é rodado por cima
        foreach ($resposta->headers->getCookies() as $cookie) {
            if ($cookie->getName() === CookiesSessao::nome('token', $request)) {
                return $resposta;
            }
        }

        $novo = $this->sessoes->rotacionar($token);
        if ($novo !== null && $token->expira_em !== null) {
            $resposta->headers->setCookie(CookiesSessao::cookieToken($novo, $token->expira_em, $request));
        }

        return $resposta;
    }
}
