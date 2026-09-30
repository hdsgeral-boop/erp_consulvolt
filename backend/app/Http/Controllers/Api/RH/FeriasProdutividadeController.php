<?php

namespace App\Http\Controllers\Api\RH;

use App\Http\Controllers\Controller;
use App\Models\ItemProdutividadeRH;
use App\Models\PeriodoProcessamentoSalarial;
use App\Models\PeriodoProdutividadeRH;
use App\Models\PlanoFeriasColaborador;
use App\Models\RegistoProdutividadeRH;
use App\Services\RH\ServicoFerias;
use App\Services\RH\ServicoProdutividade;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** /api/rh/ferias e /api/rh/produtividade — ecrãs «Férias» e «Subsídio de produtividade». */
final class FeriasProdutividadeController extends Controller
{
    public function __construct(
        private readonly ServicoFerias $ferias,
        private readonly ServicoProdutividade $produtividade,
    ) {}

    // ───────────── Férias ─────────────

    public function ferias(Request $r): JsonResponse
    {
        $this->exigir('rh_ferias_view', 'rh_ferias_edit');
        $f = $r->validate(['ano' => ['required', 'integer', 'between:2000,2100'], 'colaborador_id' => ['nullable', 'integer']]);

        return RespostaApi::sucesso(['resumo' => $this->ferias->resumo((int) $f['ano']),
            'periodos' => PlanoFeriasColaborador::query()->where('ano', $f['ano'])->when($f['colaborador_id'] ?? null, fn ($q, $c) => $q->where('colaborador_id', $c))
                ->orderBy('data_inicio')->get()], 'Plano de férias.');
    }

    public function gravarFerias(Request $r, ?int $periodo = null): JsonResponse
    {
        $this->exigir('rh_ferias_edit');
        $d = $r->validate(['colaborador_id' => ['required', 'integer'], 'data_inicio' => ['required', 'date_format:Y-m-d'], 'data_fim' => ['required', 'date_format:Y-m-d'],
            'estado' => ['nullable', Rule::in(ServicoFerias::ESTADOS_RH)], 'direito' => ['nullable', 'integer', 'between:0,60'], 'observacoes' => ['nullable', 'string', 'max:1000'],
            'confirmar_excesso' => ['nullable', 'boolean']]);
        $p = $periodo ? PlanoFeriasColaborador::query()->findOrFail($periodo) : null;
        $res = $this->ferias->gravar($d, $p);

        return $p ? RespostaApi::sucesso($res, 'Período de férias actualizado.') : RespostaApi::criado($res, 'Período de férias marcado.');
    }

    public function estadoFerias(Request $r, int $periodo): JsonResponse
    {
        $this->exigir('rh_ferias_edit');
        $d = $r->validate(['estado' => ['required', Rule::in(ServicoFerias::ESTADOS_RH)]]);

        return RespostaApi::sucesso($this->ferias->alterarEstado(PlanoFeriasColaborador::query()->findOrFail($periodo), $d['estado']), 'Estado das férias alterado.');
    }

    public function eliminarFerias(int $periodo): JsonResponse
    {
        $this->exigir('rh_ferias_edit');
        $this->ferias->eliminar(PlanoFeriasColaborador::query()->findOrFail($periodo));

        return RespostaApi::sucesso(null, 'Período de férias eliminado.');
    }

    // ───────────── Produtividade: itens ─────────────

    public function itens(): JsonResponse
    {
        $this->exigir('rh_produtividade_view', 'contratos_view');

        return RespostaApi::sucesso(ItemProdutividadeRH::query()->orderBy('codigo')->get(), 'Itens de produtividade.');
    }

    public function gravarItem(Request $r, ?int $item = null): JsonResponse
    {
        $this->exigir('rh_prod_config');
        $d = $r->validate(['codigo' => ['required', 'string', 'max:20'], 'descricao' => ['required', 'string', 'max:500'], 'metrica' => ['nullable', Rule::in(ServicoProdutividade::METRICAS)],
            'unidade' => ['nullable', 'string', 'max:10'], 'preco_unitario' => ['required', 'numeric', 'gt:0', 'max:99999999999'], 'infotipo_salarial_id' => ['required', 'integer'],
            'minimo' => ['nullable', 'numeric', 'min:0'], 'maximo' => ['nullable', 'numeric', 'gt:0'], 'ativo' => ['nullable', 'boolean']]);
        $i = $item ? ItemProdutividadeRH::query()->findOrFail($item) : null;
        $res = $this->produtividade->gravarItem($d, $i);

        return $i ? RespostaApi::sucesso($res, 'Item actualizado.') : RespostaApi::criado($res, 'Item criado.');
    }

    public function eliminarItem(int $item): JsonResponse
    {
        $this->exigir('rh_prod_config');
        $this->produtividade->eliminarItem(ItemProdutividadeRH::query()->findOrFail($item));

        return RespostaApi::sucesso(null, 'Item eliminado.');
    }

    // ───────────── Produtividade: períodos e registos ─────────────

    public function periodos(): JsonResponse
    {
        $this->exigir('rh_produtividade_view');

        return RespostaApi::sucesso(PeriodoProdutividadeRH::query()->orderByDesc('mes')->get(), 'Períodos de produtividade.');
    }

    public function gravarPeriodo(Request $r, ?int $periodo = null): JsonResponse
    {
        $this->exigir('rh_prod_periodo');
        $d = $r->validate(['mes' => ['required', 'string'], 'data_inicio' => ['required', 'date_format:Y-m-d'], 'data_fim' => ['required', 'date_format:Y-m-d'],
            'observacoes' => ['nullable', 'string', 'max:1000']]);
        $p = $periodo ? PeriodoProdutividadeRH::query()->findOrFail($periodo) : null;
        $res = $this->produtividade->gravarPeriodo($d, $p);

        return $p ? RespostaApi::sucesso($res, 'Período actualizado.') : RespostaApi::criado($res, 'Período de produtividade aberto.');
    }

    public function periodo(int $periodo): JsonResponse
    {
        $this->exigir('rh_produtividade_view');
        $p = PeriodoProdutividadeRH::query()->findOrFail($periodo);

        return RespostaApi::sucesso($p->toArray() + ['elegiveis' => array_values($this->produtividade->elegiveis($p)),
            'registos' => RegistoProdutividadeRH::query()->where('periodo_produtividade_id', $p->id)->orderBy('colaborador_id')->orderBy('data')->get()], 'Período de produtividade.');
    }

    public function fecharPeriodo(int $periodo): JsonResponse
    {
        $this->exigir('rh_prod_periodo');

        return RespostaApi::sucesso($this->produtividade->fecharPeriodo(PeriodoProdutividadeRH::query()->findOrFail($periodo)), 'Período de produtividade fechado.');
    }

    public function reabrirPeriodo(Request $r, int $periodo): JsonResponse
    {
        $this->exigir('rh_prod_periodo');
        $d = $r->validate(['motivo' => ['required', 'string', 'min:5', 'max:500']]);

        return RespostaApi::sucesso($this->produtividade->reabrirPeriodo(PeriodoProdutividadeRH::query()->findOrFail($periodo), $d['motivo']), 'Período de produtividade reaberto.');
    }

    public function gravarRegisto(Request $r, int $periodo, ?int $registo = null): JsonResponse
    {
        $this->exigir('rh_prod_registar');
        $d = $r->validate(['colaborador_id' => ['required', 'integer'], 'item_produtividade_id' => ['required', 'integer'], 'quantidade' => ['required', 'numeric', 'min:0'],
            'data' => ['nullable', 'date_format:Y-m-d'], 'observacoes' => ['nullable', 'string', 'max:1000']]);
        $reg = $registo ? RegistoProdutividadeRH::query()->where('periodo_produtividade_id', $periodo)->findOrFail($registo) : null;
        $res = $this->produtividade->gravarRegisto(PeriodoProdutividadeRH::query()->findOrFail($periodo), $d, $reg);

        return $reg ? RespostaApi::sucesso($res, 'Registo actualizado.') : RespostaApi::criado($res, 'Registo gravado.');
    }

    public function eliminarRegisto(int $periodo, int $registo): JsonResponse
    {
        $this->exigir('rh_prod_registar');
        $this->produtividade->eliminarRegisto(RegistoProdutividadeRH::query()->where('periodo_produtividade_id', $periodo)->findOrFail($registo));

        return RespostaApi::sucesso(null, 'Registo eliminado.');
    }

    /** POST /api/rh/salarios/periodos/{id}/importar-produtividade — ecrã Calcular («Importar Produtividade»). */
    public function lancar(Request $r, int $id): JsonResponse
    {
        $this->exigir('calcular_folha');
        $d = $r->validate(['substituir' => ['nullable', 'boolean']]);
        $res = $this->produtividade->lancarNoPeriodo(PeriodoProcessamentoSalarial::query()->findOrFail($id), (bool) ($d['substituir'] ?? true));

        return RespostaApi::sucesso($res, "Produtividade lançada: {$res['lancados']} lançamento(s) ({$res['total']} Kz); {$res['ignorados']} ignorado(s); {$res['removidos']} retirado(s).");
    }
}
