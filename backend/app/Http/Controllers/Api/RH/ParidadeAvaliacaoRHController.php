<?php

namespace App\Http\Controllers\Api\RH;

use App\Http\Controllers\Controller;
use App\Models\CicloAvaliacao360;
use App\Services\RH\ServicoAcompanhamento360RH;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;

/**
 * /api/rh/paridade/avaliacao — «Acompanhamento do Ciclo 360º» com os separadores do sistema anterior (relatório 6.5.10):
 * participação, reuniões de acompanhamento e confirmações do comunicado. Só leitura; ciclo da empresa activa (o modelo
 * tem o isolamento por empresa, um ciclo de outra empresa dá 404).
 */
final class ParidadeAvaliacaoRHController extends Controller
{
    public function __construct(private readonly ServicoAcompanhamento360RH $servico) {}

    /** GET /rh/paridade/avaliacao/ciclos/{ciclo}/acompanhamento */
    public function acompanhamento(int $ciclo): JsonResponse
    {
        $this->exigir('rh_avaliacao_ciclo_view', 'rh_avaliacao_config_view');

        return RespostaApi::sucesso($this->servico->acompanhamento(CicloAvaliacao360::query()->findOrFail($ciclo)), 'Acompanhamento do ciclo 360º.');
    }
}
