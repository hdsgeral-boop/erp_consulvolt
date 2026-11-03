<?php

namespace App\Console\Commands;

use App\Support\Tenancy\ContextoTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Apaga as execuções idempotentes CONCLUÍDAS antigas (ADR-080). As EM_CURSO ficam: ainda podem ser retomadas. Agendado de madrugada. */
final class IdempotenciaLimparCommand extends Command
{
    protected $signature = 'erp:idempotencia:limpar {--dias=30 : idade mínima (dias) das execuções concluídas a apagar}';

    protected $description = 'Apaga as execuções idempotentes concluídas com mais de N dias';

    public function handle(ContextoTenant $tenant): int
    {
        $dias = max(1, (int) $this->option('dias'));
        $limite = now()->subDays($dias);
        $total = 0;
        $tenant->porTenant(function () use ($limite, &$total) {
            $total += DB::table('execucoes_idempotentes')->where('estado', 'CONCLUIDO')->where('concluido_em', '<', $limite)->delete();
        });
        $this->info("Idempotência: {$total} execução(ões) concluída(s) com mais de {$dias} dia(s) apagada(s).");

        return self::SUCCESS;
    }
}
