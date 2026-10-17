<?php

namespace App\Http\Controllers\Api\Contabilidade;

use App\Http\Controllers\Controller;
use App\Services\Contabilidade\ServicoComposicaoDemonstracoes;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/contabilidade/relatorios/composicao/{tipo} — cálculo detalhado de uma rubrica do Balanço, da Demonstração de
 * resultados ou dos Fluxos de caixa (contas que a compõem, ajustes e rubricas somadas) e, com `codigo_conta`, os
 * movimentos dessa conta na rubrica (ligação ao lançamento). Permissão: a do próprio mapa.
 */
final class ComposicaoDemonstracoesController extends Controller
{
    use ValidaFiltrosMapas;

    private const PERMISSOES = ['balanco' => 'contab_mapa_balanco_view', 'dr' => 'contab_mapa_dr_view', 'fluxo' => 'contab_mapa_fluxo_view'];

    public function show(Request $request, string $tipo, ServicoComposicaoDemonstracoes $servico): JsonResponse
    {
        $this->exigir(self::PERMISSOES[$tipo]);
        $f = $this->filtros($request, [
            'data_fim' => ['required', 'date_format:Y-m-d'],
            'data_inicio' => [$tipo === 'balanco' ? 'nullable' : 'required', 'date_format:Y-m-d', 'before_or_equal:data_fim'],
            'modo_comparativo' => ['nullable', 'in:ano_anterior,homologo'],
            'rubrica' => ['required', 'string', 'max:40', 'regex:/^[0-9A-Za-z_.]+$/'],
            'periodo' => ['nullable', 'in:atual,anterior'],
            'codigo_conta' => ['nullable', 'string', 'max:20', 'regex:/^[0-9A-Za-z.]+$/'],
        ]);

        return RespostaApi::sucesso($servico->composicao($tipo, $f), 'Cálculo da rubrica obtido com sucesso.');
    }
}
