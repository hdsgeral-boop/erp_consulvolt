<?php

namespace App\Http\Controllers\Api\Reparticao;

use App\Http\Controllers\Api\Contabilidade\ValidaFiltrosMapas;
use App\Http\Controllers\Controller;
use App\Services\Reparticao\ServicoMapasPorUnidade;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/contabilidade/relatorios/{balanco,demonstracao-resultados}/por-unidade — Balanço e DR com uma coluna por unidade de
 * negócio (Fase C; botão «Por unidade de negócio» do legado novo, ui_reports.js:376-406). Mesmas permissões dos mapas.
 */
final class MapasPorUnidadeController extends Controller
{
    use ValidaFiltrosMapas;

    public function __construct(private readonly ServicoMapasPorUnidade $mapas) {}

    public function balanco(Request $request): JsonResponse
    {
        $this->exigir('contab_mapa_balanco_view');
        $f = $this->filtros($request, ['data_fim' => ['required', 'date_format:Y-m-d'], 'data_inicio' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:data_fim']]);

        return RespostaApi::sucesso($this->mapas->balanco($f), 'Balanço por unidade de negócio calculado com sucesso.');
    }

    public function demonstracaoResultados(Request $request): JsonResponse
    {
        $this->exigir('contab_mapa_dr_view');
        $f = $this->filtros($request, ['data_inicio' => ['required', 'date_format:Y-m-d'], 'data_fim' => ['required', 'date_format:Y-m-d', 'after_or_equal:data_inicio']]);

        return RespostaApi::sucesso($this->mapas->demonstracaoResultados($f), 'Demonstração de resultados por unidade de negócio calculada com sucesso.');
    }
}
