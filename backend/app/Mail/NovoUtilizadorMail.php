<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Envelope;

/**
 * Enviado a um utilizador novo com o nome de utilizador e o endereço de entrada (nunca a palavra-passe) (ServicoUtilizadores::criar) — só se o utilizador tiver
 * email (campo opcional). A password em texto simples só existe neste momento (antes do cast 'hashed' do
 * Utilizador); nunca é guardada nem fica acessível depois da criação.
 */
final class NovoUtilizadorMail extends EmailBase
{
    public function __construct(
        private readonly string $nomeCompleto,
        private readonly string $nomeUtilizador,
        private readonly string $nomeEmpresa,
        private readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "O seu acesso ao MEU ERP — {$this->nomeEmpresa}");
    }

    protected function vista(): string
    {
        return 'emails.novo_utilizador';
    }

    protected function dados(): array
    {
        return [
            'nomeCompleto' => $this->nomeCompleto, 'nomeUtilizador' => $this->nomeUtilizador, 'nomeEmpresa' => $this->nomeEmpresa, 'url' => $this->url,
        ];
    }
}
