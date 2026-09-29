<?php

namespace App\Http\Controllers\Api\Vendas;

use App\Http\Controllers\Controller;
use App\Models\SerieFaturacaoEletronica;
use App\Services\Vendas\ServicoConfigVendas;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /api/vendas/configuracao — contas de vendas (vendas_config) e séries de numeração (vendas_fe_config). */
final class ConfigVendasController extends Controller
{
    public function __construct(private readonly ServicoConfigVendas $config) {}

    public function contas(): JsonResponse
    {
        $this->exigir('vendas_faturacao_view', 'vendas_config');

        return RespostaApi::sucesso($this->config->todas(), 'Contas de vendas obtidas com sucesso.');
    }

    public function definirContas(Request $request): JsonResponse
    {
        $this->exigir('vendas_config');
        $regras = [];
        foreach (array_keys(ServicoConfigVendas::CHAVES) as $chave) {
            $regras["contas.{$chave}"] = ['nullable', 'string', 'max:20'];
        }
        $d = $request->validate(['contas' => ['required', 'array']] + $regras);
        $this->config->definir($d['contas']);

        return RespostaApi::sucesso($this->config->todas(), 'Contas de vendas actualizadas.');
    }

    public function series(Request $request): JsonResponse
    {
        $this->exigir('vendas_faturacao_view', 'vendas_fe_config');
        $f = $request->validate(['ano' => ['nullable', 'integer', 'min:2000', 'max:2100'], 'tipo' => ['nullable', 'string', 'max:5']]);
        $series = SerieFaturacaoEletronica::query()
            ->when($f['ano'] ?? null, fn ($q, $v) => $q->where('ano', $v))
            ->when($f['tipo'] ?? null, fn ($q, $v) => $q->where('tipo', $v))
            ->orderByDesc('ano')->orderBy('tipo')->orderBy('codigo')
            ->get(['id', 'codigo', 'tipo', 'ano', 'origem', 'origem_nome', 'estado', 'contingencia', 'proximo_numero', 'ultima_data', 'agt_ultimo_numero']);

        return RespostaApi::sucesso($series, 'Séries obtidas com sucesso.');
    }
}
