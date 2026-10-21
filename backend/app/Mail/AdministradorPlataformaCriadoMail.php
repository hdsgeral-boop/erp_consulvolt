<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Envelope;

/** Enviado a um novo super-administrador da plataforma com o nome de utilizador e o endereço de entrada (nunca a palavra-passe) (AdministradoresController::store). */
final class AdministradorPlataformaCriadoMail extends EmailBase
{
    public function __construct(private readonly string $nomeCompleto, private readonly string $nomeUtilizador, private readonly string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'O seu acesso à administração da plataforma MEU ERP');
    }

    protected function vista(): string
    {
        return 'emails.administrador_plataforma_criado';
    }

    protected function dados(): array
    {
        return [
            'nomeCompleto' => $this->nomeCompleto, 'nomeUtilizador' => $this->nomeUtilizador, 'url' => $this->url,
        ];
    }
}
