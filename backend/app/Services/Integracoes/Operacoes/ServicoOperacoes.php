<?php

namespace App\Services\Integracoes\Operacoes;

use App\Exceptions\ErroNegocio;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

/**
 * Operações em segundo plano (M-05; legado js/tarefas.js + shared/janela_processo.js): um lote de trabalhos na fila
 * (Bus::batch, tabela lotes_trabalhos) acompanhado pelo frontend no gestor de operações (src/componentes/operacoes).
 *
 * Uso nos módulos:
 *   $op = app(ServicoOperacoes::class)->despachar('Recibos de Janeiro (ZIP)', [new GerarRecibo(...), ...], 'pdfs');
 *   return RespostaApi::sucesso($op);      // o frontend chama operacoes.acompanharLote($op['id'], $op['titulo'])
 *
 * O dono (utilizador e empresa activa) fica em cache 7 dias: GET /api/sistema/operacoes/{id} só responde ao próprio.
 */
final class ServicoOperacoes
{
    private const PREFIXO = 'operacao:';

    /** @param  list<object>  $trabalhos */
    public function despachar(string $titulo, array $trabalhos, ?string $fila = null, ?int $empresa = null): array
    {
        $lote = Bus::batch($trabalhos)->name(mb_substr($titulo, 0, 200))->allowFailures();
        if ($fila) {
            $lote->onQueue($fila);
        }
        $this->registarDono($id = $lote->dispatch()->id, $titulo, $empresa);

        return $this->estado($id);
    }

    public function registarDono(string $id, string $titulo, ?int $empresa = null): void
    {
        Cache::put(self::PREFIXO.$id, ['utilizador_id' => Auth::id(), 'empresa_id' => $empresa ?? app(ContextoEmpresa::class)->id(), 'titulo' => $titulo], now()->addDays(7));
    }

    /** @return array<string, mixed> */
    public function estado(string $id, ?int $utilizador = null): array
    {
        $dono = Cache::get(self::PREFIXO.$id);
        if (! $dono || ($utilizador !== null && (int) $dono['utilizador_id'] !== $utilizador)) {
            throw new ErroNegocio('Operação não encontrada.', 'NAO_ENCONTRADO', 404);
        }
        $b = Bus::findBatch($id);
        if (! $b instanceof Batch) {
            throw new ErroNegocio('Operação não encontrada.', 'NAO_ENCONTRADO', 404);
        }

        return ['id' => $b->id, 'titulo' => $dono['titulo'], 'total' => $b->totalJobs, 'pendentes' => $b->pendingJobs, 'falhados' => $b->failedJobs,
            'progresso' => $b->progress(), 'concluida' => $b->finished(), 'cancelada' => $b->cancelled(),
            'estado' => $b->cancelled() ? 'CANCELADA' : ($b->finished() ? ($b->failedJobs ? 'CONCLUIDA_COM_FALHAS' : 'CONCLUIDA') : 'EM_CURSO'),
            'criada_em' => $b->createdAt?->toIso8601String(), 'terminada_em' => $b->finishedAt?->toIso8601String()];
    }

    public function cancelar(string $id, int $utilizador): array
    {
        $this->estado($id, $utilizador);
        Bus::findBatch($id)?->cancel();

        return $this->estado($id, $utilizador);
    }
}
