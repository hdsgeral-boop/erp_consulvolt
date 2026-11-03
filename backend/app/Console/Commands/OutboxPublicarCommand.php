<?php

namespace App\Console\Commands;

use App\Services\Sistema\PublicadorOutbox;
use App\Support\Tenancy\ContextoTenant;
use Illuminate\Console\Command;

/**
 * Publica os eventos pendentes do Transactional Outbox (ADR-080). Agendado de minuto a minuto: apanha o que a publicação imediata
 * (depois do COMMIT) não conseguiu entregar, as retentativas devidas e os eventos de processos que morreram a meio (lease expirado).
 * Uma passagem por tenant (ContextoTenant::porTenant): a RLS mostra os eventos do tenant e os globais.
 */
final class OutboxPublicarCommand extends Command
{
    protected $signature = 'erp:outbox:publicar {--max=100 : máximo de eventos por passagem}';

    protected $description = 'Publica os eventos pendentes do outbox (entrega «pelo menos uma vez»)';

    public function handle(PublicadorOutbox $publicador, ContextoTenant $tenant): int
    {
        $total = ['publicados' => 0, 'falhados' => 0, 'mortos' => 0];
        $max = max(1, (int) $this->option('max'));
        $tenant->porTenant(function () use ($publicador, $max, &$total) {
            foreach ($publicador->publicarLote($max) as $chave => $n) {
                $total[$chave] += $n;
            }
        });
        $this->info("Outbox: {$total['publicados']} publicado(s), {$total['falhados']} a repetir, {$total['mortos']} morto(s).");

        return self::SUCCESS;
    }
}
