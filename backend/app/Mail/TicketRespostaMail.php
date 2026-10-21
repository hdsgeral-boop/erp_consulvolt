<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Envelope;

/**
 * Enviado quando há uma resposta nova numa conversa de suporte — para o cliente, quando responde a plataforma
 * (ConversaSuportePlataformaController::responder); para a plataforma, quando responde o cliente
 * (MinhaContaController::responderTicket).
 */
final class TicketRespostaMail extends EmailBase
{
    public function __construct(
        private readonly int $ticketId,
        private readonly string $assunto,
        private readonly string $nomeDestinatario,
        private readonly string $nomeAutor,
        private readonly string $mensagem,
        private readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Nova resposta no ticket #{$this->ticketId} — {$this->assunto}");
    }

    protected function vista(): string
    {
        return 'emails.ticket_resposta';
    }

    protected function dados(): array
    {
        return [
            'ticketId' => $this->ticketId, 'assunto' => $this->assunto, 'nomeDestinatario' => $this->nomeDestinatario,
            'nomeAutor' => $this->nomeAutor, 'mensagem' => $this->mensagem, 'url' => $this->url,
        ];
    }
}
