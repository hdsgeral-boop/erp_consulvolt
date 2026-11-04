<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Respostas da API nunca ficam em cache de navegador, proxy ou disco (`Cache-Control: no-store`, `Pragma: no-cache`):
 * autenticação, perfil, menu, dados de negócio e ficheiros descarregados são pessoais. A única excepção são as rotas com
 * `cache_condicional` (logótipo e identidade: ETag/304, sem dados pessoais), que definem o seu próprio Cache-Control.
 */
final class CabecalhosSemCache
{
    public function handle(Request $request, Closure $next): Response
    {
        $resposta = $next($request);

        if ($request->attributes->get('cache_condicional') === true) {
            return $resposta;
        }
        // Ficheiros privados que o controlador decidiu explicitamente guardar poucos minutos no navegador do próprio
        // utilizador (comprovativos, anexos de suporte: `private, max-age=600`) mantêm essa decisão.
        if ($resposta->headers->hasCacheControlDirective('max-age') || $resposta->headers->hasCacheControlDirective('public')) {
            return $resposta;
        }

        $resposta->headers->set('Cache-Control', 'no-store, private');
        $resposta->headers->set('Pragma', 'no-cache');
        $resposta->headers->set('Expires', '0');

        return $resposta;
    }
}
