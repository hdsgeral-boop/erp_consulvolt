<?php

namespace App\Http\Controllers\Api\Contabilidade;

use App\Http\Controllers\Controller;
use App\Services\Contabilidade\ServicoCompensacoes;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /api/contabilidade/compensacoes — compensação de movimentos no extracto de conta corrente (ADR-055). */
final class CompensacaoController extends Controller
{
    public function __construct(private readonly ServicoCompensacoes $compensacoes) {}

    public function show(string $codigo): JsonResponse
    {
        $this->exigir('contab_mapa_extrato_view', 'contab_compensar', 'contab_reconc_rev');

        return RespostaApi::sucesso($this->compensacoes->mostrar($codigo), 'Compensação obtida com sucesso.');
    }

    public function store(Request $request): JsonResponse
    {
        $this->exigir('contab_compensar');
        $d = $request->validate(['linhas' => ['required', 'array', 'min:2', 'max:2000'], 'linhas.*' => ['integer']], [], ['linhas' => 'movimentos']);

        return RespostaApi::criado($this->compensacoes->compensar($d['linhas']), 'Compensação efectuada com sucesso.');
    }

    public function regularizar(Request $request): JsonResponse
    {
        $this->exigir('contab_compensar');
        $d = $request->validate(['linhas' => ['required', 'array', 'min:1', 'max:2000'], 'linhas.*' => ['integer'],
            'codigo_conta' => ['required', 'string', 'max:20'], 'data' => ['required', 'date_format:Y-m-d'], 'diario_id' => ['nullable', 'integer']],
            [], ['linhas' => 'movimentos', 'codigo_conta' => 'conta de contrapartida', 'data' => 'data do lançamento']);

        return RespostaApi::criado($this->compensacoes->regularizar($d['linhas'], $d), 'Regularização lançada e compensação efectuada com sucesso.');
    }

    public function destroy(string $codigo): JsonResponse
    {
        $this->exigir('contab_reconc_rev');

        return RespostaApi::sucesso($this->compensacoes->anular($codigo), 'Compensação revertida com sucesso.');
    }
}
