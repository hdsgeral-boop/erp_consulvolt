<?php

namespace App\Http\Controllers\Api\Sistema;

use App\Http\Controllers\Controller;
use App\Http\Resources\Sistema\EmpresaResource;
use App\Models\Empresa;
use App\Services\Sistema\ServicoEmpresas;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Empresas acessíveis ao utilizador (selector de empresa do Top Header).
 * O CRUD de empresas (config_empresas) chega com o módulo Sistema (Fase 4).
 */
final class EmpresaController extends Controller
{
    public function __construct(private readonly ServicoEmpresas $empresas) {}

    /** GET /api/sistema/empresas */
    public function index(Request $request): JsonResponse
    {
        return RespostaApi::sucesso(
            EmpresaResource::collection($this->empresas->acessiveis($request->user())),
            'Empresas acessíveis obtidas com sucesso.',
        );
    }

    /** GET /api/sistema/empresas/{empresa} — 404 também quando não há acesso (não revela a existência). */
    public function show(Request $request, int $empresa): JsonResponse
    {
        abort_unless($this->empresas->podeAceder($request->user(), $empresa), 404);

        return RespostaApi::sucesso(EmpresaResource::make(Empresa::query()->findOrFail($empresa)), 'Empresa obtida com sucesso.');
    }
}
