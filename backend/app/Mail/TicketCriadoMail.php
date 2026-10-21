<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Envelope;

/** Enviado à equipa de suporte da plataforma quando um cliente abre um ticket (MinhaContaController::criarTicket). */
final class TicketCriadoMail extends EmailBase
{
    public function __construct(
        private readonly int $ticketId,
        private readonly string $nomeCliente,
        private readonly string $assunto,
        private readonly string $prioridade,
        private readonly string $categoria,
        private readonly ?string $descricao,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "[Ticket #{$this->ticketId}] {$this->assunto} — {$this->nomeCliente}");
    }

    protected function vista(): string
    {
        return 'emails.ticket_criado';
    }

    protected function dados(): array
    {
        return [
            'ticketId' => $this->ticketId, 'nomeCliente' => $this->nomeCliente, 'assunto' => $this->assunto,
            'prioridade' => $this->prioridade, 'categoria' => $this->categoria, 'descricao' => $this->descricao,
            'url' => rtrim((string) config('app.url'), '/').'/admin/suporte',
        ];
    }
}
