<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Envelope;

/**
 * Enviado com a nova password quando um administrador a repõe (ServicoUtilizadores::reporPalavraPasse) — a password
 * é sempre gerada pelo sistema, nunca escolhida pelo administrador; o utilizador é obrigado a trocá-la no próximo acesso.
 */
final class PalavraPasseRedefinidaMail extends EmailBase
{
    public function __construct(
        private readonly string $nomeCompleto,
        private readonly string $nomeUtilizador,
        private readonly string $palavraPasse,
        private readonly string $nomeEmpresa,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'A sua password foi redefinida — Consulvolt ERP');
    }

    protected function vista(): string
    {
        return 'emails.palavra_passe_redefinida';
    }

    protected function dados(): array
    {
        return ['nomeCompleto' => $this->nomeCompleto, 'nomeUtilizador' => $this->nomeUtilizador,
            'palavraPasse' => $this->palavraPasse, 'nomeEmpresa' => $this->nomeEmpresa, 'url' => config('app.url')];
    }
}
