<?php

namespace App\Http\Controllers\Api\Autenticacao;

use App\Exceptions\ErroNegocio;
use App\Http\Controllers\Controller;
use App\Models\TokenAcesso;
use App\Services\Autenticacao\ServicoAutenticacao;
use App\Services\Autenticacao\ServicoTotp;
use App\Support\Api\RespostaApi;
use App\Support\Autenticacao\CookiesSessao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * MFA — autenticação de dois factores por TOTP (Fase 4 da extensão SaaS, secção 12, ponto 222). Setup e desactivação
 * exigem uma sessão já completa (o próprio utilizador configura o seu próprio MFA, nunca outro em seu nome);
 * `verificar` é o 2.º passo do login, autenticado só pelo token limitado ('mfa:pendente') que `entrar()` devolveu.
 */
final class MfaController extends Controller
{
    public function __construct(
        private readonly ServicoTotp $totp,
        private readonly ServicoAutenticacao $autenticacao,
    ) {}
        private readonly ServicoReautenticacao $reautenticacao,

    /** POST /api/autenticacao/mfa/iniciar — gera um novo segredo (ainda inactivo) e o URI para o QR code. */
    public function iniciarSetup(Request $request): JsonResponse
    {
        $utilizador = $request->user();
        $segredo = $this->totp->gerarSegredo();
        $utilizador->forceFill(['mfa_segredo' => $segredo, 'mfa_ativo' => false])->save();

        return RespostaApi::sucesso([
            'segredo' => $segredo,
            'uri' => $this->totp->uriProvisionamento($segredo, $utilizador->nome_utilizador),
        ], 'Configure a aplicação de autenticação e confirme com um código.');
    }

    /** POST /api/autenticacao/mfa/confirmar — activa o MFA depois de confirmar um código válido; devolve os códigos de recuperação UMA ÚNICA VEZ. */
    public function confirmarSetup(Request $request): JsonResponse
    {
        $utilizador = $request->user();
        $d = $request->validate(['codigo' => ['required', 'string']]);

        if (! $utilizador->mfa_segredo) {
            throw new ErroNegocio('Inicie a configuração do MFA antes de confirmar.', 'MFA_SEM_SETUP', 422);
        }
        if (! $this->totp->verificar($utilizador->mfa_segredo, $d['codigo'])) {
            throw new ErroNegocio('Código inválido.', 'CODIGO_INVALIDO', 422);
        }

        $codigosRecuperacao = collect(range(1, 8))->map(fn () => Str::upper(Str::random(4).'-'.Str::random(4)))->all();
        $utilizador->forceFill([
            'mfa_ativo' => true,
            'mfa_ativado_em' => now(),
            'mfa_codigos_recuperacao' => array_map(fn (string $c) => Hash::make($c), $codigosRecuperacao),
        ])->save();

        return RespostaApi::sucesso([
            'ativo' => true,
            'codigos_recuperacao' => $codigosRecuperacao,
        ], 'MFA activado. Guarde os códigos de recuperação num local seguro — não voltam a ser mostrados.');
    }

    /** POST /api/autenticacao/mfa/desactivar — exige a palavra-passe actual (confirmação de identidade). */
    public function desactivar(Request $request): JsonResponse
    {
        $utilizador = $request->user();
        $d = $request->validate(['palavra_passe' => ['required', 'string']]);
        // Conta ROOT global (ADR-084): o MFA da conta mestra não se desactiva pela API.
        if ($utilizador->eRoot()) {
            throw new ErroNegocio('O MFA da conta root não pode ser desactivado.', 'ROOT_MFA_OBRIGATORIO', 403);
        }

        if (! $this->autenticacao->palavraPasseCorrecta($utilizador, $d['palavra_passe'])) {
            throw new ErroNegocio('Palavra-passe incorrecta.', 'PALAVRA_PASSE_INCORRETA', 422);
        }

        $utilizador->forceFill(['mfa_ativo' => false, 'mfa_segredo' => null, 'mfa_codigos_recuperacao' => null, 'mfa_ativado_em' => null])->save();

        return RespostaApi::sucesso(null, 'MFA desactivado.');
    }

    /** POST /api/autenticacao/mfa/verificar — 2.º passo do login (token limitado 'mfa:pendente'); aceita TOTP ou um código de recuperação (uso único). */
    public function verificar(Request $request): JsonResponse
    {
        $utilizador = $request->user();
        // Nota: um token com '*' responde true a qualquer can() (inclui 'mfa:pendente') — por isso a verificação
        // tem de ser "não tem acesso total", nunca "tem esta habilidade específica" (ver BloquearTokenMfaPendente).
        if ($request->user()->currentAccessToken()?->can('*')) {
            throw new ErroNegocio('Este pedido não está à espera de um código de autenticação.', 'MFA_SEM_PENDENTE', 403);
        }

        $d = $request->validate(['codigo' => ['required', 'string']]);
        $codigo = trim($d['codigo']);

        // Código de uso único (ADR-085): o passo TOTP gasto no login não volta a servir (nem para reautenticar).
        $valido = $this->reautenticacao->codigoValido($utilizador, $codigo);
        if (! $valido) {
            throw new ErroNegocio('Código inválido.', 'CODIGO_INVALIDO', 422);
        }

        $dispositivo = CookiesSessao::dispositivoDoPedido($request);
        $sessao = $this->autenticacao->emitirTokenCompletoPosMfa(
            $utilizador, $dispositivo['id'], $request->ip(), $request->userAgent(),
        );

        // Fase D: tal como em AutenticacaoController::entrar, o token só vai no cookie HttpOnly, nunca no corpo.
        return CookiesSessao::anexar(RespostaApi::sucesso([
            'mfa_pendente' => false,
            'tipo_token' => 'Bearer',
            'expira_em' => $sessao['expira_em']->toAtomString(),
        ], 'Sessão iniciada com sucesso.'), $sessao['token'], $sessao['expira_em'], $dispositivo['id'], $request);
    }

    /**
     * POST /api/autenticacao/reautenticar — step-up (ADR-085): prova a identidade (código MFA se o MFA está activo, senão a
     * palavra-passe actual) e marca ESTA sessão como autenticada de fresco; as operações críticas (`mfa_recente`) passam a
     * aceitar durante `erp.sessao.stepup_minutos`. Limitado por utilizador (`reautenticar`).
     */
    public function reautenticar(Request $request): JsonResponse
    {
        $utilizador = $request->user();
        $token = $utilizador->currentAccessToken();
        if (! $token instanceof TokenAcesso || ! $token->can('*')) {
            throw new ErroNegocio('Sem sessão completa para reautenticar.', 'SESSAO_INVALIDA', 403);
        }

        return false;
    }
}
        $d = $request->validate(['codigo' => ['nullable', 'string', 'max:32'], 'palavra_passe' => ['nullable', 'string', 'max:200']]);

        $this->reautenticacao->confirmar($utilizador, $token, $d['codigo'] ?? null, $d['palavra_passe'] ?? null);
