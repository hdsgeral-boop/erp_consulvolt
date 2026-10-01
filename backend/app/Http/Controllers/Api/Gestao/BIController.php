<?php

namespace App\Http\Controllers\Api\Gestao;

use App\Http\Controllers\Controller;
use App\Services\Gestao\Paineis\Cubo\ServicoBI;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** /api/gestao/bi — BI contabilístico (vista `accounting_bi`, ui_bi.js): análise dinâmica dos lançamentos. */
final class BIController extends Controller
{
    private const VER = 'accounting_bi_view';

    public function __construct(private readonly ServicoBI $bi) {}

    public function metadados(): JsonResponse
    {
        $this->exigir(self::VER);

        return RespostaApi::sucesso($this->bi->metadados(), 'BI contabilístico.');
    }

    public function consultar(Request $r): JsonResponse
    {
        $this->exigir(self::VER);
        $f = $r->validate(CuboController::regras(false) + ['periodo' => ['nullable', Rule::in(ServicoBI::PERIODOS)]]);

        return RespostaApi::sucesso($this->bi->consultar($f), 'Análise dinâmica de lançamentos.');
    }
}
