<?php

namespace App\Console\Commands;

use App\Services\Sistema\ServicoOutbox;
use Illuminate\Console\Command;

/** Apaga do outbox os eventos PUBLICADOS antigos (ADR-080). Os MORTOS ficam sempre até alguém os tratar. Agendado de madrugada. */
final class OutboxLimparCommand extends Command
{
    protected $signature = 'erp:outbox:limpar {--dias=14 : idade mínima (dias) dos eventos publicados a apagar}';

    protected $description = 'Apaga os eventos publicados do outbox com mais de N dias (nunca os mortos)';

    public function handle(ServicoOutbox $outbox): int
    {
        $dias = max(1, (int) $this->option('dias'));
        $this->info("Outbox: {$outbox->limpar($dias)} evento(s) publicado(s) com mais de {$dias} dia(s) apagado(s).");

        return self::SUCCESS;
    }
}
