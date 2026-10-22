<?php

namespace App\Services\Autenticacao;

use App\Exceptions\ErroNegocio;
use App\Models\Tenant;
use App\Models\TokenAcesso;
use App\Models\Utilizador;
use App\Services\Sistema\ServicoAuditoria;
use App\Support\Tenancy\DominioPedido;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Autenticação por nome de utilizador + palavra-passe e emissão de tokens Bearer (Sanctum).
 *
 * Paridade com o legado (js/data/servicos.js:17-33):
 *  - o nome de utilizador é comparado de forma exacta;
 *  - utilizadores migrados autenticam com o hash PBKDF2 legado e ficam, nesse login, com Argon2id.
 * Melhorias: mensagem de erro única (não revela se o utilizador existe), limite de tentativas,
 * expiração por inactividade e absoluta do token, auditoria de sucessos e falhas.
 */
final class ServicoAutenticacao
{
    /** Argon2id (m=64 MB, t=4, p=1 — os parâmetros por omissão) de um valor aleatório descartado: não corresponde a nada. */
    private const HASH_FICTICIO = '$argon2id$v=19$m=65536,t=4,p=1$bkZkQUxvL3JVakpReHhJYw$MH9I3Pf+vIbFspAiZQ0VW63mR4LIafms2plCHJEohnU';

    /** Chave na cache (Redis) do código de entrada; guarda-se o SHA-256 do código, nunca o código. */
    private const PREFIXO_CODIGO = 'autenticacao:codigo-entrada:';

    public function __construct(
        private readonly VerificadorPasswordLegado $verificadorLegado,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    /**
     * @return array{token: string, expira_em: Carbon, utilizador: Utilizador}
     */
    public function entrar(string $nomeUtilizador, string $palavraPasse, string $dispositivo, ?string $ip, ?string $agente, ?DominioPedido $dominio = null): array
    {
        $utilizador = $this->validar($nomeUtilizador, $palavraPasse, $dominio);

        return DB::transaction(function () use ($utilizador, $palavraPasse, $dispositivo, $ip, $agente) {
            $this->modernizarHash($utilizador, $palavraPasse);

            return $this->emitirSessao($utilizador, $dispositivo, $ip, $agente);
        });
    }

    /**
     * Entrada pelo domínio base (meuerp.ao): valida as credenciais e devolve um código de uso único (60 s) para o
     * endereço do cliente do utilizador trocar por uma sessão. A palavra-passe nunca sai deste pedido.
     *
     * @return array{codigo: string, utilizador: Utilizador}
     */
    public function emitirCodigoEntrada(string $nomeUtilizador, string $palavraPasse): array
    {
        $utilizador = $this->validar($nomeUtilizador, $palavraPasse, null);
        DB::transaction(fn () => $this->modernizarHash($utilizador, $palavraPasse));

        $codigo = Str::random(48);
        Cache::put(self::PREFIXO_CODIGO.hash('sha256', $codigo), $utilizador->getKey(), now()->addSeconds(60));
        $this->auditoria->registar('Autenticação', 'Código de entrada', 'Credenciais validadas no domínio base; a reencaminhar para o endereço do cliente.',
            'utilizadores', $utilizador->getKey(), utilizadorId: $utilizador->getKey(), nomeUtilizador: $utilizador->nome_utilizador);

        return ['codigo' => $codigo, 'utilizador' => $utilizador];
    }

    /**
     * Troca o código de uso único por uma sessão, só no endereço a que o utilizador pertence.
     *
     * @return array{token: string, expira_em: Carbon, utilizador: Utilizador}
     */
    public function trocarCodigo(string $codigo, string $dispositivo, ?string $ip, ?string $agente, ?DominioPedido $dominio = null): array
    {
        $id = Cache::pull(self::PREFIXO_CODIGO.hash('sha256', $codigo));
        $utilizador = $id ? Utilizador::query()->find($id) : null;

        if (! $utilizador || ! $utilizador->ativo || ($dominio !== null && ! $dominio->permite($utilizador))) {
            throw new ErroNegocio('O código de entrada é inválido ou expirou. Entre novamente.', 'CODIGO_ENTRADA_INVALIDO', 401);
        }

        return DB::transaction(fn () => $this->emitirSessao($utilizador, $dispositivo, $ip, $agente));
    }

    /** Endereço (https://<slug>.<base>) onde o utilizador trabalha; null sem domínio base ou sem cliente. */
    public function enderecoDoUtilizador(Utilizador $utilizador): ?string
    {
        $base = strtolower(trim((string) config('erp.dominio.base')));
        if ($base === '' || $utilizador->tenant_id === null) {
            return null;
        }
        $prefixo = (int) $utilizador->tenant_id === (int) config('erp.dominio.plataforma_tenant_id')
            ? (string) config('erp.dominio.prefixo_plataforma')
            : Tenant::query()->whereKey($utilizador->tenant_id)->value('slug');

        return $prefixo ? "https://{$prefixo}.{$base}" : null;
    }

    private function validar(string $nomeUtilizador, string $palavraPasse, ?DominioPedido $dominio): Utilizador
    {
        $utilizador = Utilizador::query()->where('nome_utilizador', $nomeUtilizador)->first() ?? $this->porEmail($nomeUtilizador);

        // OWASP A07: verificar SEMPRE uma palavra-passe — sem utilizador, contra um hash fictício com o mesmo custo — para
        // o tempo de resposta não revelar se o nome existe (antes: ~ms sem utilizador vs. ~100 ms do Argon2id).
        if (! $utilizador) {
            password_verify($palavraPasse, self::HASH_FICTICIO);   // directo: independente do HASH_DRIVER/verify
        }
        $valida = $utilizador && $this->credencialValida($utilizador, $palavraPasse);

        // SaaS: credenciais certas num endereço que não é do utilizador (ex.: conta de outro cliente) são recusadas com a
        // MESMA mensagem, para não revelar que a conta existe noutro cliente. Em `livre` (sem domínio base) `permite()` é sempre verdadeiro.
        $enderecoErrado = $valida && $utilizador->ativo && $dominio !== null && ! $dominio->permite($utilizador);

        if (! $utilizador || ! $utilizador->ativo || ! $valida || $enderecoErrado) {
            $detalhe = $enderecoErrado
                ? "Tentativa recusada para '{$nomeUtilizador}': o endereço usado ({$dominio->area}) não pertence a este utilizador."
                : "Tentativa falhada para '{$nomeUtilizador}'.";
            $this->auditoria->registar('Autenticação', 'Falha de autenticação', $detalhe,
                'utilizadores', $utilizador?->getKey(), utilizadorId: $utilizador?->getKey(), nomeUtilizador: $nomeUtilizador);

            throw new ErroNegocio('Nome de utilizador ou palavra-passe incorrectos.', 'CREDENCIAIS_INVALIDAS', 401);
        }

        return $utilizador;
    }

    /**
     * Entrar também com o e-mail (pedido de 2026-10-22). O e-mail não é único: se houver mais do que uma conta activa
     * com ele (no âmbito do endereço usado), pede-se o nome de utilizador em vez de adivinhar qual é.
     */
    private function porEmail(string $texto): ?Utilizador
    {
        $email = mb_strtolower(trim($texto));
        if (! str_contains($email, '@')) {
            return null;
        }
        $contas = Utilizador::query()->whereRaw('lower(email) = ?', [$email])->where('ativo', true)->limit(2)->get();
        if ($contas->count() > 1) {
            throw new ErroNegocio('Este e-mail está associado a mais de uma conta: entre com o nome de utilizador.', 'EMAIL_AMBIGUO', 401);
        }

        return $contas->first();
    }

    /** @return array{token: string, expira_em: Carbon, utilizador: Utilizador} */
    private function emitirSessao(Utilizador $utilizador, string $dispositivo, ?string $ip, ?string $agente): array
    {
        $expiraEm = now()->addHours(config('erp.sessao.validade_horas'));
        $novo = $utilizador->createToken($dispositivo, ['*'], $expiraEm);

        /** @var TokenAcesso $token */
        $token = $novo->accessToken;
        $token->forceFill(['endereco_ip' => $ip, 'agente_utilizador' => $agente ? mb_substr($agente, 0, 500) : null])->save();

        $utilizador->forceFill(['ultimo_acesso_em' => now()])->saveQuietly();

        $this->auditoria->registar('Autenticação', 'Entrar', 'Sessão iniciada.', 'utilizadores', $utilizador->getKey(),
            utilizadorId: $utilizador->getKey(), nomeUtilizador: $utilizador->nome_utilizador);

        return ['token' => $novo->plainTextToken, 'expira_em' => $expiraEm, 'utilizador' => $utilizador];
    }

    public function sair(Utilizador $utilizador): void
    {
        $utilizador->currentAccessToken()?->delete();
        $this->auditoria->registar('Autenticação', 'Sair', 'Sessão terminada.', 'utilizadores', $utilizador->getKey());
    }

    /** Confirma a palavra-passe actual do utilizador (hash moderno ou PBKDF2 do legado), sem iniciar sessão. */
    public function palavraPasseCorrecta(Utilizador $utilizador, string $palavraPasse): bool
    {
        return $this->credencialValida($utilizador, $palavraPasse);
    }

    private function credencialValida(Utilizador $utilizador, string $palavraPasse): bool
    {
        if ($utilizador->palavra_passe !== null) {
            return Hash::check($palavraPasse, $utilizador->palavra_passe);
        }

        if ($utilizador->temCredencialLegada()) {
            return $this->verificadorLegado->verificar(
                $palavraPasse,
                $utilizador->hash_password_legado,
                (string) $utilizador->salt_password_legado,
                $utilizador->algoritmo_password_legado,
            );
        }

        return false;
    }

    /** Converte o hash PBKDF2 legado (ou um hash moderno desactualizado) para o algoritmo actual. */
    private function modernizarHash(Utilizador $utilizador, string $palavraPasse): void
    {
        if ($utilizador->temCredencialLegada()) {
            $utilizador->forceFill([
                'palavra_passe' => Hash::make($palavraPasse),
                'hash_password_legado' => null,
                'salt_password_legado' => null,
                'algoritmo_password_legado' => null,
            ])->saveQuietly();

            $this->auditoria->registar('Autenticação', 'Migração de palavra-passe',
                'Hash PBKDF2 do legado substituído por Argon2id no primeiro login.', 'utilizadores', $utilizador->getKey(),
                utilizadorId: $utilizador->getKey(), nomeUtilizador: $utilizador->nome_utilizador);

            return;
        }

        if (Hash::needsRehash($utilizador->palavra_passe)) {
            $utilizador->forceFill(['palavra_passe' => Hash::make($palavraPasse)])->saveQuietly();
        }
    }
}
