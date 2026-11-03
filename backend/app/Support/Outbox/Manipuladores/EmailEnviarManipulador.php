<?php

namespace App\Support\Outbox\Manipuladores;

use App\Models\OutboxEvento;
use App\Support\Outbox\ManipuladorOutbox;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

/**
 * Evento `email.enviar` (ADR-080): instancia um Mailable da lista branca `erp.outbox.emails_permitidos` com os argumentos NOMEADOS
 * da carga (escalares — nunca objectos Eloquent) e entrega-o à fila de e-mail (os Mailables são `ShouldQueue`, EmailBase).
 * Carga: {mailable: FQCN, para: e-mail, argumentos: {nome: valor}}.
 *
 * Entrega «pelo menos uma vez»: o evento só chega a ficar PUBLICADO depois de o e-mail estar na fila; se o processo morrer entre as duas
 * coisas, a reentrega põe na fila um e-mail repetido — nunca um e-mail perdido. É o compromisso do outbox e aceita-se: não há marcador em
 * cache (as escritas em cache têm inventário próprio, RegrasCacheTest) e um e-mail duplicado é inofensivo.
 */
final class EmailEnviarManipulador implements ManipuladorOutbox
{
    public function tipo(): string
    {
        return 'email.enviar';
    }

    public function manipular(array $carga, OutboxEvento $evento): void
    {
        $classe = (string) ($carga['mailable'] ?? '');
        if (! in_array($classe, (array) config('erp.outbox.emails_permitidos', []), true)) {
            throw new InvalidArgumentException("Mailable «{$classe}» fora da lista branca erp.outbox.emails_permitidos.");
        }
        $para = (string) ($carga['para'] ?? '');
        if (filter_var($para, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Destinatário de e-mail inválido.');
        }
        Mail::to($para)->send(new $classe(...(array) ($carga['argumentos'] ?? [])));
    }
}
