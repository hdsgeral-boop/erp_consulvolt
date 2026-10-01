<?php

namespace App\Http\Controllers\Api\Contabilidade;

use App\Exceptions\ErroNegocio;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contabilidade\RelatorioPeriodoRequest;
use App\Services\Contabilidade\ServicoReconciliacaoIvaAgt;
use App\Services\Contabilidade\ServicoRelatoriosContabeis;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /api/contabilidade/relatorios/* — mapas contabilísticos (ecrã "relatorios_contabeis" e sub-ecrãs). */
final class RelatorioContabilController extends Controller
{
    use ValidaFiltrosMapas;

    public function __construct(private readonly ServicoRelatoriosContabeis $relatorios) {}

    public function balancete(RelatorioPeriodoRequest $request): JsonResponse
    {
        $this->exigir('contab_mapa_balancete_view');
        // opções do legado (ADR-055): validadas aqui para não alterar o pedido partilhado com o razão
        $f = $request->validated() + $this->filtros($request, ['por_terceiro' => ['nullable', 'boolean'], 'totalizadoras' => ['nullable', 'boolean'],
            'sem_saldo_inicial' => ['nullable', 'boolean'], 'so_movimento' => ['nullable', 'boolean'], 'sem_saldo_zero' => ['nullable', 'boolean']]);

        return RespostaApi::sucesso($this->relatorios->balancete($f), 'Balancete calculado com sucesso.');
    }

    public function razao(RelatorioPeriodoRequest $request): JsonResponse
    {
        $this->exigir('contab_mapa_balancete_view', 'contab_mapa_extrato_view');

        return RespostaApi::sucesso($this->relatorios->razao($request->validated()), 'Extracto da conta calculado com sucesso.');
    }

    public function desequilibrios(RelatorioPeriodoRequest $request): JsonResponse
    {
        $this->exigir('relatorios_contabeis_view');
        $f = $request->validated();

        return RespostaApi::sucesso($this->relatorios->desequilibrios($f['data_inicio'] ?? null, $f['data_fim'] ?? null),
            'Relatório de desequilíbrios calculado com sucesso.');
    }

    /** Extracto de conta corrente (várias contas e/ou terceiro). */
    public function extrato(Request $request): JsonResponse
    {
        $this->exigir('contab_mapa_extrato_view');
        $f = $this->filtros($request, ['data_inicio' => ['nullable', 'required_without:data_fim', 'date_format:Y-m-d'],
            'data_fim' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:data_inicio'], 'tipo' => ['nullable', 'in:todos,aberto,compensado']]);
        if (empty($f['filtro_contas']) && empty($f['terceiro_id'])) {
            throw new ErroNegocio('Indique pelo menos uma conta ou um terceiro.', 'EXTRATO_SEM_CRITERIO', 422);
        }

        return RespostaApi::sucesso($this->relatorios->extrato($f), 'Extracto de conta corrente calculado com sucesso.');
    }

    /** Balancete de evolução mensal. */
    public function evolucao(Request $request): JsonResponse
    {
        $this->exigir('contab_mapa_evolucao_view');
        $f = $this->filtros($request, ['ano' => ['required', 'integer', 'min:1990', 'max:2100'], 'nivel' => ['nullable', 'integer', 'min:1', 'max:20'],
            'por_terceiro' => ['nullable', 'boolean']]);

        return RespostaApi::sucesso($this->relatorios->evolucao($f), 'Evolução mensal calculada com sucesso.');
    }

    /** Mapa de reconciliação de IVA (contas 345). */
    public function mapaIva(Request $request): JsonResponse
    {
        $this->exigir('contab_mapa_balancete_view');
        $f = $this->filtros($request, ['data_fim' => ['required', 'date_format:Y-m-d'], 'data_inicio' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:data_fim']]);

        return RespostaApi::sucesso($this->relatorios->mapaIva($f), 'Mapa de IVA calculado com sucesso.');
    }

    /** Reconciliação do IVA dedutível com o ficheiro da AGT. */
    public function reconciliacaoAgt(Request $request, ServicoReconciliacaoIvaAgt $servico): JsonResponse
    {
        $this->exigir('contab_agt');
        $d = $request->validate(['mes' => ['required', 'date_format:Y-m'], 'ficheiro' => ['required', 'file', 'max:10240', 'mimes:xlsx,xls,csv,txt']],
            [], ['mes' => 'mês', 'ficheiro' => 'ficheiro da AGT']);

        return RespostaApi::sucesso($servico->reconciliar($d['mes'], $request->file('ficheiro')->getRealPath()), 'Reconciliação com a AGT calculada com sucesso.');
    }
}
