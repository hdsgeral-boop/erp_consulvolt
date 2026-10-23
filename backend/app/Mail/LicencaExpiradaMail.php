<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Envelope;

/** Enviado no dia em que a licença de uma empresa expira e ela passa a modo só-leitura (comando agendado erp:licencas:avisar). */
final class LicencaExpiradaMail extends EmailBase
{
    public function __construct(private readonly string $nomeCompleto, private readonly string $nomeEmpresa) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "A licença de {$this->nomeEmpresa} expirou");
    }

    protected function vista(): string
    {
        return 'emails.licenca_expirada';
    }

    protected function dados(): array
    {
        return ['nomeCompleto' => $this->nomeCompleto, 'nomeEmpresa' => $this->nomeEmpresa, 'url' => config('app.url')];
    }
}
