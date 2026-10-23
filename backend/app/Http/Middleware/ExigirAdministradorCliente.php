<?php

namespace App\Http\Middleware;

use App\Exceptions\ErroNegocio;
use App\Services\Sistema\ServicoPermissoes;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Minha conta": o administrador do próprio cliente (o tenant vem sempre do utilizador autenticado).
 *
 * Usa a mesma noção de "administrador" do resto do sistema (ServicoPermissoes::administrador: papel
 * SUPER_ADMINISTRADOR ou ADMINISTRADOR, ou perfil de acesso total) e não só `eSuperAdministrador()`: o
 * administrador de uma empresa auto-registada (RegistoEmpresaController) fica com papel ADMINISTRADOR —
 * de propósito, para não ficar isento da expiração da licença (ResolverEmpresaAtiva, que isenta
 * SUPER_ADMINISTRADOR) — e precisa, ainda assim, de gerir o seu próprio plano e pagamentos.
 */
final class ExigirAdministradorCliente
{
    public function __construct(private readonly ServicoPermissoes $permissoes) {}

    public function handle(Request $request, Closure $next): Response
    {
        $u = $request->user();
        if (! $u || ! $this->permissoes->administrador($u)) {
            throw new ErroNegocio('Esta área é reservada ao administrador da conta.', 'AREA_RESERVADA', 403);
        }

        return $next($request);
    }
}