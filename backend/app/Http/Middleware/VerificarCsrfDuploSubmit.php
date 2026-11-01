<?php

namespace App\Http\Middleware;

use App\Exceptions\ErroNegocio;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Padrão de duplo-submit do CSRF (Fase D, reforço de autenticação) — não é o CSRF nativo do Laravel (esse exige
 * sessão 'web' de Blade); aqui o cookie não-HttpOnly e o cabeçalho equivalente são lidos e comparados à mão
 * (CookiesSessao define os dois no login). Só se aplica quando a sessão vem do cookie HttpOnly do token: um
 * pedido com Authorization: Bearer nunca é enviado automaticamente por um browser a mando de outro site, por
 * isso não é vulnerável a CSRF e fica isento (compatibilidade com qualquer cliente que ainda use o cabeçalho).
 */
class VerificarCsrfDuploSubmit
{
    private const METODOS_SEGUROS = ['GET', 'HEAD', 'OPTIONS'];

    public function handle(Request $request, Closure $next): Response
    {
        $cookieToken = $request->cookie(config('erp.sessao.cookie_token'));

        if (! $cookieToken || in_array($request->method(), self::METODOS_SEGUROS, true) || $request->bearerToken()) {
            return $next($request);
        }

        $cookieCsrf = (string) $request->cookie(config('erp.sessao.cookie_csrf'));
        $cabecalho = (string) $request->header(config('erp.sessao.cabecalho_csrf'));

        if ($cookieCsrf === '' || $cabecalho === '' || ! hash_equals($cookieCsrf, $cabecalho)) {
            throw new ErroNegocio('Sessão inválida. Actualize a página e tente novamente.', 'CSRF_INVALIDO', 419);
        }

        return $next($request);
    }
}
