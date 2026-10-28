<?php

namespace App\Http\Controllers\Api\Projetos;

use App\Http\Controllers\Controller;
use App\Models\EtiquetaProjeto;
use App\Services\Projetos\ServicoEtiquetasProjeto;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /api/projetos/etiquetas — catálogo de etiquetas dos projectos (modal «Etiquetas e sinalizações»). */
final class EtiquetaProjetoController extends Controller
{
    public function __construct(private readonly ServicoEtiquetasProjeto $etiquetas) {}

    public function index(): JsonResponse
    {
        $this->exigir(...ProjetoController::LER);

        return RespostaApi::sucesso($this->etiquetas->listar(), 'Etiquetas de projectos.');
    }

    public function store(Request $r): JsonResponse
    {
        $this->exigir('proj_gerir');

        return RespostaApi::criado($this->etiquetas->guardar($this->validar($r)), 'Etiqueta criada.');
    }

    public function update(Request $r, int $etiqueta): JsonResponse
    {
        $this->exigir('proj_gerir');

        return RespostaApi::sucesso($this->etiquetas->guardar($this->validar($r), EtiquetaProjeto::query()->findOrFail($etiqueta)), 'Etiqueta actualizada.');
    }

    public function destroy(int $etiqueta): JsonResponse
    {
        $this->exigir('proj_gerir');
        $this->etiquetas->eliminar(EtiquetaProjeto::query()->findOrFail($etiqueta));

        return RespostaApi::sucesso(null, 'Etiqueta eliminada.');
    }

    /** @return array<string, mixed> */
    private function validar(Request $r): array
    {
        return $r->validate(['nome' => ['required', 'string', 'max:60'], 'cor' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/']]);
    }
}
