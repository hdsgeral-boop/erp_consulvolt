<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Envelope;

/** Enviado ao administrador assim que uma empresa se regista (RegistoEmpresaController::store). */
final class BoasVindasEmpresaMail extends EmailBase
{
    public function __construct(
        private readonly string $nomeAdministrador,
        private readonly string $nomeEmpresa,
        private readonly int $diasTeste,
        private readonly ?string $nomePlano,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Bem-vindo(a) à Consulvolt — {$this->nomeEmpresa}");
    }

    protected function vista(): string
    {
        return 'emails.boas_vindas_empresa';
    }

    protected function dados(): array
    {
        return [
            'nomeAdministrador' => $this->nomeAdministrador,
            'nomeEmpresa' => $this->nomeEmpresa,
            'diasTeste' => $this->diasTeste,
            'nomePlano' => $this->nomePlano,
            'url' => config('app.url'),
        ];
    }
}
