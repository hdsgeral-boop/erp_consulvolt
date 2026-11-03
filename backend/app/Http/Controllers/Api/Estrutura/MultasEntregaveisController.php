<?php

namespace App\Http\Controllers\Api\Estrutura;

use App\Http\Controllers\Controller;
use App\Models\MultaEntregavel;
use App\Models\PeriodoProcessamentoSalarial;
use App\Services\Estrutura\ServicoMultasEntregaveis;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * /api/rh/estrutura/multas — multas de entregáveis: propor a partir dos atrasos, aplicar, dispensar, repor; e na folha
 * (/api/rh/salarios/periodos/{id}/importar-multas-entregaveis) lançar e anular o desconto (Fase K, ADR-079).
 * Ver: est_estrutura_view ou est_multas_gerir. Propor, decidir e repor: est_multas_gerir (nunca o próprio penalizado — regra no serviço).
 * Folha: a verificação de «Importar Empréstimos» (rh_emp_lancar) E est_multas_gerir. NADA se desconta sem uma pessoa aplicar a multa.
 */
final class MultasEntregaveisController extends Controller
{
    public function __construct(private readonly ServicoMultasEntregaveis $multas) {}

    /** GET /rh/estrutura/multas?estado&unidade_id&colaborador_id&de&ate */
    public function index(Request $r): JsonResponse
    {
        $this->exigir('est_estrutura_view', 'est_multas_gerir');
        $f = $r->validate(['estado' => ['nullable', Rule::in(MultaEntregavel::ESTADOS)], 'unidade_id' => ['nullable', 'integer'], 'colaborador_id' => ['nullable', 'integer'],
            'de' => ['nullable', 'date_format:Y-m-d'], 'ate' => ['nullable', 'date_format:Y-m-d']]);

        return RespostaApi::sucesso($this->multas->listar($f), 'Multas de entregáveis.');
    }

    /** POST /rh/estrutura/multas/propor {unidades[]?} */
    public function propor(Request $r): JsonResponse
    {
        $this->exigir('est_multas_gerir');
        $d = $r->validate(['unidades' => ['nullable', 'array', 'max:500'], 'unidades.*' => ['integer']]);
        $res = $this->multas->proporDoPeriodo($d['unidades'] ?? null);

        return RespostaApi::sucesso($res, "{$res['criadas']} multa(s) proposta(s), {$res['ignoradas']} já existente(s)".($res['avisos'] ? ', '.count($res['avisos']).' aviso(s)' : '').'.');
    }

    public function aplicar(Request $r, int $multa): JsonResponse
    {
        $this->exigir('est_multas_gerir');
        $d = $r->validate(['nota' => ['nullable', 'string', 'max:2000']]);

        return RespostaApi::sucesso($this->multas->aplicar(MultaEntregavel::query()->findOrFail($multa), $d['nota'] ?? null), 'Multa aplicada: será descontada quando for lançada na folha.');
    }

    public function dispensar(Request $r, int $multa): JsonResponse
    {
        $this->exigir('est_multas_gerir');
        $d = $r->validate(['motivo' => ['required', 'string', 'min:3', 'max:2000']]);

        return RespostaApi::sucesso($this->multas->dispensar(MultaEntregavel::query()->findOrFail($multa), $d['motivo']), 'Multa dispensada.');
    }

    public function repor(int $multa): JsonResponse
    {
        $this->exigir('est_multas_gerir');

        return RespostaApi::sucesso($this->multas->repor(MultaEntregavel::query()->findOrFail($multa)), 'Multa reposta como proposta.');
    }

    // ───────────── Folha ─────────────

    /** POST /rh/salarios/periodos/{id}/importar-multas-entregaveis — «Importar Multas». */
    public function importar(Request $r, int $id): JsonResponse
    {
        $this->exigir('rh_emp_lancar');
        $this->exigir('est_multas_gerir');
        $d = $r->validate(['substituir' => ['nullable', 'boolean']]);
        $res = $this->multas->lancarNoPeriodo(PeriodoProcessamentoSalarial::query()->findOrFail($id), (bool) ($d['substituir'] ?? false));

        return RespostaApi::sucesso($res, "Multas: {$res['lancados']} lançada(s), {$res['actualizados']} actualizada(s), {$res['ignorados']} já existente(s)".($res['avisos'] ? ', '.count($res['avisos']).' aviso(s)' : '').'.');
    }

    /** POST /rh/salarios/periodos/{id}/anular-multas-entregaveis — desfaz o desconto das multas no período (aberto). */
    public function anular(int $id): JsonResponse
    {
        $this->exigir('rh_emp_lancar');
        $this->exigir('est_multas_gerir');
        $n = $this->multas->anularDoPeriodo(PeriodoProcessamentoSalarial::query()->findOrFail($id));

        return RespostaApi::sucesso(['repostas' => $n], "{$n} multa(s) reposta(s) como aplicadas (por descontar).");
    }
}
