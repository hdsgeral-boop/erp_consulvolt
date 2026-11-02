<?php

namespace App\Http\Controllers\Api\Autenticacao;

use App\Http\Controllers\Controller;
use App\Models\Utilizador;
use App\Services\Autenticacao\ServicoAutenticacao;
use App\Services\Sistema\ServicoAuditoria;
use App\Support\Autenticacao\CookiesSessao;
use App\Support\Tenancy\ContextoTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * Login com o Google (OAuth2/OIDC) — navegação de página inteira, nunca AJAX: o browser sai da SPA para o Google e
 * volta a um destino da própria SPA já com o cookie HttpOnly da sessão definido (CookiesSessao), tal como o login
 * por password. Fora de /api (ver routes/web.php) porque o handshake OAuth precisa da sessão Laravel nativa
 * (guarda o `state` anti-CSRF do Socialite) — nada disto usa ou troca a sessão Laravel pela do ERP.
 */
final class AutenticacaoGoogleController extends Controller
{
    public function __construct(
        private readonly ServicoAutenticacao $autenticacao,
        private readonly ServicoAuditoria $auditoria,
        private readonly ContextoTenant $tenant,
    ) {}

    /** GET /auth/google/redirecionar */
    public function redirecionar(): RedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }

    /** GET /auth/google/callback */
    public function callback(Request $r): RedirectResponse
    {
        try {
            $perfilGoogle = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            Log::warning('Login Google: falha a obter o perfil do Google.', ['erro' => $e->getMessage()]);

            return redirect('/entrar?erro=google_falhou');
        }

        $emailVerificado = filter_var($perfilGoogle->user['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $googleId = (string) $perfilGoogle->getId();
        $email = (string) $perfilGoogle->getEmail();

        // Mesmo mecanismo do login por password: a pesquisa é entre tenants, antes de se saber a que tenant
        // pertence, por isso só pode correr através de funções SECURITY DEFINER dedicadas (RLS esconderia tudo).
        $utilizador = Utilizador::fromQuery('SELECT * FROM login_localizar_utilizador_google_id(?)', [$googleId])->first();

        if (! $utilizador && $emailVerificado && $email !== '') {
            $candidato = Utilizador::fromQuery('SELECT * FROM login_localizar_utilizador(NULL, ?)', [$email])->first();

            if ($candidato && $candidato->google_id !== null && $candidato->google_id !== $googleId) {
                // O email já está ligado a OUTRA conta Google: nunca troca a ligação só porque alguém chegou
                // com o mesmo email (podia ser um email antigo/reatribuído) — rejeita em vez de arriscar o link errado.
                return redirect('/entrar?erro=google_conta_ja_ligada');
            }

            if ($candidato && $candidato->google_id === null) {
                // Primeira vez que esta conta (já existente, criada por password) entra com o Google: liga-a.
                $this->tenant->definir((int) $candidato->tenant_id);
                $candidato->forceFill(['google_id' => $googleId])->saveQuietly();
                $this->auditoria->registar('Autenticação', 'Ligação de conta Google', 'Conta associada ao Google pelo email confirmado.',
                    'utilizadores', $candidato->getKey(), utilizadorId: $candidato->getKey(), nomeUtilizador: $candidato->nome_utilizador);
                $utilizador = $candidato;
            }
        }

        if (! $utilizador) {
            return redirect('/registar?nome='.urlencode($perfilGoogle->getName() ?? '').'&email='.urlencode($email));
        }

        $this->tenant->definir((int) $utilizador->tenant_id);

        if (! $utilizador->ativo) {
            return redirect('/entrar?erro=google_conta_inactiva');
        }

        $sessao = $this->autenticacao->emitirSessaoOuMfa($utilizador, 'web-google', $r->ip(), $r->userAgent());

        if ($sessao['mfa_pendente']) {
            // MFA activo nesta conta: o 2.º passo (código TOTP) ainda não tem ecrã algum no frontend — nem para
            // login por password, nem para este. Falha de forma segura em vez de contornar a exigência do MFA.
            return redirect('/entrar?erro=google_mfa_obrigatorio');
        }

        return CookiesSessao::anexar(redirect('/app'), $sessao['token'], $sessao['expira_em']);
    }
}
