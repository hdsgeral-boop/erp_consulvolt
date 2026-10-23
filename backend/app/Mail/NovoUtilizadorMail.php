<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Envelope;

/**
 * Enviado a um utilizador novo com as suas credenciais (ServicoUtilizadores::criar) — só se o utilizador tiver
 * email (campo opcional). A password em texto simples só existe neste momento (antes do cast 'hashed' do
 * Utilizador); nunca é guardada nem fica acessível depois da criação.
 */
final class NovoUtilizadorMail extends EmailBase
{
    public function __construct(
        private readonly string $nomeCompleto,
        private readonly string $nomeUtilizador,
        private readonly string $palavraPasse,
        private readonly string $nomeEmpresa,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "O seu acesso ao ERP Consulvolt — {$this->nomeEmpresa}");
    }

    protected function vista(): string
    {
        return 'emails.novo_utilizador';
    }

    protected function dados(): array
    {
        return [
            'nomeCompleto' => $this->nomeCompleto, 'nomeUtilizador' => $this->nomeUtilizador,
            'palavraPasse' => $this->palavraPasse, 'nomeEmpresa' => $this->nomeEmpresa, 'url' => config('app.url'),
        ];
    }
}
