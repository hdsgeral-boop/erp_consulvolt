<?php

namespace App\Http\Controllers\Api\Ativos;

use App\Http\Controllers\Controller;
use App\Models\AtivoImobilizado;
use App\Services\Ativos\ServicoAmortizacoes;
use App\Services\Ativos\ServicoRelatoriosAtivos;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /api/ativos — amortizações (cálculo, quotas manuais, integração, reabertura, verificação), mapas e fluxo do imobilizado. */
final class AmortizacoesController extends Controller
{
    private const PERIODO = ['required', 'string', 'regex:/^(0[1-9]|1[0-2])-\d{4}$/'];

    public function __construct(
        private readonly ServicoAmortizacoes $amortizacoes,
        private readonly ServicoRelatoriosAtivos $relatorios,
    ) {}

    public function periodo(Request $r): JsonResponse
    {
        $this->exigir('activos_amortizacoes_view');
        $d = $r->validate(['periodo' => self::PERIODO]);

        return RespostaApi::sucesso($this->amortizacoes->periodo($d['periodo']), "Amortizações de {$d['periodo']}.");
    }

    public function pendentes(): JsonResponse
    {
        $this->exigir('activos_amortizacoes_view');

        return RespostaApi::sucesso($this->amortizacoes->pendentes(), 'Meses por calcular e por integrar.');
    }

    public function previsualizar(Request $r): JsonResponse
    {
        $this->exigir('activos_amortizacoes_view');
        $d = $r->validate(['periodo' => self::PERIODO]);

        return RespostaApi::sucesso($this->amortizacoes->previsualizar($d['periodo']), 'Pré-visualização da integração.');
    }

    public function calcular(Request $r): JsonResponse
    {
        $this->exigir('activos_amort_calcular');
        $d = $r->validate(['periodos' => ['required', 'array', 'min:1', 'max:240'], 'periodos.*' => self::PERIODO]);

        return RespostaApi::sucesso($this->amortizacoes->calcular($d['periodos']), 'Cálculos guardados em rascunho.');
    }

    public function definirQuota(Request $r): JsonResponse
    {
        $this->exigir('activos_amort_calcular', 'activos_mapa_editar');
        $d = $r->validate(['ativo_imobilizado_id' => ['required', 'integer'], 'periodo' => self::PERIODO, 'valor' => ['required', 'numeric', 'min:0']]);
        $res = $this->amortizacoes->definirQuota(AtivoImobilizado::query()->findOrFail($d['ativo_imobilizado_id']), $d['periodo'], (string) $d['valor']);

        return RespostaApi::sucesso($res, $res ? 'Quota manual guardada em rascunho.' : 'Rascunho retirado.');
    }

    public function integrar(Request $r): JsonResponse
    {
        $this->exigir('activos_amort_integrar');
        $d = $r->validate(['periodos' => ['required', 'array', 'min:1', 'max:240'], 'periodos.*' => self::PERIODO]);

        return RespostaApi::sucesso($this->amortizacoes->integrar($d['periodos']), 'Amortizações integradas na contabilidade.');
    }

    public function integrarAtivo(Request $r, int $bem): JsonResponse
    {
        $this->exigir('activos_amort_integrar');
        $d = $r->validate(['ate' => self::PERIODO]);

        return RespostaApi::sucesso($this->amortizacoes->integrarAtivo(AtivoImobilizado::query()->findOrFail($bem), $d['ate']), 'Amortizações do activo integradas.');
    }

    public function reabrir(Request $r): JsonResponse
    {
        $this->exigir('activos_amort_anular');
        $d = $r->validate(['periodos' => ['required', 'array', 'min:1', 'max:240'], 'periodos.*' => self::PERIODO, 'motivo' => ['nullable', 'string', 'max:500']]);

        return RespostaApi::sucesso($this->amortizacoes->reabrir($d['periodos'], $d['motivo'] ?? null), 'Período(s) reaberto(s): lançamentos estornados e cálculos anulados.');
    }

    public function verificar(): JsonResponse
    {
        $this->exigir('activos_amortizacoes_view', 'activos_mapa_view');

        return RespostaApi::sucesso($this->amortizacoes->verificar(), 'Verificação das amortizações.');
    }

    public function porIntegrarNoAno(Request $r): JsonResponse
    {
        $this->exigir('activos_amortizacoes_view', 'activos_mapa_view');
        $d = $r->validate(['ano' => ['required', 'integer', 'between:1900,2100']]);

        return RespostaApi::sucesso($this->amortizacoes->porIntegrarNoAno((int) $d['ano']), "Activos com amortizações de {$d['ano']} por integrar.");
    }

    // ───────────── Mapas ─────────────

    public function mapaAmortizacoes(Request $r): JsonResponse
    {
        $this->exigir('activos_mapa_view');
        $d = $r->validate(['ano' => ['required', 'integer', 'between:1900,2100']]);

        return RespostaApi::sucesso($this->relatorios->mapaAmortizacoes((int) $d['ano']), "Mapa de amortizações de {$d['ano']}.");
    }

    public function mapaFiscal(Request $r): JsonResponse
    {
        $this->exigir('activos_mapa_view');
        $d = $r->validate(['ano' => ['required', 'integer', 'between:1900,2100']]);

        return RespostaApi::sucesso($this->relatorios->mapaFiscal((int) $d['ano']), "Mapa fiscal de {$d['ano']}.");
    }

    public function resumoCategorias(): JsonResponse
    {
        $this->exigir('activos_view', 'activos_mapa_view');

        return RespostaApi::sucesso($this->relatorios->resumoPorCategoria(), 'Imobilizado por categoria.');
    }

    public function fluxo(): JsonResponse
    {
        $this->exigir('fluxo_processos_view', 'activos_view', 'activos_amortizacoes_view');

        return RespostaApi::sucesso($this->relatorios->fluxo(), 'Fluxo do imobilizado.');
    }
}
