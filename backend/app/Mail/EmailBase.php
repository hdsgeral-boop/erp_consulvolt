<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Base dos emails do sistema: fila (nunca bloqueia o pedido que o despoleta — um SMTP lento não pode atrasar um
 * registo, um login ou uma resposta de ticket), e injecta sempre `emailSuporte` na vista (rodapé do layout,
 * resources/views/emails/layout.blade.php). Cada email concreto só define assunto e vista/dados próprios.
 */
abstract class EmailBase extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** Vista Blade (ex.: 'emails.boas_vindas_empresa') e os dados que ela precisa, para além de emailSuporte. */
    abstract protected function vista(): string;

    /** @return array<string, mixed> */
    protected function dados(): array
    {
        return [];
    }

    public function content(): Content
    {
        return new Content(
            view: $this->vista(),
            with: $this->dados() + ['emailSuporte' => self::emailSuporte()],
        );
    }

    /**
     * E-mail do suporte (Plataforma › Definições, definicoes_plataforma.email_suporte). Sem ele definido o rodapé não mostra
     * contacto de suporte (antes caía no remetente noreply@, que não é atendido). Nunca bloqueia o envio.
     */
    public static function emailSuporte(): ?string
    {
        return rescue(fn () => DB::table('definicoes_plataforma')->where('chave', 'email_suporte')->value('valor'), null, report: false) ?: null;
    }
}
