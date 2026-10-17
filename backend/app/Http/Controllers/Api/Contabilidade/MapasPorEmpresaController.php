<?php

namespace App\Http\Controllers\Api\Contabilidade;

use App\Http\Controllers\Controller;
use App\Services\Contabilidade\ServicoMapasPorEmpresa;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/contabilidade/relatorios/por-empresa/{mapa} — mapas legais da holding com uma coluna por empresa do grupo,
 * as eliminações, a conversão e o consolidado (interruptor «Por empresa», legado renderPorEmpresa). Permissão: a do mapa.
 */
final class MapasPorEmpresaController extends Controller
{
    use ValidaFiltrosMapas;

    private const PERMISSOES = ['balancete' => 'contab_mapa_balancete_view', 'balanco' => 'contab_mapa_balanco_view', 'dr' => 'contab_mapa_dr_view', 'fluxo' => 'contab_mapa_fluxo_view'];

    public function show(Request $request, string $mapa, ServicoMapasPorEmpresa $servico): JsonResponse
    {
        $this->exigir(self::PERMISSOES[$mapa]);
        $f = $this->filtros($request, [
            'data_fim' => ['required', 'date_format:Y-m-d'],
            'data_inicio' => [$mapa === 'balanco' ? 'nullable' : 'required', 'date_format:Y-m-d', 'before_or_equal:data_fim'],
            'nivel' => ['nullable', 'integer', 'min:1', 'max:20'],
            'totalizadoras' => ['nullable', 'boolean'], 'so_movimento' => ['nullable', 'boolean'], 'sem_saldo_inicial' => ['nullable', 'boolean'],
            'sem_saldo_zero' => ['nullable', 'boolean'], 'excluir_estornos' => ['nullable', 'boolean'],
        ]);
        foreach (['excluir_estornos'] as $b) {
            if (array_key_exists($b, $f)) {
                $f[$b] = filter_var($f[$b], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return RespostaApi::sucesso($servico->calcular($mapa, $f), 'Mapa por empresa calculado com sucesso.');
    }
}
