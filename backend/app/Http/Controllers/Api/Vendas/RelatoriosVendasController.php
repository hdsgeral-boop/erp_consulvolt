<?php

namespace App\Http\Controllers\Api\Vendas;

use App\Http\Controllers\Controller;
use App\Services\Vendas\ServicoExportacaoSaft;
use App\Services\Vendas\ServicoRelatoriosVendas;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Ecrã «Relatórios de vendas» (vendas_relatorios): indicadores do período e validação prévia do SAF-T. */
final class RelatoriosVendasController extends Controller
{
    /** GET /api/vendas/relatorios/resumo?inicio=&fim=&top= — indicadores calculados em SQL. */
    public function resumo(Request $request, ServicoRelatoriosVendas $servico): JsonResponse
    {
        $this->exigir('vendas_relatorios_view');
        $d = $request->validate([
            'inicio' => ['required', 'date_format:Y-m-d'], 'fim' => ['required', 'date_format:Y-m-d', 'after_or_equal:inicio'],
            'top' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        return RespostaApi::sucesso($servico->resumo($d['inicio'], $d['fim'], (int) ($d['top'] ?? 10)), 'Resumo de vendas do período.');
    }

    /** GET /api/vendas/saft/validar?inicio=&fim= — valida o pedido do SAF-T sem gerar o ficheiro (erros no envelope JSON). */
    public function validarSaft(Request $request, ServicoExportacaoSaft $saft): JsonResponse
    {
        $this->exigir('vendas_relatorios_view', 'vendas_fe_config');
        $d = $request->validate(['inicio' => ['required', 'date_format:Y-m-d'], 'fim' => ['required', 'date_format:Y-m-d']]);

        return RespostaApi::sucesso($saft->validar($d['inicio'], $d['fim']), 'O SAF-T pode ser gerado para o período.');
    }
}
