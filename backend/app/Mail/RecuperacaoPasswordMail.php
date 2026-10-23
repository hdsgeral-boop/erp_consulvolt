<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Envelope;

/** Enviado quando é pedida a recuperação de password (AutenticacaoController::recuperarPassword). */
final class RecuperacaoPasswordMail extends EmailBase
{
    public function __construct(private readonly string $nomeUtilizador, private readonly string $nomeCompleto) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Pedido de recuperação de password — Consulvolt ERP');
    }

    protected function vista(): string
    {
        return 'emails.recuperacao_password';
    }

    protected function dados(): array
    {
        return ['nomeUtilizador' => $this->nomeUtilizador, 'nomeCompleto' => $this->nomeCompleto, 'url' => config('app.url')];
    }
}
