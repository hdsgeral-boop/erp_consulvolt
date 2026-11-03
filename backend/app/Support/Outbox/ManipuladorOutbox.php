<?php

namespace App\Support\Outbox;

use App\Models\OutboxEvento;

/**
 * Executa o efeito externo de um evento do outbox (ADR-080). A entrega é «pelo menos uma vez»: o MESMO evento pode chegar mais de
 * uma vez (o processo morreu depois do efeito e antes de o evento ficar PUBLICADO), por isso o manipulador TEM de ser idempotente —
 * repetir não pode duplicar o efeito (usar uma chave natural: um registo único, `INSERT … ON CONFLICT`, ou consultar antes).
 * Lança uma excepção para pedir nova tentativa (recuo exponencial); regista-se em `erp.outbox.manipuladores`.
 * Corre com a empresa do evento activa (`ContextoEmpresa`), ou sem empresa se o evento for global.
 */
interface ManipuladorOutbox
{
    /** Tipo de evento que trata (ex.: `notificacao.rh`). */
    public function tipo(): string;

    /** @param  array<string, mixed>  $carga */
    public function manipular(array $carga, OutboxEvento $evento): void;
}
