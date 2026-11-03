<?php

namespace App\Support\Outbox\Manipuladores;

use App\Mail\NotificacaoRHMail;
use App\Models\NotificacaoSistema;
use App\Models\OutboxEvento;
use App\Models\Utilizador;
use App\Services\Sistema\ServicoNotificacoes;
use App\Support\Outbox\ManipuladorOutbox;
use App\Support\Outbox\Outbox;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Evento `notificacao.rh` (ADR-080, NotificadorRH): cria a notificação persistente de cada destinatário e, com
 * `erp.notificacoes.email` ligado, regista o evento `email.enviar` NA MESMA transacção (a notificação e o pedido de e-mail são
 * atómicos). Idempotente: cada notificação leva a chave `rh:ev{id}:u{utilizador}` em `notificacoes_sistema.chave_origem` (índice
 * único) — uma reentrega do mesmo evento salta os destinatários já tratados.
 * Carga: {utilizadores: [id], titulo, mensagem, link, categoria, tipo, autor, unica: bool|int}.
 */
final class NotificacaoRHManipulador implements ManipuladorOutbox
{
    public function tipo(): string
    {
        return 'notificacao.rh';
    }

    public function manipular(array $carga, OutboxEvento $evento): void
    {
        $empresa = (int) $evento->empresa_id;
        $servico = app(ServicoNotificacoes::class);
        $unica = $carga['unica'] ?? false;
        foreach ((array) ($carga['utilizadores'] ?? []) as $utilizador) {
            $utilizador = (int) $utilizador;
            $chave = "rh:ev{$evento->id}:u{$utilizador}";
            try {
                DB::transaction(function () use ($carga, $empresa, $servico, $unica, $utilizador, $chave) {
                    if (NotificacaoSistema::query()->where('chave_origem', $chave)->exists()) {
                        return;   // reentrega: já tratado
                    }
                    $categoria = strtolower((string) $carga['categoria']);
                    if ($unica !== false && NotificacaoSistema::query()->where('utilizador_id', $utilizador)->where('categoria', $categoria)->where('titulo', $carga['titulo'])
                        ->where('mensagem', $carga['mensagem'])->when(is_int($unica), fn ($q) => $q->where('criado_em', '>=', now()->subDays($unica)))->exists()) {
                        return;   // já avisado (para sempre, ou dentro dos últimos N dias)
                    }
                    $servico->criar($empresa, (string) $carga['tipo'], $categoria, mb_substr((string) $carga['titulo'], 0, 200), (string) $carga['mensagem'],
                        mb_substr((string) $carga['link'], 0, 255), $utilizador, (string) $carga['autor'], $chave);
                    $this->email($utilizador, $carga, $chave);
                });
            } catch (UniqueConstraintViolationException) {
                // outro publicador criou a mesma notificação entretanto (chave única): nada a fazer
            }
        }
    }

    /** @param  array<string, mixed>  $carga */
    private function email(int $utilizador, array $carga, string $chave): void
    {
        if (! config('erp.notificacoes.email')) {
            return;
        }
        $endereco = Utilizador::query()->whereKey($utilizador)->value('email');
        if ($endereco) {
            Outbox::email(NotificacaoRHMail::class, $endereco,
                ['titulo' => (string) $carga['titulo'], 'mensagem' => (string) $carga['mensagem'], 'url' => rtrim((string) config('app.url'), '/').$carga['link']], "email:{$chave}");
        }
    }
}
