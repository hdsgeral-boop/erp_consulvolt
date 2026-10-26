<?php

namespace App\Http\Middleware;

use App\Exceptions\ErroNegocio;
use App\Support\Tenancy\DominioPedido;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Isolamento do Portal do Colaborador por subdomínio (portal.<base>, DominioPedido::PORTAL): fora desta área, não faz
 * nada. Dentro dela, só deixa passar rotas de uma lista branca explícita — falha fechado, mesmo que o utilizador
 * tivesse permissão normal para a rota. É esta barreira (e não `DominioPedido::permite()`, que só decide QUEM entra
 * pelo endereço) que dá o isolamento: uma rota nova noutro módulo fica automaticamente de fora deste domínio até
 * alguém decidir, de propósito, acrescentá-la aqui.
 */
final class ExigirAreaPortal
{
    /** Nomes de rota (ou prefixos terminados em `.`) alcançáveis a partir de portal.<base>. */
    private const ROTAS_PERMITIDAS = [
        'portal.acesso',
        'rh.portal.',          // todas as rotas do portal do colaborador (/rh/portal/*)
        'autenticacao.sair',
        'autenticacao.eu',
        'autenticacao.dados_pessoais.',
        'autenticacao.palavra_passe',
        'sistema.empresas.',   // escolha de empresa depois do login
        'sistema.menu',
        'sistema.identidade',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (DominioPedido::de($request)->area !== DominioPedido::PORTAL) {
            return $next($request);
        }

        $nome = (string) $request->route()?->getName();
        $permitida = $nome !== '' && collect(self::ROTAS_PERMITIDAS)->contains(fn ($r) => str_ends_with($r, '.') ? str_starts_with($nome, $r) : $nome === $r);

        if (! $permitida) {
            throw new ErroNegocio('Esta área só dá acesso ao Portal do Colaborador.', 'AREA_RESERVADA_PORTAL', 403);
        }

        return $next($request);
    }
}
