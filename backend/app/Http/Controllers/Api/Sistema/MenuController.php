<?php

namespace App\Http\Controllers\Api\Sistema;

use App\Http\Controllers\Controller;
use App\Services\Sistema\ServicoPermissoes;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /api/sistema/menu — menu e permissões efectivas do utilizador na empresa activa (frontend, Fase 5). */
final class MenuController extends Controller
{
    public function __construct(
        private readonly ServicoPermissoes $permissoes,
        private readonly ContextoEmpresa $contexto,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $empresa = $this->contexto->obrigatorio();

        return RespostaApi::sucesso([
            'menu' => $this->permissoes->menu($request->user(), $empresa),
            'permissoes' => $this->permissoes->efectivas($request->user(), $empresa),
        ], 'Menu obtido com sucesso.');
    }
}
