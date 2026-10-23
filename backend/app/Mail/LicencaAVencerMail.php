<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Envelope;

/** Aviso enviado dias antes da licença de uma empresa expirar (comando agendado erp:licencas:avisar). */
final class LicencaAVencerMail extends EmailBase
{
    public function __construct(private readonly string $nomeCompleto, private readonly string $nomeEmpresa, private readonly int $diasRestantes, private readonly string $dataFim) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "A licença de {$this->nomeEmpresa} expira em {$this->diasRestantes} dia(s)");
    }

    protected function vista(): string
    {
        return 'emails.licenca_a_vencer';
    }

    protected function dados(): array
    {
        return [
            'nomeCompleto' => $this->nomeCompleto, 'nomeEmpresa' => $this->nomeEmpresa,
            'diasRestantes' => $this->diasRestantes, 'dataFim' => $this->dataFim, 'url' => config('app.url'),
        ];
    }
}
