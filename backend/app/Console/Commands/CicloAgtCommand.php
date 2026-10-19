<?php

namespace App\Console\Commands;

use App\Jobs\Vendas\CicloAgt;
use App\Models\ConfigFaturacaoEletronica;
use App\Models\LigacaoAgtEmpresa;
use App\Support\Tenancy\ContextoEmpresa;
use App\Support\Tenancy\ContextoTenant;
use Illuminate\Console\Command;

/**
 * Agenda o ciclo de envio/consulta à AGT (consulta periódica — «polling»: a AGT processa de forma assíncrona e o estado
 * obtém-se com obterEstado; doc-agt «modelo.html») das empresas com o regime activo e o envio automático ligado. Com a
 * ligação desligada no servidor (AGT_DRIVER), só entram as empresas que têm a sua própria ligação activa.
 */
final class CicloAgtCommand extends Command
{
    protected $signature = 'erp:agt:ciclo {--empresa= : só esta empresa}';

    protected $description = 'Envia à AGT os documentos pendentes e consulta os estados (envio automático)';

    public function handle(ContextoEmpresa $contexto, ContextoTenant $tenant): int
    {
        $desligado = config('erp.agt.driver') === 'desligado';
        $agendadas = 0;
        $comAlgumaLigacao = false;

        // SaaS (ADR-073): uma passagem por tenant (sem SaaS, uma só, como antes).
        $tenant->porTenant(function () use ($contexto, $desligado, &$agendadas, &$comAlgumaLigacao) {
            $comLigacao = $contexto->semIsolamento(fn () => LigacaoAgtEmpresa::query()->where('ativo', true)->pluck('empresa_id')->map(fn ($e) => (int) $e)->all());
            $comAlgumaLigacao = $comAlgumaLigacao || $comLigacao !== [];
            if ($desligado && ! $comLigacao) {
                return;
            }
            $empresas = $contexto->semIsolamento(fn () => ConfigFaturacaoEletronica::query()->where('ativo', true)
                ->when($this->option('empresa'), fn ($q, $e) => $q->where('empresa_id', $e))->get()
                ->filter(fn ($c) => ! empty($c->servico['auto']) || $this->option('empresa'))
                ->filter(fn ($c) => ! $desligado || in_array((int) $c->empresa_id, $comLigacao, true))
                ->pluck('empresa_id'));

            foreach ($empresas as $empresa) {
                CicloAgt::dispatch((int) $empresa);
            }
            $agendadas += $empresas->count();
        });

        if ($desligado && ! $comAlgumaLigacao) {
            $this->line('Ligação à AGT desligada (AGT_DRIVER) e nenhuma empresa com ligação própria: nada a fazer.');

            return self::SUCCESS;
        }
        $this->info("Ciclo AGT agendado para {$agendadas} empresa(s).");

        return self::SUCCESS;
    }
}