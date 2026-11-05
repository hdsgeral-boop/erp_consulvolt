<?php

namespace App\Http\Middleware;

use App\Exceptions\ErroNegocio;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rotas só da conta ROOT global (ADR-084): negar por omissão. Nem o super-administrador da plataforma passa.
 */
final class ExigirRoot
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->eRoot()) {
            throw new ErroNegocio('Esta área é reservada à conta root.', 'AREA_RESERVADA_ROOT', 403);
        }

        return $next($request);
    }
}
