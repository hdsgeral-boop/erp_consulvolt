<?php

namespace App\Http\Controllers\Api\RH;

use App\Http\Controllers\Controller;
use App\Models\AusenciaFaltaColaborador;
use App\Models\EfectividadeAssiduidade;
use App\Models\FechoMensalAssiduidade;
use App\Models\PeriodoProcessamentoSalarial;
use App\Services\RH\ServicoAssiduidade;
use App\Services\RH\ServicoAusencias;
use App\Services\RH\ServicoCalendarioRH;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** /api/rh/assiduidade — efectividade, apuramento e fecho mensal, ausências e configuração (ecrã «Efectividade»). */
final class AssiduidadeController extends Controller
{
    public function __construct(
        private readonly ServicoAssiduidade $assiduidade,
        private readonly ServicoAusencias $ausencias,
        private readonly ServicoCalendarioRH $calendario,
    ) {}

    // ───────────── Configuração ─────────────

    public function config(): JsonResponse
    {
        $this->exigir('rh_assiduidade_view', 'rh_assid_config');

        return RespostaApi::sucesso($this->calendario->config(), 'Configuração da assiduidade.');
    }

    public function gravarConfig(Request $r): JsonResponse
    {
        $this->exigir('rh_assid_config');
        $d = $r->validate([
            'dias_uteis' => ['required', 'array', 'min:1'], 'dias_uteis.*' => ['integer', 'between:0,6'],
            'tolerancia_min' => ['required', 'integer', 'between:0,120'], 'arredondamento_min' => ['required', Rule::in(ServicoCalendarioRH::ARREDONDAMENTOS)],
            'extras_min_minutos' => ['required', 'integer', 'between:0,240'], 'feriados' => ['nullable', 'array'], 'feriados.*' => ['date_format:Y-m-d'],
            'relogio' => ['nullable', 'array'], 'relogio.url' => ['nullable', 'string', 'max:500'], 'relogio.formato' => ['nullable', 'in:CSV,JSON'],
            'modo_compensacao' => ['required', Rule::in(ServicoCalendarioRH::MODOS_COMPENSACAO)], 'limite_compensacao_h' => ['nullable', 'numeric', 'between:0,200'],
            'extra_nao_util_exige_autorizacao' => ['required', 'boolean'],
        ]);

        return RespostaApi::sucesso($this->calendario->gravarConfig($d), 'Configuração da assiduidade gravada.');
    }

    // ───────────── Registos ─────────────

    public function registos(Request $r): JsonResponse
    {
        $this->exigir('rh_assiduidade_view');
        $f = $r->validate(['mes' => ['required', 'string'], 'colaborador_id' => ['nullable', 'integer']]);
        $mes = ServicoCalendarioRH::mes($f['mes']);

        return RespostaApi::sucesso(EfectividadeAssiduidade::query()->whereBetween('data', ["{$mes}-01", date('Y-m-t', strtotime("{$mes}-01"))])
            ->when($f['colaborador_id'] ?? null, fn ($q, $c) => $q->where('colaborador_id', $c))->orderBy('data')->orderBy('colaborador_id')->get(), 'Registos de efectividade.');
    }

    public function gravarRegisto(Request $r): JsonResponse
    {
        $this->exigir('rh_assid_registar');
        $d = $r->validate(['colaborador_id' => ['required', 'integer'], 'data' => ['required', 'date_format:Y-m-d'], 'entrada' => ['nullable', 'date_format:H:i'],
            'saida' => ['nullable', 'date_format:H:i'], 'horas' => ['nullable', 'numeric'], 'observacoes' => ['nullable', 'string', 'max:1000'],
            'autorizado_extra' => ['nullable', 'boolean']]);

        return RespostaApi::sucesso($this->assiduidade->gravarRegisto($d), 'Registo de efectividade gravado.');
    }

    public function eliminarRegisto(int $registo): JsonResponse
    {
        $this->exigir('rh_assid_registar');
        $this->assiduidade->eliminarRegisto(EfectividadeAssiduidade::query()->findOrFail($registo));

        return RespostaApi::sucesso(null, 'Registo eliminado.');
    }

    public function importar(Request $r): JsonResponse
    {
        $this->exigir('rh_assid_registar');
        $d = $r->validate(['ficheiro' => ['required', 'file', 'max:10240', 'mimes:csv,txt,xlsx,xls'], 'substituir' => ['nullable', 'boolean']]);
        $res = $this->assiduidade->importar($d['ficheiro'], (bool) ($d['substituir'] ?? true));

        return RespostaApi::sucesso($res, "Importação: {$res['gravados']} registo(s) gravado(s), {$res['ignorados']} ignorado(s), ".count($res['erros']).' erro(s).');
    }

    // ───────────── Mês ─────────────

    public function mes(string $mes): JsonResponse
    {
        $this->exigir('rh_assiduidade_view');
        $f = $this->assiduidade->estadoMes($mes);
        $resumo = $f?->estado === 'FECHADO' ? ['mes' => $f->mes, 'dias_uteis' => $f->dias_uteis, 'apurado_ate' => $f->apurado_ate?->toDateString(), 'linhas' => $f->linhas, 'totais' => $f->totais]
            : $this->assiduidade->resumoMes($mes);

        return RespostaApi::sucesso($resumo + ['estado' => $f?->estado ?? 'ABERTO', 'fecho' => $f?->only(['id', 'fechado_em', 'fechado_por', 'reaberto_em', 'reaberto_por',
            'motivo_reabertura', 'lancado_em', 'lancado_por', 'periodo_processamento_salarial_id', 'ausencias_geradas'])], 'Apuramento do mês.');
    }

    public function detectar(string $mes): JsonResponse
    {
        $this->exigir('rh_assid_registar');
        $r = $this->assiduidade->detectarFaltas($mes);

        return RespostaApi::sucesso($r, "Faltas por justificar: {$r['criadas']} nova(s), {$r['removidas']} retirada(s).");
    }

    public function fechar(string $mes): JsonResponse
    {
        $this->exigir('rh_assid_fechar');

        return RespostaApi::sucesso($this->assiduidade->fechar($mes), 'Efectividade do mês fechada.');
    }

    public function reabrir(Request $r, string $mes): JsonResponse
    {
        $this->exigir('rh_assid_fechar');
        $d = $r->validate(['motivo' => ['required', 'string', 'min:5', 'max:500']]);

        return RespostaApi::sucesso($this->assiduidade->reabrir($mes, $d['motivo']), 'Efectividade do mês reaberta.');
    }

    public function fechos(): JsonResponse
    {
        $this->exigir('rh_assiduidade_view');

        return RespostaApi::sucesso(FechoMensalAssiduidade::query()->orderByDesc('mes')->get(['id', 'mes', 'estado', 'dias_uteis', 'totais', 'apurado_ate', 'fechado_em',
            'fechado_por', 'lancado_em', 'periodo_processamento_salarial_id', 'ausencias_geradas']), 'Fechos mensais da efectividade.');
    }

    /** POST /api/rh/salarios/periodos/{id}/importar-efectividade — ecrã Calcular («Importar Efectividade»). */
    public function lancar(Request $r, int $id): JsonResponse
    {
        $this->exigir('calcular_folha');
        $d = $r->validate(['infotipo_extra_id' => ['required', 'integer'], 'infotipo_falta_id' => ['required', 'integer']]);
        $res = $this->assiduidade->lancarNoPeriodo(PeriodoProcessamentoSalarial::query()->findOrFail($id), (int) $d['infotipo_extra_id'], (int) $d['infotipo_falta_id']);

        return RespostaApi::sucesso($res, "Efectividade lançada: {$res['lancados']} lançamento(s); {$res['removidos']} retirado(s).");
    }

    // ───────────── Ausências ─────────────

    public function catalogo(): JsonResponse
    {
        $this->exigir('rh_assiduidade_view', 'rh_portal_usar');

        return RespostaApi::sucesso(ServicoAusencias::CATALOGO, 'Tipos de ausência (Lei 12/23).');
    }

    public function ausencias(Request $r): JsonResponse
    {
        $this->exigir('rh_assiduidade_view', 'rh_portal_gestao_view');
        $f = $r->validate(['colaborador_id' => ['nullable', 'integer'], 'estado' => ['nullable', 'string'], 'mes' => ['nullable', 'string'], 'ano' => ['nullable', 'integer']]);

        return RespostaApi::sucesso(AusenciaFaltaColaborador::query()
            ->when($f['colaborador_id'] ?? null, fn ($q, $c) => $q->where('colaborador_id', $c))
            ->when($f['estado'] ?? null, fn ($q, $e) => $q->where('estado', $e))
            ->when($f['mes'] ?? null, fn ($q, $m) => $q->where('data_inicio', '<=', date('Y-m-t', strtotime(ServicoCalendarioRH::mes($m).'-01')))->where('data_fim', '>=', ServicoCalendarioRH::mes($m).'-01'))
            ->when($f['ano'] ?? null, fn ($q, $a) => $q->whereYear('data_inicio', $a))
            ->orderByDesc('data_inicio')->get(), 'Ausências.');
    }

    public function criarAusencia(Request $r): JsonResponse
    {
        $this->exigir('rh_assid_registar');

        return RespostaApi::criado($this->ausencias->criar($r->validate($this->regrasAusencia() + ['colaborador_id' => ['required', 'integer'],
            'data_inicio' => ['required', 'date_format:Y-m-d'], 'data_fim' => ['required', 'date_format:Y-m-d'], 'horas' => ['nullable', 'numeric', 'gt:0', 'max:744']])),
            'Ausência registada: aguarda decisão.');
    }

    public function justificar(Request $r, int $ausencia): JsonResponse
    {
        $this->exigir('rh_assid_registar');

        return RespostaApi::sucesso($this->ausencias->justificar(AusenciaFaltaColaborador::query()->findOrFail($ausencia), $r->validate($this->regrasAusencia())),
            'Falta justificada: aguarda decisão.');
    }

    public function decidir(Request $r, int $ausencia): JsonResponse
    {
        $this->exigir('rh_portal_aprovar');
        $d = $r->validate(['decisao' => ['required', 'in:APROVADO,RECUSADO'], 'remunerada' => ['nullable', 'in:SIM,NAO'], 'nota' => ['nullable', 'string', 'max:500']]);

        return RespostaApi::sucesso($this->ausencias->decidir(AusenciaFaltaColaborador::query()->findOrFail($ausencia), $d['decisao'], $d['remunerada'] ?? null, $d['nota'] ?? null),
            $d['decisao'] === 'APROVADO' ? 'Ausência aprovada.' : 'Ausência recusada.');
    }

    public function cancelar(int $ausencia): JsonResponse
    {
        $this->exigir('rh_assid_registar', 'rh_portal_aprovar');

        return RespostaApi::sucesso($this->ausencias->cancelar(AusenciaFaltaColaborador::query()->findOrFail($ausencia)), 'Ausência cancelada.');
    }

    /** @return array<string, list<mixed>> */
    private function regrasAusencia(): array
    {
        return ['tipo' => ['required', Rule::in(array_keys(ServicoAusencias::CATALOGO))], 'motivo' => ['required', 'string', 'max:1000'],
            'documento_url' => ['nullable', 'string', 'max:1000']];
    }
}
