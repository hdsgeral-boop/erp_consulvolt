<?php

namespace App\Http\Middleware;

use App\Exceptions\ErroNegocio;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * MFA (Fase 4 da extensão SaaS, secção 12): o token limitado que `ServicoAutenticacao::entrar()` devolve enquanto o
 * código TOTP não é confirmado tem só a habilidade 'mfa:pendente' — nunca '*'. Sem esta barreira, qualquer rota que
 * não verifique habilidades Sanctum explicitamente aceitaria esse token como uma sessão completa (Sanctum, por
 * omissão, autentica qualquer token válido independentemente das suas habilidades). Falha fechado, lista branca
 * explícita, mesmo padrão de `ExigirTrocaPassword`/`ExigirAreaPortal`: só deixa passar a verificação do código e sair.
 */
final class BloquearTokenMfaPendente
{
    private const ROTAS_PERMITIDAS = [
        'autenticacao.mfa.verificar',
        'autenticacao.sair',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user('sanctum')?->currentAccessToken();
        // Nota: um token com '*' responde true a qualquer can() (inclui 'mfa:pendente') — por isso a verificação
        // tem de ser "não tem acesso total", nunca "tem esta habilidade específica".
        if ($token === null || $token->can('*')) {
            return $next($request);
        }

        $nome = (string) $request->route()?->getName();
        if (! in_array($nome, self::ROTAS_PERMITIDAS, true)) {
            throw new ErroNegocio('Confirme primeiro o código de autenticação.', 'MFA_PENDENTE', 423);
        }

        return $next($request);
    }
}
