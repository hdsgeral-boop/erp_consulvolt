<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** E-mail com a ligação de redefinição da palavra-passe (válida por tempo limitado e uma só vez). */
class RecuperarSenhaMail extends Mailable
{
    public function __construct(
        public readonly string $nome,
        public readonly string $ligacao,
        public readonly int $validadeMinutos,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Recuperação da palavra-passe - MEU ERP');
    }

    public function content(): Content
    {
        $nome = e($this->nome);
        $ligacao = e($this->ligacao);
        $html = "<p>Olá, {$nome}.</p>"
            .'<p>Recebemos um pedido para redefinir a palavra-passe da sua conta. Clique na ligação abaixo para escolher uma nova:</p>'
            ."<p><a href=\"{$ligacao}\">{$ligacao}</a></p>"
            ."<p>A ligação é válida durante {$this->validadeMinutos} minutos e só pode ser usada uma vez. "
            .'Se não fez este pedido, ignore esta mensagem: a sua palavra-passe não será alterada.</p>'
            .'<p>MEU ERP</p>';

        return new Content(htmlString: $html);
    }
}
