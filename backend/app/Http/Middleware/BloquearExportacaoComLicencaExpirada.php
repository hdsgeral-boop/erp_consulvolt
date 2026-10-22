<?php

namespace App\Http\Middleware;

use App\Exceptions\ErroContextoEmpresa;
use App\Support\Tenancy\ContextoEmpresa;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloqueia a geração de documentos (SAF-T, recibos em PDF, cópias de segurança) quando a licença da empresa activa
 * está expirada — mesmo sendo pedidos GET, que `ResolverEmpresaAtiva` trata como leitura e deixa passar (ADR-074:
 * "só de leitura" significa consultar ecrãs, não extrair ficheiros). Aplicar apenas às rotas de exportação, depois
 * do middleware `empresa` (precisa da empresa já resolvida em `ContextoEmpresa`).
 */
final class BloquearExportacaoComLicencaExpirada
{
    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ResolverEmpresaAtiva $resolverEmpresaAtiva,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $utilizador = $request->user();
        $empresaId = $this->contexto->id();

        if ($utilizador && $empresaId && $this->resolverEmpresaAtiva->licencaBloqueada($request, $utilizador, $empresaId)) {
            throw ErroContextoEmpresa::licencaExpirada();
        }

        return $next($request);
    }
}
