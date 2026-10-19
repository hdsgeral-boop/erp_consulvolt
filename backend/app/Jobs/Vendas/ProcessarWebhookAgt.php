<?php

namespace App\Jobs\Vendas;

use App\Models\EventoWebhookAgt;
use App\Services\Vendas\Agt\ServicoWebhookAgt;
use App\Services\Vendas\ServicoEnvioAgt;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Processa uma notificação da AGT recebida no webhook da empresa: confirma por consulta (obterEstado/consultarFactura) o
 * estado dos documentos referidos — o corpo da notificação nunca é tomado como estado oficial.
 */
final class ProcessarWebhookAgt implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public function __construct(public readonly int $empresaId, public readonly int $eventoId)
    {
        $this->onQueue('agt');
    }

    public function handle(ContextoEmpresa $contexto): void
    {
        $contexto->executarComo($this->empresaId, function () {
            $evento = EventoWebhookAgt::query()->find($this->eventoId);
            if (! $evento || $evento->estado === 'PROCESSADO') {
                return;
            }
            ServicoWebhookAgt::exigirEmpresa($evento, $this->empresaId);
            try {
                $ids = ServicoWebhookAgt::documentosReferidos((array) $evento->corpo);
                if (! $ids) {
                    $evento->update(['estado' => 'IGNORADO', 'processado_em' => now(), 'resultado' => 'Sem documentos desta empresa a aguardar validação.']);

                    return;
                }
                $r = app(ServicoEnvioAgt::class)->consultarEstados($ids, true);
                $evento->update(['estado' => 'PROCESSADO', 'processado_em' => now(),
                    'resultado' => mb_substr(json_encode(array_intersect_key($r, array_flip(['validos', 'invalidos', 'aguardar', 'ocupado'])), JSON_UNESCAPED_UNICODE), 0, 1000)]);
            } catch (Throwable $e) {
                $evento->update(['estado' => 'ERRO', 'resultado' => mb_substr($e->getMessage(), 0, 1000)]);
                throw $e;
            }
        });
    }
}
