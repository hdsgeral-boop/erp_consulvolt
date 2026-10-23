<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Envelope;

/** Aviso de segurança enviado quando um administrador repõe a password de um utilizador (ServicoUtilizadores::reporPalavraPasse). */
final class PalavraPasseRedefinidaMail extends EmailBase
{
    public function __construct(private readonly string $nomeCompleto, private readonly string $nomeUtilizador, private readonly string $nomeEmpresa) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'A sua password foi alterada — Consulvolt ERP');
    }

    protected function vista(): string
    {
        return 'emails.palavra_passe_redefinida';
    }

    protected function dados(): array
    {
        return ['nomeCompleto' => $this->nomeCompleto, 'nomeUtilizador' => $this->nomeUtilizador, 'nomeEmpresa' => $this->nomeEmpresa, 'url' => config('app.url')];
    }
}
