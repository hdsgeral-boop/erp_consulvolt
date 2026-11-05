<?php

namespace App\Http\Middleware;

use App\Services\Sistema\ServicoAuditoria;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Auditoria da conta ROOT global (ADR-084): cada pedido autenticado da conta root fica em `logs_auditoria` com
 * `dados_novos.root = true`, o método, o caminho (sem query string nem corpo: nunca dados pessoais), o estado da
 * resposta e a empresa/cliente alvo (definidos por ResolverEmpresaAtiva). As alterações a dados feitas pelos modelos
 * auditáveis já levam a mesma marca (ServicoAuditoria::registar). Uma falha ao auditar não derruba o pedido, mas fica no log.
 */
final class AuditarContaRoot
{
    /** Pedidos de rotina do próprio SPA (sondagens) que só enchem o registo. */
    private const IGNORADOS = ['api/autenticacao/eu', 'api/sistema/notificacoes', 'api/saude'];

    public function __construct(private readonly ServicoAuditoria $auditoria) {}

    public function handle(Request $request, Closure $next): Response
    {
        $resposta = $next($request);

        try {
            $u = $request->user('sanctum');
            if ($u?->eRoot() && ! in_array($request->path(), self::IGNORADOS, true)) {
                $alvo = $request->attributes->get('root_empresa_alvo');
                $this->auditoria->registar(
                    modulo: 'Root',
                    acao: in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true) ? 'Acesso root' : 'Acção root',
                    detalhes: "root=true {$request->method()} /{$request->path()} → {$resposta->getStatusCode()}",
                    empresaId: $alvo !== null ? (int) $alvo : null,
                    utilizadorId: (int) $u->getKey(),
                    nomeUtilizador: $u->nome_utilizador,
                    novos: [
                        'root' => true,
                        'metodo' => $request->method(),
                        'caminho' => '/'.$request->path(),
                        'estado_http' => $resposta->getStatusCode(),
                        'empresa_alvo_id' => $alvo !== null ? (int) $alvo : null,
                        'tenant_alvo_id' => $request->attributes->get('root_tenant_alvo'),
                    ],
                );
            }
        } catch (\Throwable $e) {
            Log::error('Falha ao auditar a conta root: '.$e->getMessage());
        }

        return $resposta;
    }
}
