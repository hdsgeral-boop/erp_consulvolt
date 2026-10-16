<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Services\RH\ServicoPortalColaborador;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;

/**
 * /api/portal — portal autónomo do colaborador (Versão Actual: portal.html, ADR-071). A guarda é feita aqui, no
 * servidor, para a empresa activa (X-Empresa-Id validado pelo middleware de empresa); os separadores usam as rotas
 * /api/rh/portal/*, que voltam a exigir o colaborador do próprio utilizador.
 */
final class PortalAutonomoController extends Controller
{
    public function __construct(private readonly ServicoPortalColaborador $portal) {}

    /** GET /portal/acesso — 200 com o colaborador do próprio e as aprovações pendentes; 403 SEM_ACESSO_PORTAL sem acesso. */
    public function acesso(): JsonResponse
    {
        return RespostaApi::sucesso($this->portal->exigirAcessoPortal(), 'Acesso ao portal do colaborador.');
    }
}
