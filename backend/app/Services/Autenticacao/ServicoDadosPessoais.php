<?php

namespace App\Services\Autenticacao;

use App\Exceptions\ErroNegocio;
use App\Models\Utilizador;
use App\Services\Sistema\ServicoAuditoria;
use App\Services\Sistema\ServicoUtilizadores;
use Illuminate\Support\Facades\DB;

/**
 * «Gerir Dados Pessoais» (menu do utilizador): o próprio utilizador altera o nome, o telefone, o e-mail e a palavra-passe.
 * O nome de utilizador, o perfil, o papel e as empresas continuam a ser geridos só pelo administrador (Sistema › Utilizadores).
 * A palavra-passe exige a actual e termina as outras sessões abertas (OWASP A07); tudo fica nos logs de auditoria, sem
 * guardar palavras-passe.
 */
final class ServicoDadosPessoais
{
    public function __construct(
        private readonly ServicoAutenticacao $autenticacao,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    /** @return array<string, mixed> */
    public function obter(Utilizador $u): array
    {
        return [
            'nome_utilizador' => $u->nome_utilizador,
            'nome_completo' => $u->nome_completo,
            'telefone' => $u->telefone,
            'email' => $u->email,
            'palavra_passe_alterada_em' => $u->palavra_passe_alterada_em?->toAtomString(),
            'minimo_palavra_passe' => ServicoUtilizadores::MINIMO_PALAVRA_PASSE,
        ];
    }

    /** @param  array{nome_completo: string, telefone?: ?string, email?: ?string}  $d */
    public function atualizar(Utilizador $u, array $d): Utilizador
    {
        $limpo = fn (?string $v) => ($v = trim((string) $v)) === '' ? null : $v;
        $novos = ['nome_completo' => trim($d['nome_completo']), 'telefone' => $limpo($d['telefone'] ?? null), 'email' => $limpo($d['email'] ?? null)];
        if ($novos['email'] !== null) {
            $novos['email'] = mb_strtolower($novos['email']);
        }
        $anteriores = ['nome_completo' => $u->nome_completo, 'telefone' => $u->telefone, 'email' => $u->email];
        if ($anteriores == $novos) {
            return $u;
        }

        return DB::transaction(function () use ($u, $novos, $anteriores) {
            $u->forceFill($novos)->save();
            $this->auditoria->registar('Sistema/Utilizadores', 'Alterar dados pessoais', "O utilizador '{$u->nome_utilizador}' alterou os seus dados pessoais.",
                'utilizadores', $u->id, $anteriores, $novos);

            return $u->refresh();
        });
    }

    /** @return int sessões terminadas (as outras; a actual continua) */
    public function alterarPalavraPasse(Utilizador $u, string $actual, string $nova): int
    {
        if (! $this->autenticacao->palavraPasseCorrecta($u, $actual)) {
            throw new ErroNegocio('A palavra-passe actual não está correcta.', 'PALAVRA_PASSE_ACTUAL_ERRADA', 422);
        }
        if (mb_strlen($nova) < ServicoUtilizadores::MINIMO_PALAVRA_PASSE) {
            throw new ErroNegocio('A nova palavra-passe tem de ter pelo menos '.ServicoUtilizadores::MINIMO_PALAVRA_PASSE.' caracteres.', 'PALAVRA_PASSE_CURTA', 422);
        }
        if (hash_equals($actual, $nova)) {
            throw new ErroNegocio('A nova palavra-passe tem de ser diferente da actual.', 'PALAVRA_PASSE_IGUAL', 422);
        }

        return DB::transaction(function () use ($u, $nova) {
            // Argon2id pelo cast «hashed» do model; a credencial PBKDF2 do legado deixa de existir
            $u->palavra_passe = $nova;
            $u->forceFill(['hash_password_legado' => null, 'salt_password_legado' => null, 'algoritmo_password_legado' => null, 'palavra_passe_alterada_em' => now()])->save();
            $actual = $u->currentAccessToken();
            $outras = $u->tokens()->when($actual?->getKey(), fn ($q, $id) => $q->whereKeyNot($id))->delete();
            $this->auditoria->registar('Sistema/Utilizadores', 'Alterar palavra-passe', "O utilizador '{$u->nome_utilizador}' alterou a sua palavra-passe ({$outras} outra(s) sessão(ões) terminada(s)).",
                'utilizadores', $u->id);

            return $outras;
        });
    }
}
