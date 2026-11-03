<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Envelope;

/** Ponte opcional (erp.notificacoes.email) do centro de notificações de RH/Método/Estrutura para o e-mail do destinatário. */
final class NotificacaoRHMail extends EmailBase
{
    public function __construct(private readonly string $titulo, private readonly string $mensagem, private readonly string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->titulo);
    }

    protected function vista(): string
    {
        return 'emails.notificacao_rh';
    }

    protected function dados(): array
    {
        return ['titulo' => $this->titulo, 'mensagem' => $this->mensagem, 'url' => $this->url];
    }
}
