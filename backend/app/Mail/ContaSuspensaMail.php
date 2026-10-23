<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Envelope;

/** Enviado ao administrador quando a plataforma suspende o cliente (ServicoClientes::definirEstado). */
final class ContaSuspensaMail extends EmailBase
{
    public function __construct(private readonly string $nomeCompleto, private readonly string $nomeCliente) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'A sua conta Consulvolt foi suspensa');
    }

    protected function vista(): string
    {
        return 'emails.conta_suspensa';
    }

    protected function dados(): array
    {
        return ['nomeCompleto' => $this->nomeCompleto, 'nomeCliente' => $this->nomeCliente, 'emailSuporteDestaque' => true];
    }
}
