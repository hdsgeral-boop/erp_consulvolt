<?php

namespace App\Http\Middleware;

use App\Exceptions\ErroNegocio;
use App\Models\Utilizador;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Backoffice de suporte (/api/suporte/*): tenant da plataforma, super-administrador OU agente de suporte
 * (ADR-076, fase 4 fatia 3). Isolado de /api/plataforma/* (ExigirPlataforma, só super-administrador): um agente
 * nunca alcança clientes, planos, licenciamento nem definições globais — só tickets, aqui.
 */
final class ExigirSuporte
{
    public function handle(Request $request, Closure $next): Response
    {
        self::exigir($request->user());

        return $next($request);
    }

    public static function exigir(?Utilizador $utilizador): void
    {
        $plataforma = (int) config('erp.dominio.plataforma_tenant_id', 1);
        $elegivel = $utilizador && (int) $utilizador->tenant_id === $plataforma && ($utilizador->eSuperAdministrador() || $utilizador->eAgenteSuporte());

        if (! $elegivel) {
            throw new ErroNegocio('Esta área é reservada à equipa de suporte da plataforma.', 'AREA_RESERVADA', 403);
        }
    }
}
