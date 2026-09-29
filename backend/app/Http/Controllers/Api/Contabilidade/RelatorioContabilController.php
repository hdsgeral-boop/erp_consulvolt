<?php

namespace App\Http\Controllers\Api\Contabilidade;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contabilidade\RelatorioPeriodoRequest;
use App\Services\Contabilidade\ServicoRelatoriosContabeis;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;

/** /api/contabilidade/relatorios/* — mapas contabilísticos (ecrã "relatorios_contabeis" e sub-ecrãs). */
final class RelatorioContabilController extends Controller
{
    public function __construct(private readonly ServicoRelatoriosContabeis $relatorios) {}

    public function balancete(RelatorioPeriodoRequest $request): JsonResponse
    {
        $this->exigir('contab_mapa_balancete_view');

        return RespostaApi::sucesso($this->relatorios->balancete($request->validated()), 'Balancete calculado com sucesso.');
    }

    public function razao(RelatorioPeriodoRequest $request): JsonResponse
    {
        $this->exigir('contab_mapa_balancete_view', 'contab_mapa_extrato_view');

        return RespostaApi::sucesso($this->relatorios->razao($request->validated()), 'Extracto da conta calculado com sucesso.');
    }

    public function desequilibrios(RelatorioPeriodoRequest $request): JsonResponse
    {
        $this->exigir('relatorios_contabeis_view');
        $f = $request->validated();

        return RespostaApi::sucesso($this->relatorios->desequilibrios($f['data_inicio'] ?? null, $f['data_fim'] ?? null),
            'Relatório de desequilíbrios calculado com sucesso.');
    }
}
