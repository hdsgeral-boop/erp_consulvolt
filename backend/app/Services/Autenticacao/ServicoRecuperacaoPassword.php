<?php

namespace App\Services\Autenticacao;

use App\Mail\RecuperacaoPasswordMail;
use App\Models\Utilizador;
use App\Services\Sistema\ServicoAuditoria;
use App\Services\Sistema\ServicoUtilizadores;
use App\Support\Autenticacao\AgenteUtilizador;
use App\Support\Tenancy\ContextoTenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * Recuperação «esqueci-me» (a filosofia do sistema mantém-se: a palavra-passe é SEMPRE gerada pelo sistema e a troca é
 * obrigatória no próximo acesso). Endurecimento contra a negação de serviço de uma conta alheia (ADR-082):
 *
 *  - limite por CONTA (não só por IP) e intervalo mínimo entre pedidos: um terceiro não consegue forçar trocas em série;
 *  - resposta sempre genérica e de tempo uniforme (trabalho equivalente e duração mínima, exista a conta ou não);
 *  - a palavra-passe actual só deixa de valer depois de a nova ter sido ENTREGUE (envio síncrono bem sucedido); se o
 *    envio falhar, nada muda e o titular continua a entrar com a que tinha;
 *  - o titular recebe o e-mail com o dia, a hora e o IP aproximado do pedido (quem o pediu), e tudo fica em auditoria.
 */
final class ServicoRecuperacaoPassword
{
    public function __construct(
        private readonly ServicoUtilizadores $utilizadores,
        private readonly ServicoAuditoria $auditoria,
        private readonly ContextoTenant $tenant,
    ) {}

    public function processar(string $identificador, ?string $ip, ?string $agente): void
    {
        $inicio = hrtime(true);
        $anterior = $this->tenant->id();
        try {
            $this->tratar($identificador, $ip, $agente);
        } catch (Throwable $e) {
            // nunca revela nada ao pedinte: a resposta é a mesma; fica no registo técnico
            Log::warning('Recuperação de palavra-passe: falha ao processar o pedido.', ['erro' => $e->getMessage()]);
        } finally {
            $this->tenant->definir($anterior);
        }
        $this->igualarTempo($inicio);
    }

    private function tratar(string $identificador, ?string $ip, ?string $agente): void
    {
        // Ainda não se sabe a que tenant pertence: pesquisa entre todos, como o login (SECURITY DEFINER).
        $u = Utilizador::fromQuery('SELECT * FROM login_localizar_utilizador(?, ?)', [$identificador, $identificador])->first();

        if (! $u || ! $u->ativo || ! $u->email) {
            Hash::make(Str::random(14));   // trabalho equivalente ao de uma conta real (tempo uniforme)
            $this->auditoria->registar('Autenticação', 'Recuperação de palavra-passe sem efeito', 'Pedido para um identificador sem conta activa com e-mail.',
                nomeUtilizador: mb_substr($identificador, 0, 100));

            return;
        }

        $this->tenant->definir((int) $u->tenant_id);

        $limite = (int) config('erp.sessao.recuperacao_max_por_hora');
        $intervalo = (int) config('erp.sessao.recuperacao_intervalo_segundos');
        $chave = 'recuperacao:'.$u->getKey();
        if (($limite > 0 && RateLimiter::tooManyAttempts($chave, $limite))
            || ($intervalo > 0 && ! Cache::add($chave.':intervalo', 1, $intervalo))) {
            Hash::make(Str::random(14));
            $this->auditoria->registar('Autenticação', 'Recuperação de palavra-passe limitada', 'Pedido ignorado: limite por conta ou intervalo mínimo entre pedidos.',
                'utilizadores', $u->getKey(), utilizadorId: $u->getKey(), nomeUtilizador: $u->nome_utilizador);

            return;
        }
        RateLimiter::hit($chave, 3600);

        $palavraPasse = $this->utilizadores->gerarPalavraPasseAleatoria();

        // Primeiro entrega-se; só depois se troca. Falha de envio => excepção => nada foi alterado.
        Mail::to($u->email)->sendNow(new RecuperacaoPasswordMail(
            $u->nome_utilizador, $u->nome_completo ?: $u->nome_utilizador, $palavraPasse,
            AgenteUtilizador::ipAproximado($ip), now()->format('d/m/Y H:i'), AgenteUtilizador::rotulo($agente),
        ));
        $this->utilizadores->aplicarRecuperacao($u, $palavraPasse);
    }

    /** Duração mínima fixa: nem a existência da conta nem a latência do SMTP se distinguem pelo tempo de resposta. */
    private function igualarTempo(int|float $inicio): void
    {
        $minimoMs = (int) config('erp.sessao.recuperacao_tempo_minimo_ms');
        $decorridoMs = (hrtime(true) - $inicio) / 1_000_000;
        if ($minimoMs > 0 && $decorridoMs < $minimoMs) {
            usleep((int) (($minimoMs - $decorridoMs) * 1000));
        }
    }
}
