<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Envelope;

/** Enviado a um novo operador do back-office da plataforma com as suas credenciais (OperadoresPlataformaController::store). */
final class OperadorPlataformaCriadoMail extends EmailBase
{
    public function __construct(private readonly string $nomeCompleto, private readonly string $nomeUtilizador, private readonly string $palavraPasse) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'O seu acesso ao back-office da plataforma Consulvolt');
    }

    protected function vista(): string
    {
        return 'emails.operador_plataforma_criado';
    }

    protected function dados(): array
    {
        return [
            'nomeCompleto' => $this->nomeCompleto, 'nomeUtilizador' => $this->nomeUtilizador,
            'palavraPasse' => $this->palavraPasse, 'url' => rtrim((string) config('app.url'), '/').'/admin',
        ];
    }
}
