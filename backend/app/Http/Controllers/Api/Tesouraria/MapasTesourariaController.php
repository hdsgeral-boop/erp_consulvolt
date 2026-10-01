<?php

namespace App\Http\Controllers\Api\Tesouraria;

use App\Http\Controllers\Controller;
use App\Services\Tesouraria\ServicoMapasTesouraria;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /api/tesouraria/disponibilidades e /api/tesouraria/extrato-conta — ecrã teso_gestao_mapas (ADR-064). */
final class MapasTesourariaController extends Controller
{
    public function __construct(private readonly ServicoMapasTesouraria $mapas) {}

    public function disponibilidades(Request $r): JsonResponse
    {
        $this->exigir('teso_gestao_mapas_view');
        $f = $r->validate(['data' => ['nullable', 'date_format:Y-m-d']]);

        return RespostaApi::sucesso($this->mapas->disponibilidades($f['data'] ?? now()->toDateString()), 'Disponibilidades.');
    }

    public function extratoConta(Request $r): JsonResponse
    {
        $this->exigir('teso_gestao_mapas_view');
        $f = $r->validate(['codigo_conta' => ['required', 'string', 'max:20'], 'data_inicio' => ['required', 'date_format:Y-m-d'],
            'data_fim' => ['required', 'date_format:Y-m-d', 'after_or_equal:data_inicio']], [], ['codigo_conta' => 'conta', 'data_inicio' => 'data inicial', 'data_fim' => 'data final']);

        return RespostaApi::sucesso($this->mapas->extratoConta($f['codigo_conta'], $f['data_inicio'], $f['data_fim']), 'Extracto da conta.');
    }
}
