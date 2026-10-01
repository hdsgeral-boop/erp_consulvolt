<?php

namespace App\Http\Controllers\Api\Orcamento;

use App\Http\Controllers\Controller;
use App\Models\CenarioOrcamental;
use App\Models\OrcamentoAnual;
use App\Models\PrevisaoOrcamental;
use App\Services\Orcamento\FormatoOrcamento;
use App\Services\Orcamento\ServicoPlaneamentoOrcamental;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** /api/orcamento/previsoes, /cenarios e /orcamentos/{id}/desvios — planeamento orçamental. */
final class PlaneamentoOrcamentalController extends Controller
{
    public function __construct(private readonly ServicoPlaneamentoOrcamental $plano) {}

    // ───────────── Previsões ─────────────

    public function previsoes(): JsonResponse
    {
        $this->exigir('orc_previsoes_view', 'orc_previsoes_edit');

        return $this->ok(PrevisaoOrcamental::query()->orderBy('tipo')->orderByDesc('revisao')->get(), 'Previsões orçamentais.');
    }

    public function previsao(int $previsao): JsonResponse
    {
        $this->exigir('orc_previsoes_view', 'orc_previsoes_edit');

        return $this->ok($this->plano->resumoPrevisao(PrevisaoOrcamental::query()->findOrFail($previsao)), 'Previsão.');
    }

    public function criarPrevisao(Request $r): JsonResponse
    {
        $this->exigir('orc_previsoes_edit');
        $d = $r->validate(['tipo' => ['required', 'in:EXPLORACAO,TESOURARIA'], 'mes_referencia' => ['required', 'date_format:Y-m'],
            'metodo' => ['nullable', Rule::in(ServicoPlaneamentoOrcamental::METODOS)], 'crescimento_pct' => ['nullable', 'numeric', 'between:-100,1000'],
            'nome' => ['nullable', 'string', 'max:255'], 'unidade_negocio_id' => ['nullable', 'integer'], 'centro_custo_id' => ['nullable', 'integer'], 'projeto_id' => ['nullable', 'integer']]);

        return $this->novo($this->plano->criarPrevisao($d), 'Previsão criada.');
    }

    public function novaRevisao(Request $r, int $previsao): JsonResponse
    {
        $this->exigir('orc_previsoes_edit');
        $d = $r->validate(['mes_referencia' => ['nullable', 'date_format:Y-m']]);

        return $this->novo($this->plano->novaRevisao(PrevisaoOrcamental::query()->findOrFail($previsao), $d['mes_referencia'] ?? null), 'Nova revisão criada.');
    }

    public function gravarPrevisao(Request $r, int $previsao): JsonResponse
    {
        $this->exigir('orc_previsoes_edit');
        $d = $r->validate(['linhas' => ['required', 'array'], 'linhas.*.rubrica_orcamental_id' => ['required', 'integer'], 'linhas.*.valores' => ['required', 'array'],
            'notas' => ['nullable', 'string', 'max:5000']]);

        return $this->ok($this->plano->gravarPrevisao(PrevisaoOrcamental::query()->findOrFail($previsao), $d['linhas'], $d['notas'] ?? null), 'Previsão gravada.');
    }

    public function publicarPrevisao(int $previsao): JsonResponse
    {
        $this->exigir('orc_previsoes_edit');

        return $this->ok($this->plano->publicarPrevisao(PrevisaoOrcamental::query()->findOrFail($previsao)), 'Previsão publicada.');
    }

    public function eliminarPrevisao(int $previsao): JsonResponse
    {
        $this->exigir('orc_previsoes_edit');
        $this->plano->eliminarPrevisao(PrevisaoOrcamental::query()->findOrFail($previsao));

        return $this->ok(null, 'Previsão eliminada.');
    }

    // ───────────── Cenários ─────────────

    public function cenarios(int $orcamento): JsonResponse
    {
        $this->exigir('orc_cenarios_view', 'orc_cenarios_edit');

        return $this->ok(CenarioOrcamental::query()->where('orcamento_anual_id', $orcamento)->orderBy('id')->get(), 'Cenários do orçamento.');
    }

    public function cenario(int $cenario): JsonResponse
    {
        $this->exigir('orc_cenarios_view', 'orc_cenarios_edit');

        return $this->ok($this->plano->calcularCenario(CenarioOrcamental::query()->findOrFail($cenario)), 'Cenário.');
    }

    public function gravarCenario(Request $r, ?int $cenario = null): JsonResponse
    {
        $this->exigir('orc_cenarios_edit');
        $d = $r->validate(['orcamento_anual_id' => [$cenario ? 'nullable' : 'required', 'integer'], 'nome' => ['required', 'string', 'max:255'],
            'tipo' => ['nullable', 'in:OTIMISTA,REALISTA,PESSIMISTA,PERSONALIZADO'], 'variaveis' => ['nullable', 'array'], 'variaveis.*' => ['numeric'],
            'ajustes' => ['nullable', 'array'], 'ajustes.*' => ['numeric'], 'notas' => ['nullable', 'string', 'max:5000']]);
        $c = $cenario ? CenarioOrcamental::query()->findOrFail($cenario) : null;
        $res = $this->plano->gravarCenario($d, $c);

        return $c ? $this->ok($res, 'Cenário actualizado.') : $this->novo($res, 'Cenário criado.');
    }

    public function cenariosPadrao(int $orcamento): JsonResponse
    {
        $this->exigir('orc_cenarios_edit');

        return $this->ok($this->plano->criarPadrao(OrcamentoAnual::query()->findOrFail($orcamento)), 'Cenários padrão criados.');
    }

    public function eliminarCenario(int $cenario): JsonResponse
    {
        $this->exigir('orc_cenarios_edit');
        CenarioOrcamental::query()->findOrFail($cenario)->delete();

        return $this->ok(null, 'Cenário eliminado.');
    }

    public function orcamentoDeCenario(int $cenario): JsonResponse
    {
        $this->exigir('orc_cenarios_edit');
        $this->exigir('orc_editar');

        return $this->novo($this->plano->orcamentoDeCenario(CenarioOrcamental::query()->findOrFail($cenario)), 'Nova versão do orçamento gerada do cenário.');
    }

    // ───────────── Desvios ─────────────

    public function desvios(Request $r, int $orcamento, int $rubrica): JsonResponse
    {
        $this->exigir('orc_controlo_view');
        $d = $r->validate(['de' => ['nullable', 'integer', 'between:1,12'], 'ate' => ['nullable', 'integer', 'between:1,12']]);

        return $this->ok($this->plano->analisarDesvio(OrcamentoAnual::query()->findOrFail($orcamento), $rubrica, (int) ($d['de'] ?? 1), (int) ($d['ate'] ?? now()->month)),
            'Análise do desvio.');
    }

    /** Resposta com os valores monetários em texto decimal de 2 casas (ADR-064). */
    private function ok(mixed $dados, string $mensagem, array $extra = []): JsonResponse
    {
        return RespostaApi::sucesso(FormatoOrcamento::normalizar($dados, $extra), $mensagem);
    }

    private function novo(mixed $dados, string $mensagem): JsonResponse
    {
        return RespostaApi::criado(FormatoOrcamento::normalizar($dados), $mensagem);
    }
}
