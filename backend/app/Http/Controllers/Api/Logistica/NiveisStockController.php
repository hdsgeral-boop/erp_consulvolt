<?php

namespace App\Http\Controllers\Api\Logistica;

use App\Http\Controllers\Controller;
use App\Services\Logistica\ServicoNiveisStock;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * /api/logistica/niveis-stock — alertas dos níveis de stock (crítico, reposição, excesso) e «Sugestões de reposição»,
 * com a geração do pedido de compra pelo fluxo existente das Compras (numeração PC e deliberação).
 */
final class NiveisStockController extends Controller
{
    public function __construct(private readonly ServicoNiveisStock $niveis) {}

    /** GET — resumo e linhas (opcionalmente só um estado). */
    public function index(Request $r): JsonResponse
    {
        $this->exigir('armazem_stock_view', 'vendas_produtos_view', 'vendas_relatorios_view', 'compras_pedidos_view');
        $f = $r->validate(['estado' => ['nullable', Rule::in([ServicoNiveisStock::CRITICO, ServicoNiveisStock::REPOSICAO, ServicoNiveisStock::EXCESSO])]]);
        $a = $this->niveis->avaliar();

        return RespostaApi::sucesso(['resumo' => $a['resumo'], 'calculado_em' => $a['calculado_em'] ?? null, 'linhas' => $this->niveis->linhas($f['estado'] ?? null)],
            'Níveis de stock avaliados.');
    }

    /** POST /pedido-compra — gera o pedido de compra com as sugestões escolhidas (quantidades editáveis). */
    public function gerarPedidoCompra(Request $r): JsonResponse
    {
        $this->exigir('compras_ped_criar');
        $d = $r->validate([
            'linhas' => ['required', 'array', 'min:1', 'max:500'],
            'linhas.*.produto_id' => ['required', 'integer', 'distinct', Rule::exists('produtos', 'id')->where('empresa_id', app(ContextoEmpresa::class)->obrigatorio())->whereNull('eliminado_em')],
            'linhas.*.quantidade' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'descricao' => ['nullable', 'string', 'max:2000'],
            'data_entrega' => ['nullable', 'date_format:Y-m-d'],
        ], [], ['linhas' => 'sugestões']);
        $p = $this->niveis->gerarPedidoCompra($d['linhas'], $d['descricao'] ?? null, $d['data_entrega'] ?? null);

        return RespostaApi::criado(['id' => $p->id, 'numero_pedido' => $p->numero_pedido, 'estado' => $p->estado],
            "Pedido de compra {$p->numero_pedido} criado e enviado para deliberação.");
    }
}
