<?php

namespace App\Http\Controllers\Api\Gestao;

use App\Http\Controllers\Controller;
use App\Services\Gestao\Relatorios\PeriodosGestao;
use App\Services\Gestao\Relatorios\ServicoRelatoriosGestao;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * /api/gestao/relatorios — relatórios de gestão por módulo com comparação de períodos (relatorios_gestao.js).
 * Como no legado, o ecrã mostra os indicadores de todos os módulos a quem tem a consulta do ecrã (catálogo: «Mostra
 * indicadores de todos os módulos»), sem exigir a consulta de cada módulo.
 *
 * Períodos (todas as rotas excepto o catálogo): preset_a (mes, mes_anterior, trimestre, trimestre_anterior, semestre, ytd,
 * ano, ano_anterior) ou a_inicio + a_fim; comparacao (homologo, anterior, livre, nenhum) com b_inicio + b_fim em «livre»;
 * referencia = data de referência dos presets (hoje por omissão).
 */
final class RelatoriosGestaoController extends Controller
{
    private const VER = ['relatorios_gestao_view'];

    public function __construct(private readonly ServicoRelatoriosGestao $relatorios) {}

    public function catalogo(): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso($this->relatorios->catalogo(), 'Relatórios de gestão.');
    }

    public function periodos(Request $r): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso($this->periodosDe($r), 'Períodos do relatório.');
    }

    public function resumo(Request $r): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso($this->relatorios->resumo($this->periodosDe($r)), 'Resumo executivo.');
    }

    public function todos(Request $r): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso($this->relatorios->todos($this->periodosDe($r)), 'Relatório de gestão completo.');
    }

    public function modulo(Request $r, string $modulo): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso($this->relatorios->modulo($modulo, $this->periodosDe($r)), 'Relatório de gestão do módulo.');
    }

    private function periodosDe(Request $r): array
    {
        $f = $r->validate([
            'preset_a' => ['nullable', Rule::in(array_diff(array_keys(PeriodosGestao::PRESETS_A), ['livre']))],
            'a_inicio' => ['nullable', 'date_format:Y-m-d', 'required_with:a_fim'], 'a_fim' => ['nullable', 'date_format:Y-m-d', 'required_with:a_inicio', 'after_or_equal:a_inicio'],
            'comparacao' => ['nullable', Rule::in(array_keys(PeriodosGestao::COMPARACOES))],
            'b_inicio' => ['nullable', 'date_format:Y-m-d', 'required_if:comparacao,livre'], 'b_fim' => ['nullable', 'date_format:Y-m-d', 'required_if:comparacao,livre', 'after_or_equal:b_inicio'],
            'referencia' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return PeriodosGestao::resolver($f);
    }
}
