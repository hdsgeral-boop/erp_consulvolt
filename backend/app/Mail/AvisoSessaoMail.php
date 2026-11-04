<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Envelope;

/**
 * Avisos de segurança sobre sessões (ADR-082): `novo_dispositivo` (primeiro acesso a partir de um dispositivo ainda não
 * visto) e `token_reutilizado` (um token já rodado foi apresentado fora da tolerância — a sessão desse dispositivo foi
 * terminada). Nunca leva segredos: só o rótulo do dispositivo, o IP aproximado e a hora.
 */
final class AvisoSessaoMail extends EmailBase
{
    public const NOVO_DISPOSITIVO = 'novo_dispositivo';

    public const TOKEN_REUTILIZADO = 'token_reutilizado';

    public function __construct(
        private readonly string $tipo,
        private readonly string $nomeCompleto,
        private readonly string $rotulo,
        private readonly ?string $ipAproximado,
        private readonly string $quando,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->tipo === self::TOKEN_REUTILIZADO
            ? 'Sessão terminada por segurança — Consulvolt ERP'
            : 'Novo dispositivo na sua conta — Consulvolt ERP');
    }

    protected function vista(): string
    {
        return 'emails.aviso_sessao';
    }

    protected function dados(): array
    {
        return ['tipo' => $this->tipo, 'nomeCompleto' => $this->nomeCompleto, 'rotulo' => $this->rotulo,
            'ip' => $this->ipAproximado, 'quando' => $this->quando, 'url' => config('app.url')];
    }
}
