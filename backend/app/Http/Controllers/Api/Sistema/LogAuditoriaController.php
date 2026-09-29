<?php

namespace App\Http\Controllers\Api\Sistema;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sistema\ListarLogsAuditoriaRequest;
use App\Http\Resources\Sistema\LogAuditoriaResource;
use App\Services\Sistema\ServicoLogsAuditoria;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class LogAuditoriaController extends Controller
{
    public function __construct(private readonly ServicoLogsAuditoria $logs) {}

    /** GET /api/sistema/logs — requer a consulta do ecrã "config_logs" (paridade de permissões). */
    public function index(ListarLogsAuditoriaRequest $request): JsonResponse
    {
        Gate::authorize('config_logs_view');

        return RespostaApi::paginado($this->logs->listar($request->validated()), LogAuditoriaResource::class);
    }
}
