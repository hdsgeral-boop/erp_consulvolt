<?php

namespace App\Http\Controllers\Api\Contabilidade;

use App\Http\Controllers\Controller;
use App\Services\Contabilidade\ServicoRelatorioContas;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** /api/contabilidade/relatorio-contas/{ano} — ecrã "relatorio_contas" (js/relatorio_contas.js:927), ADR-055. */
final class RelatorioContasController extends Controller
{
    public function __construct(private readonly ServicoRelatorioContas $rc) {}

    public function show(int $ano): JsonResponse
    {
        $this->exigir('relatorio_contas_view');

        return RespostaApi::sucesso($this->semDemonstracoesVedadas($this->rc->obter($this->ano($ano))), 'Relatório e Contas obtido com sucesso.');
    }

    public function update(Request $request, int $ano): JsonResponse
    {
        $this->exigir('rc_editar');
        $d = $request->validate(['configuracao' => ['sometimes', 'array'], 'textos' => ['sometimes', 'array'], 'textos.*.html' => ['nullable', 'string', 'max:200000'], 'textos.*.texto' => ['nullable', 'string', 'max:100000'],
            'textos.*.auto' => ['nullable', 'boolean'], 'notas_incluir' => ['sometimes', 'array'], 'notas_incluir.*' => ['boolean'],
            'configuracao.taxa_imposto' => ['nullable', 'numeric', 'min:0', 'max:100'], 'configuracao.pct_reservas' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'configuracao.pct_transitados' => ['nullable', 'numeric', 'min:0', 'max:100'], 'configuracao.pct_dividendos' => ['nullable', 'numeric', 'min:0', 'max:100'],
            // membros do Conselho de Administração (capa do documento impresso), um por linha
            'configuracao.conselho' => ['nullable', 'string', 'max:2000']]);
        // validate() aninhado só devolve as subchaves listadas: usam-se os blocos completos do pedido
        $dados = array_intersect_key($request->only(['configuracao', 'textos', 'notas_incluir']), $d);
        $this->rc->gravar($this->ano($ano), $dados);

        return RespostaApi::sucesso($this->rc->obter($ano)['registo'], 'Relatório e Contas gravado.');
    }

    public function concluir(Request $request, int $ano): JsonResponse
    {
        $this->exigir('rc_concluir');
        $d = $request->validate(['forcar' => ['nullable', 'boolean']]);

        return RespostaApi::sucesso($this->semDemonstracoesVedadas($this->rc->concluir($this->ano($ano), (bool) ($d['forcar'] ?? false))), "Relatório e Contas de {$ano} concluído.");
    }

    public function reabrir(int $ano): JsonResponse
    {
        $this->exigir('rc_reabrir');

        return RespostaApi::sucesso($this->semDemonstracoesVedadas($this->rc->reabrir($this->ano($ano))), "Relatório e Contas de {$ano} reaberto.");
    }

    /**
     * As demonstrações fixadas na conclusão só seguem para quem pode consultar o mapa respectivo (como os mapas ao vivo:
     * contab_mapa_balanco_view / _dr_view / _fluxo_view).
     */
    private function semDemonstracoesVedadas(array $r): array
    {
        foreach (['balanco' => 'contab_mapa_balanco_view', 'dr' => 'contab_mapa_dr_view', 'fluxo' => 'contab_mapa_fluxo_view'] as $k => $permissao) {
            if (isset($r['dados']['demonstracoes'][$k]) && ! Gate::allows($permissao)) {
                unset($r['dados']['demonstracoes'][$k]);
            }
        }

        return $r;
    }

    private function ano(int $ano): int
    {
        abort_if($ano < 1990 || $ano > 2100, 404);

        return $ano;
    }
}
