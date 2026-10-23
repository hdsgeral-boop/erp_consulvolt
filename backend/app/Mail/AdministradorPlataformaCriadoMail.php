<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Envelope;

/** Enviado a um novo super-administrador da plataforma com as suas credenciais (AdministradoresController::store). */
final class AdministradorPlataformaCriadoMail extends EmailBase
{
    public function __construct(private readonly string $nomeCompleto, private readonly string $nomeUtilizador, private readonly string $palavraPasse) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'O seu acesso à administração da plataforma Consulvolt');
    }

    protected function vista(): string
    {
        return 'emails.administrador_plataforma_criado';
    }

    protected function dados(): array
    {
        return [
            'nomeCompleto' => $this->nomeCompleto, 'nomeUtilizador' => $this->nomeUtilizador,
            'palavraPasse' => $this->palavraPasse, 'url' => rtrim((string) config('app.url'), '/').'/admin',
        ];
    }
}
