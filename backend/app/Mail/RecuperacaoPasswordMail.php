<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Envelope;

/**
 * Enviado com uma nova password quando é pedida a recuperação (AutenticacaoController::recuperarPassword) — a
 * password é sempre gerada pelo sistema; o utilizador é obrigado a trocá-la no próximo acesso.
 */
final class RecuperacaoPasswordMail extends EmailBase
{
    public function __construct(
        private readonly string $nomeUtilizador,
        private readonly string $nomeCompleto,
        private readonly string $palavraPasse,
        private readonly ?string $ipAproximado = null,
        private readonly ?string $quando = null,
        private readonly ?string $dispositivo = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'A sua nova password — Consulvolt ERP');
    }

    protected function vista(): string
    {
        return 'emails.recuperacao_password';
    }

    protected function dados(): array
    {
        return ['nomeUtilizador' => $this->nomeUtilizador, 'nomeCompleto' => $this->nomeCompleto,
            'palavraPasse' => $this->palavraPasse, 'url' => config('app.url'),
            'ip' => $this->ipAproximado, 'quando' => $this->quando, 'dispositivo' => $this->dispositivo];
    }
}
