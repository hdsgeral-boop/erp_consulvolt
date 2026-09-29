<?php

namespace App\Jobs\Vendas;

use App\Services\Vendas\ServicoEnvioAgt;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Envia os documentos pendentes e consulta os estados na AGT para uma empresa (ciclo() do legado,
 * facturacao_agt_envio.js:280-295, que corria no browser de 2 em 2 minutos). Único por empresa na fila.
 */
final class CicloAgt implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $empresaId)
    {
        $this->onQueue('agt');
    }

    public function uniqueId(): string
    {
        return (string) $this->empresaId;
    }

    public function handle(ContextoEmpresa $contexto): void
    {
        $contexto->executarComo($this->empresaId, function () {
            try {
                $r = app(ServicoEnvioAgt::class)->ciclo();
                if (($r['consulta']['invalidos'] ?? 0) || ($r['envio']['rejeitados'] ?? 0)) {
                    Log::warning("AGT empresa {$this->empresaId}: documentos recusados", $r);
                }
            } catch (Throwable $e) {
                Log::error("AGT empresa {$this->empresaId}: ciclo falhou: {$e->getMessage()}");
            }
        });
    }
}
