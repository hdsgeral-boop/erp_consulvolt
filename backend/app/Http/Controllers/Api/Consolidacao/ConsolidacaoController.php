<?php

namespace App\Http\Controllers\Api\Consolidacao;

use App\Http\Controllers\Controller;
use App\Services\Consolidacao\ServicoConsolidacao;
use App\Services\Consolidacao\ServicoConsolidacaoGrupos;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /api/consolidacao — grupos (holdings), execução da consolidação, eliminações/divergências e Mapa de Consolidação (ADR-057). */
final class ConsolidacaoController extends Controller
{
    public function __construct(
        private readonly ServicoConsolidacaoGrupos $grupos,
        private readonly ServicoConsolidacao $consolidacao,
    ) {}

    public function index(): JsonResponse
    {
        $this->exigir('consolidacao_view', 'consol_gerir', 'consol_executar');

        return RespostaApi::sucesso($this->grupos->listar(), 'Grupos de consolidação.');
    }

    public function show(int $grupo): JsonResponse
    {
        $this->exigir('consolidacao_view', 'consol_gerir', 'consol_executar');

        return RespostaApi::sucesso($this->grupos->obter($grupo), 'Grupo de consolidação.');
    }

    public function store(Request $r): JsonResponse
    {
        $this->exigir('consol_gerir');

        return RespostaApi::criado($this->grupos->criar($this->validar($r)), 'Holding e grupo de consolidação criados.');
    }

    public function update(Request $r, int $grupo): JsonResponse
    {
        $this->exigir('consol_gerir');

        return RespostaApi::sucesso($this->grupos->atualizar($grupo, $this->validar($r)), 'Grupo de consolidação actualizado.');
    }

    public function destroy(int $grupo): JsonResponse
    {
        $this->exigir('consol_eliminar');
        $this->grupos->eliminar($grupo);

        return RespostaApi::sucesso(null, 'Holding eliminada: as empresas do grupo não foram afectadas.');
    }

    public function executar(Request $r, int $grupo): JsonResponse
    {
        $this->exigir('consol_executar');
        $d = $r->validate(['data_fim' => ['required', 'date_format:Y-m-d'], 'moeda' => ['nullable', 'string', 'size:3']]);
        $res = $this->consolidacao->executar($grupo, $d['data_fim'], $d['moeda'] ?? null);

        return RespostaApi::sucesso($res, "Consolidação concluída: {$res['totais']['linhas']} linhas até {$res['data_fim']} em {$res['moeda']}.");
    }

    public function execucao(int $execucao): JsonResponse
    {
        $this->exigir('consolidacao_view', 'consol_executar');

        return RespostaApi::sucesso($this->grupos->execucao($execucao), 'Execução de consolidação.');
    }

    public function mapa(Request $r, int $grupo): JsonResponse
    {
        $this->exigir('consolidacao_view', 'consol_executar');
        $f = $r->validate(['nivel' => ['nullable', 'in:conta,2,classe'], 'modo' => ['nullable', 'in:saldo,periodo'], 'data_inicio' => ['nullable', 'date_format:Y-m-d'],
            'data_fim' => ['nullable', 'date_format:Y-m-d'], 'contas' => ['nullable', 'string', 'max:200'], 'ocultar_zeros' => ['nullable', 'boolean']]);

        return RespostaApi::sucesso($this->consolidacao->mapa($grupo, $f), 'Mapa de Consolidação.');
    }

    /** @return array<string, mixed> */
    private function validar(Request $r): array
    {
        return $r->validate([
            'nome' => ['required', 'string', 'max:255'], 'nif' => ['required', 'string', 'max:30'], 'moeda_apresentacao' => ['nullable', 'string', 'size:3'],
            'conta_reserva_cambial' => ['nullable', 'string', 'max:20'], 'eliminacao_ativa' => ['sometimes', 'boolean'],
            'prefixos_excluidos_eliminacao' => ['nullable', 'string', 'max:10'], 'conta_diferenca_eliminacao' => ['nullable', 'string', 'max:20'],
            'membros' => ['required', 'array', 'min:1'], 'membros.*' => ['integer'],
        ]);
    }
}
