<?php

namespace App\Http\Controllers\Api\POS;

use App\Http\Controllers\Controller;
use App\Http\Requests\POS\Impressao\ConsultarConfigImpressaoRequest;
use App\Http\Requests\POS\Impressao\GuardarConfigImpressaoRequest;
use App\Http\Resources\POS\ConfigImpressaoPOSResource;
use App\Services\POS\ServicoConfigImpressaoPOS;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;

/**
 * Configuração de impressão do POS na BD (F7): GET/PUT /api/pos/impressao/config (empresa, ou terminal com `terminal_pos_id`)
 * e DELETE /api/pos/impressao/config/terminal/{terminal} (volta a herdar da empresa). Isolada por empresa (RLS).
 */
final class ConfigImpressaoPOSController extends Controller
{
    public function __construct(private readonly ServicoConfigImpressaoPOS $servico) {}

    public function mostrar(ConsultarConfigImpressaoRequest $r): JsonResponse
    {
        $t = $r->validated('terminal_pos_id');

        return RespostaApi::sucesso(new ConfigImpressaoPOSResource($this->servico->obter($t ? (int) $t : null)), 'Configuração de impressão do POS.');
    }

    public function guardar(GuardarConfigImpressaoRequest $r): JsonResponse
    {
        $d = $r->validated();
        $t = $d['terminal_pos_id'] ?? null;

        return RespostaApi::sucesso(new ConfigImpressaoPOSResource($this->servico->guardar($d, $t ? (int) $t : null)), 'Configuração de impressão do POS gravada.');
    }

    public function removerTerminal(int $terminal): JsonResponse
    {
        $this->exigir('pos_print_config');

        return RespostaApi::sucesso(new ConfigImpressaoPOSResource($this->servico->removerSobreposicao($terminal)), 'Sobreposição do terminal removida.');
    }
}
