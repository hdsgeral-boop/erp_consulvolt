<?php

namespace App\Services\Autenticacao;

use App\Exceptions\ErroNegocio;
use App\Mail\RecuperarSenhaMail;
use App\Models\Utilizador;
use App\Services\Sistema\ServicoAuditoria;
use App\Services\Sistema\ServicoUtilizadores;
use App\Support\Tenancy\DominioPedido;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Recuperação de palavra-passe por e-mail ou nome de utilizador.
 *  - O token aleatório só existe no e-mail; na base de dados fica o hash (sha256), com validade curta e uso único.
 *  - A ligação usa o host do pedido (cada cliente tem o seu subdomínio), mas só se pertencer ao domínio base.
 *  - O utilizador tem de poder usar a área do host (DominioPedido::permite): nunca se recupera a conta de outro cliente.
 */
final class ServicoRecuperacaoSenha
{
    public const VALIDADE_MINUTOS = 60;

    public function __construct(private readonly ServicoAuditoria $auditoria) {}

    /** A resposta ao cliente é sempre a mesma; aqui só se decide se há e-mail a enviar. */
    public function solicitar(string $identificador, Request $pedido): void
    {
        $identificador = trim($identificador);
        $dominio = DominioPedido::de($pedido);
        $origem = $this->origemConfiavel($pedido, $dominio);
        if ($identificador === '' || $origem === null) {
            return;
        }

        $candidatos = Utilizador::query()->where('ativo', true)
            ->where(fn ($q) => $q->where('nome_utilizador', $identificador)->orWhereRaw('lower(email) = ?', [mb_strtolower($identificador)]))
            ->limit(5)->get();

        foreach ($candidatos as $utilizador) {
            if (trim((string) $utilizador->email) === '' || ! $dominio->permite($utilizador)) {
                continue;
            }
            $token = Str::random(64);
            DB::table('recuperacoes_senha')->where('utilizador_id', $utilizador->id)->whereNull('usado_em')->delete();
            DB::table('recuperacoes_senha')->insert([
                'utilizador_id' => $utilizador->id,
                'token_hash' => hash('sha256', $token),
                'expira_em' => now()->addMinutes(self::VALIDADE_MINUTOS),
                'endereco_ip' => $pedido->ip(),
                'criado_em' => now(),
            ]);

            try {
                Mail::to($utilizador->email)->send(new RecuperarSenhaMail(
                    $utilizador->nome_completo ?: $utilizador->nome_utilizador,
                    $origem.'/redefinir-senha?token='.$token,
                    self::VALIDADE_MINUTOS,
                ));
            } catch (Throwable $e) {
                report($e);   // a resposta continua genérica
            }

            $this->auditoria->registar('Autenticação', 'Pedido de recuperação de palavra-passe', 'Ligação de redefinição enviada por e-mail.',
                'utilizadores', $utilizador->id, utilizadorId: $utilizador->id, nomeUtilizador: $utilizador->nome_utilizador);
        }
    }

    /** @return int sessões (tokens) terminadas */
    public function redefinir(string $token, string $novaPalavraPasse, Request $pedido): int
    {
        ServicoUtilizadores::exigirForca($novaPalavraPasse);
        $dominio = DominioPedido::de($pedido);

        return DB::transaction(function () use ($token, $novaPalavraPasse, $dominio) {
            $registo = DB::table('recuperacoes_senha')->where('token_hash', hash('sha256', $token))
                ->whereNull('usado_em')->where('expira_em', '>', now())->lockForUpdate()->first();
            $utilizador = $registo ? Utilizador::query()->find($registo->utilizador_id) : null;
            if (! $utilizador || ! $utilizador->ativo || ! $dominio->permite($utilizador)) {
                throw new ErroNegocio('A ligação de recuperação é inválida ou expirou. Peça uma nova.', 'TOKEN_RECUPERACAO_INVALIDO', 422);
            }

            DB::table('recuperacoes_senha')->where('id', $registo->id)->update(['usado_em' => now()]);
            DB::table('recuperacoes_senha')->where('utilizador_id', $utilizador->id)->whereNull('usado_em')->delete();

            $utilizador->palavra_passe = $novaPalavraPasse;   // Argon2id pelo cast «hashed»
            $utilizador->forceFill(['hash_password_legado' => null, 'salt_password_legado' => null, 'algoritmo_password_legado' => null, 'palavra_passe_alterada_em' => now(), 'alterar_palavra_passe' => false])->save();
            $terminadas = $utilizador->tokens()->delete();

            $this->auditoria->registar('Autenticação', 'Redefinir palavra-passe', "Palavra-passe redefinida por recuperação ({$terminadas} sessão(ões) terminada(s)).",
                'utilizadores', $utilizador->id, utilizadorId: $utilizador->id, nomeUtilizador: $utilizador->nome_utilizador);

            return $terminadas;
        });
    }

    /** Origem (esquema + host) para a ligação; null quando o host não é de confiança (fora do domínio base definido). */
    private function origemConfiavel(Request $pedido, DominioPedido $dominio): ?string
    {
        $base = strtolower(trim((string) config('erp.dominio.base')));
        if ($base !== '' && in_array($dominio->area, [DominioPedido::LIVRE, DominioPedido::DESCONHECIDA], true)) {
            return null;
        }

        return $pedido->getSchemeAndHttpHost();
    }
}
