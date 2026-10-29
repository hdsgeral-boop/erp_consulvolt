<?php

namespace App\Http\Middleware;

use App\Exceptions\ErroNegocio;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * As passwords são sempre geradas pelo sistema (nunca escolhidas pelo utilizador) e enviadas por email — na criação
 * da conta, numa reposição pelo administrador ou na recuperação "esqueci-me". Enquanto `deve_alterar_password`
 * estiver activo, bloqueia TODA a API (falha fechado, lista branca explícita, mesmo padrão de `ExigirAreaPortal`) —
 * só deixa passar sair (logout), ler a sessão (para a SPA mostrar o ecrã de troca) e a própria troca de password.
 * Sem permissão normal nenhuma dá acesso a nada enquanto isto não for resolvido: não há forma de ignorar.
 */
final class ExigirTrocaPassword
{
    /** Nomes de rota alcançáveis com `deve_alterar_password=true`. */
    private const ROTAS_PERMITIDAS = [
        'autenticacao.sair',
        'autenticacao.eu',
        'autenticacao.palavra_passe',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $utilizador = $request->user('sanctum');

        if ($utilizador === null || ! $utilizador->deve_alterar_password) {
            return $next($request);
        }

        $nome = (string) $request->route()?->getName();
        if (! in_array($nome, self::ROTAS_PERMITIDAS, true)) {
            throw new ErroNegocio('Tem de trocar a sua password antes de continuar.', 'TROCA_PASSWORD_OBRIGATORIA', 423);
        }

        return $next($request);
    }
}
