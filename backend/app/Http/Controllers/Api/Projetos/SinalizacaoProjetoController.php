<?php

namespace App\Http\Controllers\Api\Projetos;

use App\Http\Controllers\Controller;
use App\Models\SinalizacaoProjeto;
use App\Services\Projetos\ServicoSinalizacoesProjeto;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /api/projetos/sinalizacoes — catálogo de sinalizações dos projectos (modal «Etiquetas e sinalizações»). */
final class SinalizacaoProjetoController extends Controller
{
    public function __construct(private readonly ServicoSinalizacoesProjeto $sinalizacoes) {}

    public function index(): JsonResponse
    {
        $this->exigir(...ProjetoController::LER);

        return RespostaApi::sucesso($this->sinalizacoes->listar(), 'Sinalizações de projectos.');
    }

    public function store(Request $r): JsonResponse
    {
        $this->exigir('proj_gerir');

        return RespostaApi::criado($this->sinalizacoes->guardar($this->validar($r)), 'Sinalização criada.');
    }

    public function update(Request $r, int $sinalizacao): JsonResponse
    {
        $this->exigir('proj_gerir');

        return RespostaApi::sucesso($this->sinalizacoes->guardar($this->validar($r), SinalizacaoProjeto::query()->findOrFail($sinalizacao)), 'Sinalização actualizada.');
    }

    public function destroy(int $sinalizacao): JsonResponse
    {
        $this->exigir('proj_gerir');
        $this->sinalizacoes->eliminar(SinalizacaoProjeto::query()->findOrFail($sinalizacao));

        return RespostaApi::sucesso(null, 'Sinalização eliminada.');
    }

    /** @return array<string, mixed> */
    private function validar(Request $r): array
    {
        return $r->validate(['nome' => ['required', 'string', 'max:60'], 'cor' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/']]);
    }
}
