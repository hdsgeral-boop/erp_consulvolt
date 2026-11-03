<?php

namespace App\Console\Commands;

use App\Exceptions\ErroNegocio;
use App\Services\Sistema\ServicoOutbox;
use Illuminate\Console\Command;

/** Volta eventos MORTOS do outbox a PENDENTE, com as tentativas a zero (ADR-080). Depois de corrigida a causa do erro. */
final class OutboxReprocessarCommand extends Command
{
    protected $signature = 'erp:outbox:reprocessar {id? : id do evento} {--mortos : todos os eventos mortos}';

    protected $description = 'Volta um evento morto (ou todos, com --mortos) do outbox a pendente';

    public function handle(ServicoOutbox $outbox): int
    {
        $id = $this->argument('id');
        if ($id === null && ! $this->option('mortos')) {
            $this->error('Indique o id do evento ou --mortos.');

            return self::INVALID;
        }
        try {
            $n = $outbox->reprocessar($id === null ? null : (int) $id);
        } catch (ErroNegocio $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("Outbox: {$n} evento(s) voltaram a pendente.");

        return self::SUCCESS;
    }
}
