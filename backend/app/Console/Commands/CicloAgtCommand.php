<?php

namespace App\Console\Commands;

use App\Jobs\Vendas\CicloAgt;
use App\Models\ConfigFaturacaoEletronica;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Console\Command;

/** Agenda o ciclo de envio/consulta à AGT das empresas com o regime activo e o envio automático ligado. */
final class CicloAgtCommand extends Command
{
    protected $signature = 'erp:agt:ciclo {--empresa= : só esta empresa}';

    protected $description = 'Envia à AGT os documentos pendentes e consulta os estados (envio automático)';

    public function handle(ContextoEmpresa $contexto): int
    {
        if (config('erp.agt.driver') === 'desligado') {
            $this->line('Ligação à AGT desligada (AGT_DRIVER): nada a fazer.');

            return self::SUCCESS;
        }
        $empresas = $contexto->semIsolamento(fn () => ConfigFaturacaoEletronica::query()->where('ativo', true)
            ->when($this->option('empresa'), fn ($q, $e) => $q->where('empresa_id', $e))->get()
            ->filter(fn ($c) => ! empty($c->servico['auto']) || $this->option('empresa'))->pluck('empresa_id'));

        foreach ($empresas as $empresa) {
            CicloAgt::dispatch((int) $empresa);
        }
        $this->info("Ciclo AGT agendado para {$empresas->count()} empresa(s).");

        return self::SUCCESS;
    }
}
