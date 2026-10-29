<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Envelope;

/** Enviado à equipa de suporte da plataforma quando um ticket excede o prazo de SLA (SlaSuporteAvisarCommand). */
final class TicketSlaVioladoMail extends EmailBase
{
    public function __construct(
        private readonly int $ticketId,
        private readonly string $nomeCliente,
        private readonly string $assunto,
        private readonly string $prioridade,
        private readonly string $prazoSla,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "[SLA violado] Ticket #{$this->ticketId} — {$this->nomeCliente}");
    }

    protected function vista(): string
    {
        return 'emails.ticket_sla_violado';
    }

    protected function dados(): array
    {
        return [
            'ticketId' => $this->ticketId, 'nomeCliente' => $this->nomeCliente, 'assunto' => $this->assunto,
            'prioridade' => $this->prioridade, 'prazoSla' => $this->prazoSla,
            'url' => rtrim((string) config('app.url'), '/').'/admin/suporte',
        ];
    }
}
