<?php

namespace App\Console\Commands;

use App\Services\Sistema\ServicoOutbox;
use Illuminate\Console\Command;

/** Resumo do outbox (ADR-080): pendentes, o mais antigo, mortos e o detalhe por tipo. Termina com erro se houver eventos mortos. */
final class OutboxEstadoCommand extends Command
{
    protected $signature = 'erp:outbox:estado';

    protected $description = 'Mostra o estado do outbox (pendentes, mais antigo, mortos)';

    public function handle(ServicoOutbox $outbox): int
    {
        $r = $outbox->resumo();
        $this->line("Pendentes: {$r['pendentes']}".($r['mais_antigo_segundos'] !== null ? " (o mais antigo há {$r['mais_antigo_segundos']} s)" : ''));
        $this->line("Mortos: {$r['mortos']}");
        $this->line("Publicados (ainda não limpos): {$r['publicados']}");
        foreach ($r['por_tipo'] as $rotulo => $n) {
            $this->line("  {$rotulo}: {$n}");
        }

        return $r['mortos'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
