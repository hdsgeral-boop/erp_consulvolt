<?php

namespace App\Http\Controllers\Api\RH;

use App\Http\Controllers\Controller;
use App\Models\ContratoTrabalho;
use App\Services\RH\ServicoEstadoContrato;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * /api/rh/contratos/{id}/… — suspensão e cessação do contrato (Versão Actual: contrato_estado_ui.js, botões Suspender,
 * Reactivar, Cessar, Anular cessação e Ver estado). Permissões (permissoes.js): contratos_suspender para suspender e
 * reactivar; contratos_terminate para cessar e anular a cessação. A autorização vem sempre antes da validação.
 */
final class ContratoEstadoController extends Controller
{
    public function __construct(private readonly ServicoEstadoContrato $estado) {}

    public function fundamentos(): JsonResponse
    {
        $this->exigir('contratos_view', 'contratos_suspender', 'contratos_terminate');

        return RespostaApi::sucesso($this->estado->fundamentos(), 'Fundamentos da suspensão e da cessação (Lei 12/23).');
    }

    public function estado(int $contrato): JsonResponse
    {
        $this->exigir('contratos_view', 'contratos_suspender', 'contratos_terminate');

        return RespostaApi::sucesso($this->estado->estado(ContratoTrabalho::query()->findOrFail($contrato)), 'Estado do contrato.');
    }

    /** GET …/compensacao?motivo_codigo=&data= — proposta de compensação e processamentos posteriores (antes de cessar). */
    public function compensacao(Request $r, int $contrato): JsonResponse
    {
        $this->exigir('contratos_terminate');
        $d = $r->validate(['motivo_codigo' => ['required', Rule::in(array_keys(ServicoEstadoContrato::MOTIVOS_CESSACAO))], 'data' => ['required', 'date_format:Y-m-d']]);
        $c = ContratoTrabalho::query()->findOrFail($contrato);

        return RespostaApi::sucesso($this->estado->compensacao($c, $d['motivo_codigo'], $d['data'])
            + ['processamentos_posteriores' => $this->estado->processamentosPosteriores((int) $c->colaborador_id, $d['data'])], 'Proposta de compensação.');
    }

    public function suspender(Request $r, int $contrato): JsonResponse
    {
        $this->exigir('contratos_suspender');
        $d = $r->validate(['de' => ['required', 'date_format:Y-m-d'], 'ate' => ['nullable', 'date_format:Y-m-d'],
            'motivo_codigo' => ['required', Rule::in(array_keys(ServicoEstadoContrato::MOTIVOS_SUSPENSAO))], 'observacoes' => ['nullable', 'string', 'max:2000']],
            [], ['de' => 'início da suspensão', 'ate' => 'fim da suspensão', 'motivo_codigo' => 'fundamento']);

        return RespostaApi::sucesso($this->estado->suspender(ContratoTrabalho::query()->findOrFail($contrato), $d), 'Contrato suspenso.');
    }

    public function reactivar(Request $r, int $contrato): JsonResponse
    {
        $this->exigir('contratos_suspender');
        $d = $r->validate(['observacoes' => ['nullable', 'string', 'max:2000']]);

        return RespostaApi::sucesso($this->estado->reactivar(ContratoTrabalho::query()->findOrFail($contrato), $d['observacoes'] ?? null), 'Contrato reactivado.');
    }

    public function cessar(Request $r, int $contrato): JsonResponse
    {
        $this->exigir('contratos_terminate');
        $d = $r->validate(['data' => ['required', 'date_format:Y-m-d'], 'motivo_codigo' => ['required', Rule::in(array_keys(ServicoEstadoContrato::MOTIVOS_CESSACAO))],
            'aviso_previo_dias' => ['nullable', 'integer', 'between:0,365'], 'compensacao_valor' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'observacoes' => ['nullable', 'string', 'max:2000'], 'inactivar_colaborador' => ['nullable', 'boolean']],
            [], ['data' => 'data da cessação', 'motivo_codigo' => 'fundamento', 'compensacao_valor' => 'compensação']);

        return RespostaApi::sucesso($this->estado->cessar(ContratoTrabalho::query()->findOrFail($contrato), $d), 'Contrato cessado.');
    }

    public function anularCessacao(Request $r, int $contrato): JsonResponse
    {
        $this->exigir('contratos_terminate');
        $d = $r->validate(['observacoes' => ['required', 'string', 'min:5', 'max:2000']], [], ['observacoes' => 'motivo da anulação']);

        return RespostaApi::sucesso($this->estado->anularCessacao(ContratoTrabalho::query()->findOrFail($contrato), $d['observacoes']), 'Cessação anulada.');
    }
}
