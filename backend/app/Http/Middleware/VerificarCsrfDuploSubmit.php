<?php

namespace App\Http\Middleware;

use App\Exceptions\ErroNegocio;
use App\Support\Autenticacao\CookiesSessao;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CSRF em duas camadas (ADR-082) para pedidos que alteram estado e se autenticam pelo cookie HttpOnly do token:
 *
 *  1. Duplo-submit LIGADO À SESSÃO: o cookie legível `XSRF-TOKEN` tem de ser igual ao cabeçalho `X-XSRF-TOKEN` e o seu valor
 *     (`nonce.mac`) tem de ter o HMAC do id desta sessão (CookiesSessao::csrfValido). Um cookie plantado por um sub-domínio, ou
 *     emitido para outra sessão, não passa — com o duplo-submit simples bastava igualar cookie e cabeçalho.
 *  2. Origem: `Origin` (e `Sec-Fetch-Site`) tem de ser do próprio anfitrião do pedido ou de uma origem configurada. Cada
 *     cliente e o portal falam com a API no seu próprio sub-domínio (mesma origem), por isso nenhum sub-domínio vizinho é aceite.
 *     Pedidos sem estes cabeçalhos (clientes que não são navegadores) passam por esta camada e ficam só com a primeira.
 *
 * Pedidos com `Authorization: Bearer` nunca são enviados automaticamente por um navegador a mando de outro site: isentos.
 */
class VerificarCsrfDuploSubmit
{
    private const METODOS_SEGUROS = ['GET', 'HEAD', 'OPTIONS'];

    public function handle(Request $request, Closure $next): Response
    {
        $token = CookiesSessao::token($request);

        if (! $token || in_array($request->method(), self::METODOS_SEGUROS, true) || $request->bearerToken()) {
            return $next($request);
        }

        if (config('erp.sessao.verificar_origem') && ! $this->origemPermitida($request)) {
            throw new ErroNegocio('Pedido de origem não permitida.', 'ORIGEM_INVALIDA', 403);
        }

        $cookieCsrf = (string) $request->cookies->get(CookiesSessao::nome('csrf', $request));
        $cabecalho = (string) $request->header(config('erp.sessao.cabecalho_csrf'));

        if ($cookieCsrf === '' || $cabecalho === '' || ! hash_equals($cookieCsrf, $cabecalho)
            || ! CookiesSessao::csrfValido($cookieCsrf, CookiesSessao::idDoToken($token))) {
            throw new ErroNegocio('Sessão inválida. Actualize a página e tente novamente.', 'CSRF_INVALIDO', 419);
        }

        return $next($request);
    }

    private function origemPermitida(Request $request): bool
    {
        if ($request->headers->get('Sec-Fetch-Site') === 'cross-site') {
            return false;
        }

        $origem = $request->headers->get('Origin');
        if ($origem === null || $origem === '') {
            return true;   // pedido sem Origin (não navegador, ou navegador antigo): fica a primeira camada
        }
        if ($origem === 'null') {
            return false;  // contexto opaco (iframe sandbox, ficheiro local, redirecção entre origens)
        }

        $partes = parse_url($origem);
        if (! is_array($partes) || empty($partes['host'])) {
            return false;
        }
        $anfitriao = strtolower($partes['host']).(isset($partes['port']) ? ':'.$partes['port'] : '');
        $proprio = strtolower($request->getHttpHost());
        if ($anfitriao === $proprio || strtolower($partes['host']) === strtolower($request->getHost())
            && (! isset($partes['port']) || (int) $partes['port'] === (int) $request->getPort())) {
            return true;
        }

        $extra = array_filter(array_map(fn ($o) => rtrim(strtolower(trim($o)), '/'), array_merge(
            (array) config('cors.allowed_origins'),
            explode(',', (string) config('erp.sessao.origens_permitidas')),
        )));

        return in_array(rtrim(strtolower($origem), '/'), $extra, true);
    }
}
