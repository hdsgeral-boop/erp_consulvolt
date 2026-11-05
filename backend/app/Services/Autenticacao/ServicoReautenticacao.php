<?php

namespace App\Services\Autenticacao;

use App\Exceptions\ErroNegocio;
use App\Models\TokenAcesso;
use App\Models\Utilizador;
use App\Services\Sistema\ServicoAuditoria;
use Illuminate\Support\Facades\Cache;

/**
 * Step-up (ADR-085): confirmação de identidade RECENTE exigida pelas operações críticas (middleware `mfa_recente`).
 *
 *  - Com MFA activo, a reautenticação é um código TOTP (ou de recuperação) — uso único: o passo de 30 s gasto fica
 *    registado e não serve outra vez, nem em /autenticacao/mfa/verificar.
 *  - Sem MFA, é a palavra-passe actual.
 *
 * O instante fica em `tokens_acesso.reautenticado_em` (por sessão: reautenticar num dispositivo não abre outro). Sem
 * reautenticação, vale o início da sessão (o login é a primeira autenticação).
 */
final class ServicoReautenticacao
{
    /** O passo TOTP gasto fica 2 minutos (cobre a janela ±1 de 30 s com folga). A chave só leva o id do utilizador e o contador. */
    private const TTL_PASSO_SEGUNDOS = 120;

    public function __construct(
        private readonly ServicoTotp $totp,
        private readonly ServicoAutenticacao $autenticacao,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    public function metodo(Utilizador $u): string
    {
        return $u->mfa_ativo ? 'codigo_mfa' : 'palavra_passe';
    }

    /** A autenticação forte desta sessão está dentro da janela? */
    public function recente(?TokenAcesso $t, int $minutos): bool
    {
        if ($t === null) {
            return false;
        }
        $referencia = $t->reautenticado_em ?? $t->criado_em;

        return $referencia !== null && $referencia->gt(now()->subMinutes($minutos));
    }

    /** Para o código que decide em runtime (ex.: mudar o e-mail): lança MFA_REQUERIDO se a sessão não tem autenticação recente. */
    public function exigirRecente(Utilizador $u, ?int $minutos = null): void
    {
        if (! config('erp.sessao.stepup_ativo')) {
            return;
        }
        $janela = $minutos ?? (int) config('erp.sessao.stepup_minutos');
        $token = $u->currentAccessToken();
        if (! $token instanceof TokenAcesso || ! $this->recente($token, $janela)) {
            throw new ErroNegocio('Esta operação exige uma confirmação de identidade recente.', 'MFA_REQUERIDO', 403,
                ['metodo' => $this->metodo($u), 'janela_minutos' => $janela]);
        }
    }

    /**
     * Confirma a identidade e marca a sessão. `codigo` (MFA) ou `palavra_passe` conforme o método do utilizador.
     *
     * @throws ErroNegocio 422 com o código CODIGO_INVALIDO / PALAVRA_PASSE_INCORRETA
     */
    public function confirmar(Utilizador $u, TokenAcesso $t, ?string $codigo, ?string $palavraPasse): void
    {
        if ($u->mfa_ativo) {
            if (! $this->codigoValido($u, (string) $codigo)) {
                $this->falha($u, 'código');
                throw new ErroNegocio('Código inválido.', 'CODIGO_INVALIDO', 422);
            }
        } elseif (! $this->autenticacao->palavraPasseCorrecta($u, (string) $palavraPasse)) {
            $this->falha($u, 'palavra-passe');
            throw new ErroNegocio('Palavra-passe incorrecta.', 'PALAVRA_PASSE_INCORRETA', 422);
        }

        $t->forceFill(['reautenticado_em' => now()])->save();
        $this->auditoria->registar('Autenticação', 'Reautenticação', 'Identidade confirmada para operações críticas ('.$this->metodo($u).').',
            'utilizadores', $u->getKey(), utilizadorId: $u->getKey(), nomeUtilizador: $u->nome_utilizador);
    }

    /** TOTP de uso único ou código de recuperação (que se consome). */
    public function codigoValido(Utilizador $u, string $codigo): bool
    {
        $codigo = trim($codigo);
        $passo = $u->mfa_segredo ? $this->totp->passoValido((string) $u->mfa_segredo, $codigo) : null;
        if ($passo !== null) {
            // Cache::add é atómico: só o primeiro a gastar o passo ganha (dois pedidos em corrida não passam ambos).
            return Cache::add('mfa:passo:'.$u->getKey().':'.$passo, 1, self::TTL_PASSO_SEGUNDOS);
        }

        return $this->consumirCodigoRecuperacao($u, $codigo);
    }

    private function consumirCodigoRecuperacao(Utilizador $u, string $codigo): bool
    {
        $hashes = $u->mfa_codigos_recuperacao ?? [];
        foreach ($hashes as $indice => $hash) {
            if (password_verify($codigo, $hash)) {
                unset($hashes[$indice]);
                $u->forceFill(['mfa_codigos_recuperacao' => array_values($hashes)])->save();

                return true;
            }
        }

        return false;
    }

    private function falha(Utilizador $u, string $oque): void
    {
        $this->auditoria->registar('Autenticação', 'Falha de reautenticação', "Reautenticação falhada ({$oque}).",
            'utilizadores', $u->getKey(), utilizadorId: $u->getKey(), nomeUtilizador: $u->nome_utilizador);
    }
}
