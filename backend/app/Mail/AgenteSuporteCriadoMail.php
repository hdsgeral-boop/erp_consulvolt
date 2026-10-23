<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Envelope;

/** Enviado a um novo agente do backoffice de suporte com as suas credenciais (AgentesSuporteController::store). */
final class AgenteSuporteCriadoMail extends EmailBase
{
    public function __construct(private readonly string $nomeCompleto, private readonly string $nomeUtilizador, private readonly string $palavraPasse) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'O seu acesso ao backoffice de suporte Consulvolt');
    }

    protected function vista(): string
    {
        return 'emails.agente_suporte_criado';
    }

    protected function dados(): array
    {
        return [
            'nomeCompleto' => $this->nomeCompleto, 'nomeUtilizador' => $this->nomeUtilizador,
            'palavraPasse' => $this->palavraPasse, 'url' => rtrim((string) config('app.url'), '/').'/suporte',
        ];
    }
}
