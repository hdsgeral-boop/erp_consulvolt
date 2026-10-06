<?php

namespace App\Http\Controllers\Api\Simulacoes;

use App\Http\Controllers\Controller;
use App\Models\CentroCusto;
use App\Models\FaturaCompra;
use App\Models\PlanoConta;
use App\Models\Projeto;
use App\Models\Terceiro;
use App\Models\UnidadeNegocio;
use App\Models\Venda;
use App\Services\Compras\ServicoContabilizacaoCompras;
use App\Services\Vendas\ServicoContabilizacaoVendas;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;

/**
 * Pré-visualização do lançamento antes de contabilizar (legado: `showPostingPreview` em js/ui_sales.js e
 * `showAccountingPreviewTooltip` — «Simulação contabilística» — em js/ui_compras_v2.js). Usa EXACTAMENTE a mesma
 * montagem de linhas dos serviços de contabilização (mesmas validações e contas), sem gravar nada: nem o lançamento,
 * nem o diário, nem o estado do documento. O ecrã só mostra o resultado.
 */
final class PreVisualizacaoContabilizacaoController extends Controller
{
    /** GET /api/vendas/documentos/{venda}/contabilizacao/pre-visualizacao */
    public function venda(int $venda, ServicoContabilizacaoVendas $servico): JsonResponse
    {
        $this->exigir('vendas_fat_contabilizar');

        return RespostaApi::sucesso($this->enriquecer($servico->previsualizar(Venda::query()->findOrFail($venda))));
    }

    /** GET /api/compras/faturas/{id}/contabilizacao/pre-visualizacao */
    public function faturaCompra(int $id, ServicoContabilizacaoCompras $servico): JsonResponse
    {
        $this->exigir('compras_fact_contabilizar');

        return RespostaApi::sucesso($this->enriquecer($servico->previsualizar(FaturaCompra::query()->findOrFail($id))));
    }

    /**
     * Acrescenta às linhas os nomes (conta, terceiro, UN, CC, projecto) e os totais de débito/crédito.
     *
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function enriquecer(array $p): array
    {
        $linhas = collect($p['linhas'] ?? []);
        $contas = PlanoConta::query()->whereIn('codigo', $linhas->pluck('codigo_conta')->filter()->unique()->values())->pluck('descricao', 'codigo');
        $terceiros = Terceiro::query()->withTrashed()->whereIn('id', $linhas->pluck('terceiro_id')->filter()->unique()->values())->pluck('nome', 'id');
        $uns = UnidadeNegocio::query()->whereIn('id', $linhas->pluck('unidade_negocio_id')->filter()->unique()->values())->pluck('codigo', 'id');
        $ccs = CentroCusto::query()->whereIn('id', $linhas->pluck('centro_custo_id')->filter()->unique()->values())->pluck('codigo', 'id');
        $projetos = Projeto::query()->whereIn('id', $linhas->pluck('projeto_id')->filter()->unique()->values())->pluck('codigo', 'id');

        $debito = '0.00';
        $credito = '0.00';
        $saida = $linhas->map(function (array $l) use ($contas, $terceiros, $uns, $ccs, $projetos, &$debito, &$credito) {
            $valor = number_format((float) $l['valor'], 2, '.', '');
            $l['tipo_dc'] === 'D' ? $debito = bcadd($debito, $valor, 2) : $credito = bcadd($credito, $valor, 2);

            return [
                'codigo_conta' => (string) $l['codigo_conta'],
                'nome_conta' => $contas[$l['codigo_conta']] ?? null,
                'tipo_dc' => $l['tipo_dc'],
                'valor' => $valor,
                'terceiro' => isset($l['terceiro_id']) ? ($terceiros[$l['terceiro_id']] ?? null) : null,
                'unidade_negocio' => isset($l['unidade_negocio_id']) ? ($uns[$l['unidade_negocio_id']] ?? null) : null,
                'centro_custo' => isset($l['centro_custo_id']) ? ($ccs[$l['centro_custo_id']] ?? null) : null,
                'projeto' => isset($l['projeto_id']) ? ($projetos[$l['projeto_id']] ?? null) : null,
                'codigo_moeda' => $l['codigo_moeda'] ?? null,
                'valor_moeda' => $l['valor_moeda'] ?? null,
            ];
        })->values()->all();

        return [
            'diario' => $p['diario'] ?? null,
            'data_documento' => $p['data_documento'] ?? null,
            'numero_documento' => $p['numero_documento'] ?? null,
            'referencia' => $p['referencia'] ?? null,
            'descricao' => $p['descricao'] ?? null,
            'linhas' => $saida,
            'total_debito' => $debito,
            'total_credito' => $credito,
            'equilibrado' => bccomp($debito, $credito, 2) === 0,
        ];
    }
}
