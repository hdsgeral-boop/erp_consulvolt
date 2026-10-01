<?php

namespace App\Http\Controllers\Api\Contabilidade;

use App\Http\Controllers\Controller;
use App\Services\Contabilidade\ServicoRelatorioContas;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /api/contabilidade/relatorio-contas/{ano} — ecrã "relatorio_contas" (js/relatorio_contas.js:927), ADR-055. */
final class RelatorioContasController extends Controller
{
    public function __construct(private readonly ServicoRelatorioContas $rc) {}

    public function show(int $ano): JsonResponse
    {
        $this->exigir('relatorio_contas_view');

        return RespostaApi::sucesso($this->rc->obter($this->ano($ano)), 'Relatório e Contas obtido com sucesso.');
    }

    public function update(Request $request, int $ano): JsonResponse
    {
        $this->exigir('rc_editar');
        $d = $request->validate(['configuracao' => ['sometimes', 'array'], 'textos' => ['sometimes', 'array'], 'textos.*.html' => ['nullable', 'string', 'max:200000'],
            'textos.*.auto' => ['nullable', 'boolean'], 'notas_incluir' => ['sometimes', 'array'], 'notas_incluir.*' => ['boolean'],
            'configuracao.taxa_imposto' => ['nullable', 'numeric', 'min:0', 'max:100'], 'configuracao.pct_reservas' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'configuracao.pct_transitados' => ['nullable', 'numeric', 'min:0', 'max:100'], 'configuracao.pct_dividendos' => ['nullable', 'numeric', 'min:0', 'max:100']]);
        // validate() aninhado só devolve as subchaves listadas: usam-se os blocos completos do pedido
        $dados = array_intersect_key($request->only(['configuracao', 'textos', 'notas_incluir']), $d);
        $this->rc->gravar($this->ano($ano), $dados);

        return RespostaApi::sucesso($this->rc->obter($ano)['registo'], 'Relatório e Contas gravado.');
    }

    public function concluir(Request $request, int $ano): JsonResponse
    {
        $this->exigir('rc_concluir');
        $d = $request->validate(['forcar' => ['nullable', 'boolean']]);

        return RespostaApi::sucesso($this->rc->concluir($this->ano($ano), (bool) ($d['forcar'] ?? false)), "Relatório e Contas de {$ano} concluído.");
    }

    public function reabrir(int $ano): JsonResponse
    {
        $this->exigir('rc_reabrir');

        return RespostaApi::sucesso($this->rc->reabrir($this->ano($ano)), "Relatório e Contas de {$ano} reaberto.");
    }

    private function ano(int $ano): int
    {
        abort_if($ano < 1990 || $ano > 2100, 404);

        return $ano;
    }
}
