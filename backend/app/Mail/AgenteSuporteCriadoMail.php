<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Envelope;

/** Enviado a um novo agente do backoffice de suporte com o nome de utilizador e o endereço de entrada (nunca a palavra-passe) (AgentesSuporteController::store). */
final class AgenteSuporteCriadoMail extends EmailBase
{
    public function __construct(private readonly string $nomeCompleto, private readonly string $nomeUtilizador, private readonly string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'O seu acesso ao backoffice de suporte MEU ERP');
    }

    protected function vista(): string
    {
        return 'emails.agente_suporte_criado';
    }

    protected function dados(): array
    {
        return [
            'nomeCompleto' => $this->nomeCompleto, 'nomeUtilizador' => $this->nomeUtilizador, 'url' => $this->url,
        ];
    }
}
