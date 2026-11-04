<?php

namespace App\Support\Autenticacao;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Cookies de sessão (ADR-082, docs/arquitetura/SESSOES_E_COOKIES.md):
 *
 *  - token       HttpOnly, Secure (HTTPS), SameSite=Strict, Path=/, sem Domain (só o anfitrião que o emitiu), `__Host-` com HTTPS;
 *                valor opaco `id|segredo` (256 bits); Max-Age = validade absoluta da sessão;
 *  - dispositivo HttpOnly, idem, valor `id.assinatura` emitido PELO SERVIDOR (HMAC com a chave da aplicação): o cliente
 *                nunca escolhe nem guarda o identificador; uma assinatura inválida equivale a não ter cookie;
 *  - csrf        legível por JavaScript (duplo-submit), ligado à sessão por HMAC (`nonce.mac(id da sessão, nonce)`): um cookie
 *                plantado por um sub-domínio ou por outra sessão não passa a verificação (VerificarCsrfDuploSubmit).
 *
 * Aceita também RedirectResponse (login Google termina num redirect, não numa resposta JSON).
 */
final class CookiesSessao
{
    /** Cookies seguros com HTTPS (directo ou por proxy de confiança) ou se forçado em ERP_COOKIE_SECURE. */
    public static function seguro(?Request $pedido = null): bool
    {
        $forcado = config('erp.sessao.cookie_secure');
        if ($forcado !== null) {
            return (bool) $forcado;
        }

        return ($pedido ?? request())->isSecure();
    }

    /** @param  'token'|'dispositivo'|'csrf'  $qual */
    public static function nome(string $qual, ?Request $pedido = null): string
    {
        $base = match ($qual) {
            'token' => config('erp.sessao.cookie_token'),
            'dispositivo' => config('erp.sessao.cookie_dispositivo'),
            default => config('erp.sessao.cookie_csrf'),
        };

        return (self::seguro($pedido) ? '__Host-' : '').$base;
    }

    /** Token de sessão tal como veio no cookie (ou null). */
    public static function token(Request $pedido): ?string
    {
        $v = $pedido->cookies->get(self::nome('token', $pedido));

        return is_string($v) && $v !== '' ? $v : null;
    }

    public static function anexar(JsonResponse|RedirectResponse $resposta, string $token, Carbon $expiraEm, ?string $dispositivoId = null, ?Request $pedido = null): JsonResponse|RedirectResponse
    {
        $resposta->headers->setCookie(self::cookieToken($token, $expiraEm, $pedido));
        $resposta->headers->setCookie(self::cookieCsrf(self::idDoToken($token), $expiraEm, $pedido));
        if ($dispositivoId !== null) {
            self::anexarDispositivo($resposta, $dispositivoId, $pedido);
        }

        return $resposta;
    }

    public static function anexarDispositivo(JsonResponse|RedirectResponse $resposta, string $dispositivoId, ?Request $pedido = null): void
    {
        $resposta->headers->setCookie(self::criar(
            self::nome('dispositivo', $pedido), self::assinarDispositivo($dispositivoId),
            now()->addDays(max(1, (int) config('erp.sessao.dispositivo_dias'))), true, $pedido,
        ));
    }

    /** Novo token a meio da sessão (rotação): só muda o cookie do token; o CSRF está ligado ao id da sessão, que não muda. */
    public static function cookieToken(string $token, Carbon $expiraEm, ?Request $pedido = null): Cookie
    {
        return self::criar(self::nome('token', $pedido), $token, $expiraEm, true, $pedido);
    }

    public static function cookieCsrf(int $sessaoId, Carbon $expiraEm, ?Request $pedido = null): Cookie
    {
        return self::criar(self::nome('csrf', $pedido), self::gerarCsrf($sessaoId), $expiraEm, false, $pedido);
    }

    /** Apaga token e CSRF (o cookie do dispositivo mantém-se: identifica o navegador, não a sessão). */
    public static function limpar(JsonResponse $resposta, ?Request $pedido = null): JsonResponse
    {
        foreach (['token' => true, 'csrf' => false] as $qual => $httpOnly) {
            $resposta->headers->setCookie(self::criar(self::nome($qual, $pedido), '', now()->subYear(), $httpOnly, $pedido));
        }

        return $resposta;
    }

    private static function criar(string $nome, string $valor, Carbon $expiraEm, bool $httpOnly, ?Request $pedido): Cookie
    {
        $sameSite = strtolower((string) config('erp.sessao.cookie_samesite', 'strict'));

        // Path=/ e sem Domain: exigidos por __Host- e garantem cookie por anfitrião (cada cliente/portal tem o seu).
        return new Cookie($nome, $valor, $expiraEm->getTimestamp(), '/', null, self::seguro($pedido), $httpOnly, false,
            in_array($sameSite, ['strict', 'lax'], true) ? $sameSite : 'strict');
    }

    public static function idDoToken(string $token): int
    {
        return (int) explode('|', $token, 2)[0];
    }

    // ---- dispositivo ------------------------------------------------------------------------------------------

    /** @return array{id: string, novo: bool} dispositivo do pedido (cookie assinado válido) ou um novo, criado agora. */
    public static function dispositivoDoPedido(Request $pedido): array
    {
        $valor = $pedido->cookies->get(self::nome('dispositivo', $pedido));
        if (is_string($valor) && ($id = self::verificarDispositivo($valor)) !== null) {
            return ['id' => $id, 'novo' => false];
        }

        return ['id' => self::novoIdDispositivo(), 'novo' => true];
    }

    public static function novoIdDispositivo(): string
    {
        return self::b64(random_bytes(16));
    }

    public static function assinarDispositivo(string $id): string
    {
        return $id.'.'.self::b64(hash_hmac('sha256', 'dev|'.$id, self::chave(), true));
    }

    public static function verificarDispositivo(string $valor): ?string
    {
        $partes = explode('.', $valor, 2);
        if (count($partes) !== 2 || ! preg_match('/^[A-Za-z0-9_-]{22}$/', $partes[0])) {
            return null;
        }

        return hash_equals(self::assinarDispositivo($partes[0]), $valor) ? $partes[0] : null;
    }

    // ---- CSRF ligado à sessão -----------------------------------------------------------------------------------

    public static function gerarCsrf(int $sessaoId): string
    {
        $nonce = self::b64(random_bytes(16));

        return $nonce.'.'.self::mac($sessaoId, $nonce);
    }

    public static function csrfValido(string $valor, int $sessaoId): bool
    {
        $partes = explode('.', $valor, 2);

        return count($partes) === 2 && $partes[0] !== '' && hash_equals(self::mac($sessaoId, $partes[0]), $partes[1]);
    }

    private static function mac(int $sessaoId, string $nonce): string
    {
        return self::b64(hash_hmac('sha256', "csrf|{$sessaoId}|{$nonce}", self::chave(), true));
    }

    /** Sub-chave derivada da chave da aplicação (nunca a própria APP_KEY directamente). */
    private static function chave(): string
    {
        $k = (string) config('app.key');
        if (str_starts_with($k, 'base64:')) {
            $k = base64_decode(substr($k, 7), true) ?: $k;
        }

        return hash_hmac('sha256', 'erp-sessoes-v1', $k, true);
    }

    public static function b64(string $binario): string
    {
        return rtrim(strtr(base64_encode($binario), '+/', '-_'), '=');
    }
}
