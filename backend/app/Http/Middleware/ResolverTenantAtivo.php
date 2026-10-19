<?php

namespace App\Http\Middleware;

use App\Exceptions\ErroContextoEmpresa;
use App\Models\Tenant;
use App\Support\Tenancy\ContextoTenant;
use App\Support\Tenancy\DominioPedido;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Define o tenant (conta SaaS) da sessão de base de dados a partir do utilizador autenticado, em todos os pedidos
 * da API. Corre antes do `auth:sanctum` das rotas: resolve o token por conta própria (o guard memoriza o resultado).
 *
 *  - Endereço (DominioPedido): subdomínio desconhecido ou cliente inexistente → 404 antes de tudo.
 *  - Utilizador de outro cliente (ou área de plataforma sem ser a da plataforma) → 403.
 *  - Sem token válido: não há tenant; as tabelas de negócio ficam vazias (fail-closed).
 *  - Tenant suspenso: 403 antes de qualquer consulta de negócio.
 *
 * O tenant nunca vem do cliente (cabeçalhos, parâmetros): é sempre o do utilizador autenticado.
 */
final class ResolverTenantAtivo
{
    public function __construct(private readonly ContextoTenant $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        // SaaS desligado (omissão): sem tenant na sessão e sem restrições por endereço.
        if (! ContextoTenant::ativo()) {
            return $next($request);
        }

        $dominio = DominioPedido::de($request);

        if ($dominio->area === DominioPedido::DESCONHECIDA) {
            throw ErroContextoEmpresa::dominioDesconhecido();
        }
        if ($dominio->area === DominioPedido::CLIENTE && $dominio->cliente() === null) {
            throw ErroContextoEmpresa::clienteDesconhecido();
        }

        $utilizador = $request->user('sanctum');

        if ($utilizador === null || $utilizador->tenant_id === null) {
            return $next($request);
        }

        if (! $dominio->permite($utilizador)) {
            throw $dominio->area === DominioPedido::PLATAFORMA
                ? ErroContextoEmpresa::areaReservadaAPlataforma()
                : ErroContextoEmpresa::contaDeOutroCliente();
        }

        $cliente = Tenant::query()->find($utilizador->tenant_id);
        if ($cliente === null || ! $cliente->estaAtivo()) {
            throw ErroContextoEmpresa::tenantSuspenso();
        }

        // Repõe o tenant anterior (em produção é nulo; em testes e chamadas aninhadas, não apaga o contexto de quem chamou).
        $anterior = $this->tenant->id();
        $this->tenant->definir((int) $utilizador->tenant_id);

        try {
            return $next($request);
        } finally {
            $this->tenant->definir($anterior);
        }
    }
}
