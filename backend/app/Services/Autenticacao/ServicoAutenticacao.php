<?php

namespace App\Services\Autenticacao;

use App\Exceptions\ErroNegocio;
use App\Models\TokenAcesso;
use App\Models\Utilizador;
use App\Services\Sistema\ServicoAuditoria;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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

    public function __construct(
        private readonly VerificadorPasswordLegado $verificadorLegado,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    /**
     * @return array{token: string, expira_em: Carbon, utilizador: Utilizador}
     */
    public function entrar(string $nomeUtilizador, string $palavraPasse, string $dispositivo, ?string $ip, ?string $agente): array
    {
        $utilizador = Utilizador::query()->where('nome_utilizador', $nomeUtilizador)->first();

        // OWASP A07: verificar SEMPRE uma palavra-passe — sem utilizador, contra um hash fictício com o mesmo custo — para
        // o tempo de resposta não revelar se o nome existe (antes: ~ms sem utilizador vs. ~100 ms do Argon2id).
        if (! $utilizador) {
            password_verify($palavraPasse, self::HASH_FICTICIO);   // directo: independente do HASH_DRIVER/verify
        }
        $valida = $utilizador && $this->credencialValida($utilizador, $palavraPasse);

        if (! $utilizador || ! $utilizador->ativo || ! $valida) {
            $this->auditoria->registar('Autenticação', 'Falha de autenticação', "Tentativa falhada para '{$nomeUtilizador}'.",
                'utilizadores', $utilizador?->getKey(), utilizadorId: $utilizador?->getKey(), nomeUtilizador: $nomeUtilizador);

            throw new ErroNegocio('Nome de utilizador ou palavra-passe incorrectos.', 'CREDENCIAIS_INVALIDAS', 401);
        }

        return DB::transaction(function () use ($utilizador, $palavraPasse, $dispositivo, $ip, $agente) {
            $this->modernizarHash($utilizador, $palavraPasse);

            $expiraEm = now()->addHours(config('erp.sessao.validade_horas'));
            $novo = $utilizador->createToken($dispositivo, ['*'], $expiraEm);

            /** @var TokenAcesso $token */
            $token = $novo->accessToken;
            $token->forceFill(['endereco_ip' => $ip, 'agente_utilizador' => $agente ? mb_substr($agente, 0, 500) : null])->save();

            $utilizador->forceFill(['ultimo_acesso_em' => now()])->saveQuietly();

            $this->auditoria->registar('Autenticação', 'Entrar', 'Sessão iniciada.', 'utilizadores', $utilizador->getKey(),
                utilizadorId: $utilizador->getKey(), nomeUtilizador: $utilizador->nome_utilizador);

            return ['token' => $novo->plainTextToken, 'expira_em' => $expiraEm, 'utilizador' => $utilizador];
        });
    }

    public function sair(Utilizador $utilizador): void
    {
        $utilizador->currentAccessToken()?->delete();
        $this->auditoria->registar('Autenticação', 'Sair', 'Sessão terminada.', 'utilizadores', $utilizador->getKey());
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
