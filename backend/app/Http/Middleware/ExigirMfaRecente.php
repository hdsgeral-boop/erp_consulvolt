<?php

namespace App\Http\Middleware;

use App\Exceptions\ErroNegocio;
use App\Services\Autenticacao\ServicoReautenticacao;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Step-up (ADR-085): a operação crítica só avança se a sessão tem uma autenticação forte recente (login, código MFA ou
 * palavra-passe de reautenticação). Uso: `mfa_recente` (janela de `erp.sessao.stepup_minutos`), `mfa_recente:5` ou, num grupo
 * misto, `mfa_recente:,escrita` (só pedidos que alteram estado, salvo `erp.sessao.stepup_isentas`).
 *
 * Resposta quando falta: 403 `MFA_REQUERIDO`, com `erros.metodo` = `codigo_mfa` | `palavra_passe`. O frontend abre o pedido
 * de código, chama `POST /api/autenticacao/reautenticar` e repete o pedido original (api/cliente.ts).
 * Falha fechada: sem sessão por token de acesso (ex.: token transitório) não há autenticação a provar.
 */
final class ExigirMfaRecente
{
    public function __construct(private readonly ServicoReautenticacao $reautenticacao) {}

    public function handle(Request $request, Closure $next, ?string $minutos = null, ?string $modo = null): Response
    {
        // `mfa_recente:,escrita` num grupo de rotas: só os métodos que alteram estado (e fora das rotas isentas)
        if ($modo === 'escrita' && ($request->isMethodSafe() || $this->isenta($request))) {
            return $next($request);
        }
        $utilizador = $request->user();
        if ($utilizador === null) {
            throw new ErroNegocio('Esta operação exige uma confirmação de identidade recente.', 'MFA_REQUERIDO', 403, ['metodo' => 'palavra_passe']);
        }
        $this->reautenticacao->exigirRecente($utilizador, $minutos !== null && ctype_digit($minutos) ? max(1, (int) $minutos) : null);

        return $next($request);
    }

    /** Rotas de um grupo `escrita` que não são críticas (tratamento diário de tickets, pré-visualizações que usam POST). */
    private function isenta(Request $request): bool
    {
        $nome = (string) $request->route()?->getName();
        foreach ((array) config('erp.sessao.stepup_isentas', []) as $prefixo) {
            if ($nome !== '' && str_starts_with($nome, $prefixo)) {
                return true;
            }
        }

        return false;
    }
}
