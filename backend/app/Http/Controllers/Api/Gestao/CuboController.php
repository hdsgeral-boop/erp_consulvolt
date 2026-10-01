<?php

namespace App\Http\Controllers\Api\Gestao;

use App\Http\Controllers\Controller;
use App\Services\Gestao\Paineis\Cubo\ServicoCubo;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/gestao/cubo — Análise Dinâmica (separador "Análise Dinâmica" do Dashboard, ui_cubo.js): conjuntos de dados disponíveis,
 * valores de uma dimensão (filtros) e tabela dinâmica agregada no servidor. Exige o Dashboard e, por conjunto, a consulta de um
 * ecrã do módulo.
 */
final class CuboController extends Controller
{
    private const DASHBOARD = 'dashboard_view';

    public function __construct(private readonly ServicoCubo $cubo) {}

    public function conjuntos(): JsonResponse
    {
        $this->exigir(self::DASHBOARD);

        return RespostaApi::sucesso($this->cubo->conjuntos(), 'Conjuntos de dados disponíveis.');
    }

    public function valores(Request $r): JsonResponse
    {
        $this->exigir(self::DASHBOARD);
        $f = $r->validate([
            'conjunto' => ['required', 'string', 'max:40'], 'dimensao' => ['required', 'string', 'max:60'],
            'data_inicio' => ['required', 'date_format:Y-m-d'], 'data_fim' => ['required', 'date_format:Y-m-d', 'after_or_equal:data_inicio'],
            'pesquisa' => ['nullable', 'string', 'max:100'], 'incluir_apuramento' => ['nullable', 'boolean'],
        ]);

        return RespostaApi::sucesso($this->cubo->valores($f['conjunto'], $f['dimensao'], $f), 'Valores da dimensão.');
    }

    public function consultar(Request $r): JsonResponse
    {
        $this->exigir(self::DASHBOARD);
        $f = $r->validate(self::regras() + ['conjunto' => ['required', 'string', 'max:40']]);

        return RespostaApi::sucesso($this->cubo->consultar($f['conjunto'], $f), 'Análise dinâmica.');
    }

    /** Regras comuns ao cubo e ao BI (a lista branca de dimensões e medidas é validada no serviço). */
    public static function regras(bool $datasObrigatorias = true): array
    {
        $req = $datasObrigatorias ? 'required' : 'nullable';

        return [
            'data_inicio' => [$req, 'date_format:Y-m-d'], 'data_fim' => [$req, 'date_format:Y-m-d', 'after_or_equal:data_inicio'],
            'linhas' => ['nullable', 'array', 'max:'.ServicoCubo::MAX_LINHAS], 'linhas.*' => ['string', 'max:60'],
            'colunas' => ['nullable', 'array', 'max:'.ServicoCubo::MAX_COLUNAS], 'colunas.*' => ['string', 'max:60'],
            'medidas' => ['nullable', 'array', 'max:'.ServicoCubo::MAX_MEDIDAS], 'medidas.*.medida' => ['nullable', 'string', 'max:60'],
            'medidas.*.agregacao' => ['required', 'string', 'max:20'],
            'filtros' => ['nullable', 'array'], 'filtros.*' => ['array'], 'filtros.*.*' => ['nullable', 'string', 'max:300'],
            'exclusoes' => ['nullable', 'array'], 'exclusoes.*' => ['array'], 'exclusoes.*.*' => ['nullable', 'string', 'max:300'],
            'incluir_apuramento' => ['nullable', 'boolean'],
        ];
    }
}
