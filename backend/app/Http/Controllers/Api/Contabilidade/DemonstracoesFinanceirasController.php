<?php

namespace App\Http\Controllers\Api\Contabilidade;

use App\Http\Controllers\Controller;
use App\Services\Contabilidade\ServicoDemonstracoesFinanceiras;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/contabilidade/relatorios/{balanco, demonstracao-resultados, fluxo-caixa, movimentos-sem-nota, notas/...} —
 * separadores Balanço, DR e Fluxo de Caixa do ecrã "relatorios_contabeis" (ADR-055).
 */
final class DemonstracoesFinanceirasController extends Controller
{
    use ValidaFiltrosMapas;

    public function __construct(private readonly ServicoDemonstracoesFinanceiras $df) {}

    public function balanco(Request $request): JsonResponse
    {
        $this->exigir('contab_mapa_balanco_view');
        $f = $this->filtros($request, ['data_fim' => ['required', 'date_format:Y-m-d'], 'data_inicio' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:data_fim'],
            'comparativo' => ['nullable', 'boolean']]);

        return RespostaApi::sucesso($this->df->balanco($f), 'Balanço calculado com sucesso.');
    }

    public function demonstracaoResultados(Request $request): JsonResponse
    {
        $this->exigir('contab_mapa_dr_view');
        $f = $this->filtros($request, ['data_inicio' => ['required', 'date_format:Y-m-d'], 'data_fim' => ['required', 'date_format:Y-m-d', 'after_or_equal:data_inicio'],
            'comparativo' => ['nullable', 'boolean'], 'modo_comparativo' => ['nullable', 'in:ano_anterior,homologo']]);

        return RespostaApi::sucesso($this->df->demonstracaoResultados($f), 'Demonstração de resultados calculada com sucesso.');
    }

    public function fluxoCaixa(Request $request): JsonResponse
    {
        $this->exigir('contab_mapa_fluxo_view');
        $f = $this->filtros($request, ['data_inicio' => ['required', 'date_format:Y-m-d'], 'data_fim' => ['required', 'date_format:Y-m-d', 'after_or_equal:data_inicio'],
            'comparativo' => ['nullable', 'boolean']]);

        return RespostaApi::sucesso($this->df->fluxoCaixa($f), 'Demonstração de fluxos de caixa calculada com sucesso.');
    }

    /** "Movimentos por mapear" do alerta do Balanço (drillDownToUnmappedBalance). */
    public function movimentosSemNota(Request $request): JsonResponse
    {
        $this->exigir('contab_mapa_balanco_view', 'contab_mapa_dr_view', 'relatorios_contabeis_view', 'lancamentos_view');
        $f = $this->filtros($request, ['data_fim' => ['required', 'date_format:Y-m-d'], 'data_inicio' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:data_fim'],
            'limite' => ['nullable', 'integer', 'min:1', 'max:20000']]);

        return RespostaApi::sucesso($this->df->movimentosSemNota($f), 'Movimentos sem nota obtidos com sucesso.');
    }

    /** Detalhe de uma nota (tipo = demonstracao | fluxo). */
    public function detalheNota(Request $request, string $tipo, int $nota): JsonResponse
    {
        $this->exigir('contab_mapa_balanco_view', 'contab_mapa_dr_view', 'contab_mapa_fluxo_view');
        $f = $this->filtros($request, ['data_fim' => ['required', 'date_format:Y-m-d'], 'data_inicio' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:data_fim']]);

        return RespostaApi::sucesso($this->df->detalheNota($tipo, $nota, $f), 'Detalhe da nota obtido com sucesso.');
    }
}
