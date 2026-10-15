<?php

namespace App\Http\Controllers\Api\RH;

use App\Http\Controllers\Controller;
use App\Models\PeriodoProdutividadeRH;
use App\Services\RH\ServicoProdutividadeProjetos;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/rh/produtividade/projetos — RH › Produtividade › «Execução de projectos» e assistente a partir do projecto
 * (Versão Actual, produtividade_projectos.js:251-386). A recolha e a análise não gravam nada; o envio exige
 * `rh_prod_registar` (verificado aqui, onde o registo é criado — ProdProjectos.enviar, :229-233).
 */
final class ProdutividadeProjetosController extends Controller
{
    private const LER = ['rh_prod_registar', 'rh_produtividade_view'];

    public function __construct(private readonly ServicoProdutividadeProjetos $ponte) {}

    /** Recolha + grupos a mapear (com o item sugerido) + períodos abertos. */
    public function recolha(Request $r): JsonResponse
    {
        $this->exigir(...self::LER);
        $d = $this->filtros($r);
        $colheita = $this->ponte->recolher($d['de'], $d['ate'], $d['projetos'] ?? null);

        return RespostaApi::sucesso($colheita + ['grupos' => $this->ponte->grupos($colheita),
            'periodos' => PeriodoProdutividadeRH::query()->where('estado', 'ABERTO')->orderByDesc('mes')->get(['id', 'mes', 'estado', 'data_inicio', 'data_fim'])],
            'Execução dos projectos no intervalo.');
    }

    public function analisar(Request $r): JsonResponse
    {
        $this->exigir(...self::LER);
        $d = $this->filtros($r, true);

        return RespostaApi::sucesso($this->ponte->analisar(PeriodoProdutividadeRH::query()->findOrFail($d['periodo_id']), $d['de'], $d['ate'], $d['mapa'] ?? [], $d['projetos'] ?? null),
            'Análise contra o período e os contratos (nada foi gravado).');
    }

    public function enviar(Request $r): JsonResponse
    {
        $this->exigir('rh_prod_registar');
        $d = $this->filtros($r, true);
        $res = $this->ponte->enviar(PeriodoProdutividadeRH::query()->findOrFail($d['periodo_id']), $d['de'], $d['ate'], $d['mapa'] ?? [], $d['projetos'] ?? null);

        return RespostaApi::sucesso($res, "{$res['criados']} registo(s) novo(s) e {$res['actualizados']} actualizado(s).");
    }

    private function filtros(Request $r, bool $comPeriodo = false): array
    {
        return $r->validate(array_filter([
            'de' => ['required', 'date_format:Y-m-d'], 'ate' => ['required', 'date_format:Y-m-d', 'after_or_equal:de'],
            'projetos' => ['nullable', 'array', 'max:500'], 'projetos.*' => ['integer'],
            'periodo_id' => $comPeriodo ? ['required', 'integer'] : null,
            'mapa' => $comPeriodo ? ['nullable', 'array', 'max:200'] : null,
            'mapa.*' => $comPeriodo ? ['nullable', 'integer'] : null,
        ]));
    }
}
