<?php

namespace App\Http\Controllers\Api\Estrategia;

use App\Http\Controllers\Controller;
use App\Http\Requests\Estrategia\GravarObjectivoRequest;
use App\Http\Requests\Estrategia\GravarPerspectivaRequest;
use App\Http\Resources\Estrategia\ObjectivoEstrategicoResource;
use App\Http\Resources\Estrategia\PerspectivaEstrategicaResource;
use App\Services\Estrategia\ServicoObjectivosEstrategicos;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Planeamento Estratégico — perspectivas e objectivos do plano (ecrã est_plano). Escrever: est_plano_editar. Num plano
 * aprovado a alteração exige est_plano_aprovar e `motivo_excepcao` (registado na auditoria).
 *  POST|PUT|DELETE estrategia/planos/{plano}/perspectivas[/{id}]
 *  POST|PUT|DELETE estrategia/planos/{plano}/objectivos[/{id}]
 */
final class EstruturaEstrategicaController extends Controller
{
    public function __construct(private readonly ServicoObjectivosEstrategicos $servico) {}

    public function criarPerspectiva(GravarPerspectivaRequest $r, int $plano): JsonResponse
    {
        $d = $r->validated();
        $motivo = $d['motivo_excepcao'] ?? null;
        unset($d['motivo_excepcao']);

        return RespostaApi::criado(new PerspectivaEstrategicaResource($this->servico->criarPerspectiva($plano, $d, $motivo)), 'Perspectiva criada.');
    }

    public function actualizarPerspectiva(GravarPerspectivaRequest $r, int $plano, int $id): JsonResponse
    {
        $d = $r->validated();
        $motivo = $d['motivo_excepcao'] ?? null;
        unset($d['motivo_excepcao']);

        return RespostaApi::sucesso(new PerspectivaEstrategicaResource($this->servico->actualizarPerspectiva($plano, $id, $d, $motivo)), 'Perspectiva gravada.');
    }

    public function apagarPerspectiva(Request $r, int $plano, int $id): JsonResponse
    {
        $this->exigir('est_plano_editar');
        $this->servico->apagarPerspectiva($plano, $id, $r->input('motivo_excepcao'));

        return RespostaApi::sucesso(null, 'Perspectiva apagada.');
    }

    public function criarObjectivo(GravarObjectivoRequest $r, int $plano): JsonResponse
    {
        $d = $r->validated();
        $motivo = $d['motivo_excepcao'] ?? null;
        unset($d['motivo_excepcao']);

        return RespostaApi::criado(new ObjectivoEstrategicoResource($this->servico->criarObjectivo($plano, $d, $motivo)), 'Objectivo criado.');
    }

    public function actualizarObjectivo(GravarObjectivoRequest $r, int $plano, int $id): JsonResponse
    {
        $d = $r->validated();
        $motivo = $d['motivo_excepcao'] ?? null;
        unset($d['motivo_excepcao']);

        return RespostaApi::sucesso(new ObjectivoEstrategicoResource($this->servico->actualizarObjectivo($plano, $id, $d, $motivo)), 'Objectivo gravado.');
    }

    public function apagarObjectivo(Request $r, int $plano, int $id): JsonResponse
    {
        $this->exigir('est_plano_editar');
        $this->servico->apagarObjectivo($plano, $id, $r->input('motivo_excepcao'));

        return RespostaApi::sucesso(null, 'Objectivo apagado.');
    }
}
