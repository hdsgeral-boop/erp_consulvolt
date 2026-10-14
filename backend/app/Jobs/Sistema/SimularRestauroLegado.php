<?php

namespace App\Jobs\Sistema;

use App\Models\RestauroLegado;
use App\Services\Migracao\ServicoMigracaoLegado;
use App\Services\Sistema\ServicoRestauroLegado;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Simulação do restauro: corre a migração completa do backup sobre a base (com --substituir) dentro de uma transacção e
 * desfaz tudo no fim (ROLLBACK). Enquanto corre (~1-2 min) as tabelas ficam bloqueadas: os pedidos dos outros
 * utilizadores esperam. Nunca se repete sozinho.
 */
final class SimularRestauroLegado implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Abaixo do retry_after da fila (REDIS_QUEUE_RETRY_AFTER=660): nunca é entregue duas vezes. */
    public int $timeout = 600;

    public function __construct(public readonly int $restauroId) {}

    public function handle(ServicoRestauroLegado $servico): void
    {
        $r = RestauroLegado::query()->find($this->restauroId);
        if (! $r || $r->estado !== 'SIMULANDO' || ! $r->caminho) {
            return;
        }
        try {
            $caminho = Storage::disk('local')->path($r->caminho);
            if (! is_file($caminho) || hash_file('sha256', $caminho) !== $r->sha256) {
                throw new \RuntimeException('O ficheiro carregado já não existe ou foi alterado.');
            }
            $relatorio = (new ServicoMigracaoLegado($caminho))->executar(true, true, "restauro #{$r->id} (simulação)");
            $resumo = ServicoRestauroLegado::resumoRelatorio($relatorio);
            $r->refresh()->update(['estado' => 'SIMULADO', 'simulacao' => $resumo, 'simulado_em' => now(),
                'historico' => [...($r->historico ?? []), $servico->entrada(null, $resumo['sem_problemas']
                    ? 'Simulação concluída sem divergências: pode ser aprovada por outro super-administrador.'
                    : 'Simulação concluída COM divergências de contagem ou órfãos: não pode ser aprovada.')]]);
        } catch (Throwable $e) {
            $this->falhou($servico, $e->getMessage());
        }
    }

    public function failed(?Throwable $e): void
    {
        $this->falhou(app(ServicoRestauroLegado::class), $e?->getMessage() ?? 'A simulação foi interrompida.');
    }

    private function falhou(ServicoRestauroLegado $servico, string $mensagem): void
    {
        $r = RestauroLegado::query()->find($this->restauroId);
        if (! $r || $r->estado !== 'SIMULANDO') {
            return;
        }
        $r->update(['estado' => 'SIMULACAO_FALHADA', 'simulado_em' => now(), 'erro' => mb_substr($mensagem, 0, 10000),
            'historico' => [...($r->historico ?? []), $servico->entrada(null, 'Simulação falhada (nada foi gravado).')]]);
    }
}
