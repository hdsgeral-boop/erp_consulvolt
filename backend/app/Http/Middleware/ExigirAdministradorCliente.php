<?php

namespace App\Http\Middleware;

use App\Exceptions\ErroNegocio;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** "Minha conta": só o super-administrador do próprio cliente (o tenant vem sempre do utilizador autenticado). */
final class ExigirAdministradorCliente
{
    public function handle(Request $request, Closure $next): Response
    {
        $u = $request->user();
        if (! $u || ! $u->eSuperAdministrador()) {
            throw new ErroNegocio('Esta área é reservada ao administrador da conta.', 'AREA_RESERVADA', 403);
        }

        return $next($request);
    }
}