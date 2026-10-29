<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Envelope;

/** Enviado ao administrador de um cliente SaaS novo, criado pela plataforma (ClientesController::store). */
final class ClienteAdministradorCriadoMail extends EmailBase
{
    public function __construct(
        private readonly string $nomeCompleto,
        private readonly string $nomeUtilizador,
        private readonly string $palavraPasse,
        private readonly string $nomeCliente,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'O seu acesso Consulvolt ERP');
    }

    protected function vista(): string
    {
        return 'emails.cliente_administrador_criado';
    }

    protected function dados(): array
    {
        return [
            'nomeCompleto' => $this->nomeCompleto, 'nomeUtilizador' => $this->nomeUtilizador,
            'palavraPasse' => $this->palavraPasse, 'nomeCliente' => $this->nomeCliente, 'url' => config('app.url'),
        ];
    }
}
