<?php

namespace App\Http\Middleware;

use App\Exceptions\ErroNegocio;
use App\Models\Utilizador;
use App\Support\Tenancy\ContextoTenant;
use App\Support\Tenancy\DefinicoesPlataforma;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Área da plataforma SaaS (/api/plataforma/*): o super-administrador do tenant da plataforma, ou um operador do
 * back-office (Fase 4 da extensão SaaS — RBAC granular), e só com o SaaS activo. É a barreira de ENTRADA (negar por
 * omissão): dentro da área, cada controlador sensível ainda exige a chave `plataforma_*_gerir` específica no perfil
 * do operador (ver ServicoPermissoes::elegivelPlataforma/tem) — o super-administrador continua com acesso total.
 */
final class ExigirPlataforma
{
    public function handle(Request $request, Closure $next): Response
    {
        self::exigir($request->user(), $request);

        return $next($request);
    }

    public static function exigir(?Utilizador $utilizador, ?Request $request = null): void
    {
        $plataforma = (int) config('erp.dominio.plataforma_tenant_id', 1);
        $elegivel = $utilizador && (int) $utilizador->tenant_id === $plataforma
            && ($utilizador->eSuperAdministrador() || $utilizador->eOperadorPlataforma());
        if (! $elegivel) {
            throw new ErroNegocio('Esta área é reservada à administração da plataforma.', 'AREA_RESERVADA', 403);
        }

        if (! ContextoTenant::ativo() && ! DefinicoesPlataforma::saasAtivo()) {
            $caminho = $request ? $request->path() : '';
            // Rotas de clientes externos ou relatórios de múltiplos tenants exigem SaaS activo
            if (str_contains($caminho, 'clientes') || str_contains($caminho, 'painel')) {
                throw new ErroNegocio('A área de plataforma só existe com o SaaS activo.', 'SAAS_INATIVO', 403);
            }
        }
    }
}