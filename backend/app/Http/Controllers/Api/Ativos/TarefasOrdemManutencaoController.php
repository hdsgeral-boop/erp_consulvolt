<?php

namespace App\Http\Controllers\Api\Ativos;

use App\Http\Controllers\Controller;
use App\Models\OrdemManutencaoAtivo;
use App\Services\Ativos\ServicoOrdensManutencao;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/ativos/manutencao/ordens/{ordem}/tarefas — gestão das tarefas da ordem de manutenção (r3_activos): concluir/reabrir
 * uma tarefa (reabrir numa ordem concluída reabre a ordem, com motivo), acrescentar e retirar tarefas. Permissão
 * activos_manut_gerir, verificada antes da validação (OWASP A01); ordens de outra empresa dão 404 (EscopoEmpresa).
 * Devolve sempre o detalhe da ordem (tarefas normalizadas, progresso e histórico).
 */
final class TarefasOrdemManutencaoController extends Controller
{
    private const GERIR = 'activos_manut_gerir';

    public function __construct(private readonly ServicoOrdensManutencao $ordens) {}

    public function estado(Request $r, int $ordem, string $tarefa): JsonResponse
    {
        $this->exigir(self::GERIR);
        $d = $r->validate(['feito' => ['required', 'boolean'], 'motivo' => ['nullable', 'string', 'max:1000']]);
        $o = $this->ordens->marcarTarefa(OrdemManutencaoAtivo::query()->findOrFail($ordem), $tarefa, (bool) $d['feito'], $d['motivo'] ?? null);

        return RespostaApi::sucesso($this->ordens->detalhe($o), $d['feito'] ? 'Tarefa concluída.' : 'Tarefa reaberta.');
    }

    public function acrescentar(Request $r, int $ordem): JsonResponse
    {
        $this->exigir(self::GERIR);
        $d = $r->validate(['texto' => ['required', 'string', 'max:500'], 'obrigatoria' => ['nullable', 'boolean']]);
        $o = $this->ordens->acrescentarTarefa(OrdemManutencaoAtivo::query()->findOrFail($ordem), $d['texto'], (bool) ($d['obrigatoria'] ?? true));

        return RespostaApi::criado($this->ordens->detalhe($o), 'Tarefa acrescentada.');
    }

    public function retirar(int $ordem, string $tarefa): JsonResponse
    {
        $this->exigir(self::GERIR);
        $o = $this->ordens->retirarTarefa(OrdemManutencaoAtivo::query()->findOrFail($ordem), $tarefa);

        return RespostaApi::sucesso($this->ordens->detalhe($o), 'Tarefa retirada.');
    }
}
