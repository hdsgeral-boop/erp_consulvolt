<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Envelope;

/**
 * Enviado a um utilizador novo (UtilizadorController::store), só se tiver e-mail: o sistema do cliente («MEU ERP
 * Grupo GLOMEU»), as empresas atribuídas, o utilizador e — a pedido de 2026-10-22 — a palavra-passe provisória, que
 * o sistema pede para alterar no primeiro acesso (utilizadores.alterar_palavra_passe). A palavra-passe em texto só
 * existe neste momento; nunca é guardada.
 */
final class NovoUtilizadorMail extends EmailBase
{
    /** @param  list<string>  $empresas */
    public function __construct(
        private readonly string $nomeCompleto,
        private readonly string $nomeUtilizador,
        private readonly string $nomeSistema,
        private readonly array $empresas,
        private readonly string $url,
        private readonly ?string $palavraPasse = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "O seu acesso ao MEU ERP {$this->nomeSistema}");
    }

    protected function vista(): string
    {
        return 'emails.novo_utilizador';
    }

    protected function dados(): array
    {
        return [
            'nomeCompleto' => $this->nomeCompleto, 'nomeUtilizador' => $this->nomeUtilizador, 'nomeSistema' => $this->nomeSistema,
            'empresas' => $this->empresas, 'url' => $this->url, 'palavraPasse' => $this->palavraPasse,
            // rodapé do layout: «plataforma MEU ERP — <sistema do cliente>»
            'nomeEmpresa' => $this->nomeSistema,
        ];
    }
}
