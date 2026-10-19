<?php

namespace App\Http\Controllers\Api\Vendas;

use App\Http\Controllers\Controller;
use App\Models\CategoriaProduto;
use App\Models\Venda;
use App\Rules\TaxaIvaLegal;
use App\Services\Compras\ServicoEncomendasClientes;
use App\Services\Logistica\ServicoProdutos;
use App\Services\Vendas\ServicoConfigFaturacaoEletronica;
use App\Services\Vendas\ServicoParidadeVendas;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Endpoints da paridade de Vendas com o sistema anterior (RELATORIO.md secção 4): cabeçalho «Gestão de Vendas»,
 * contadores, alertas de stock, análise do SAF-T, meios de pagamento e guias do formulário, acções por linha que não
 * existiam («Forçar limpeza de estado», «Converter em Pedido de Compra») e a edição em massa de produtos.
 */
final class ParidadeVendasController extends Controller
{
    private const VER_VENDAS = ['vendas_relatorios_view', 'vendas_faturacao_view', 'vendas_clientes_view', 'vendas_produtos_view'];

    public function __construct(private readonly ServicoParidadeVendas $servico) {}

    /** GET /vendas/cabecalho — «● Pendentes: N» e «📈 Mensal: X Kz» do agregador, e o saldo a receber. */
    public function cabecalho(): JsonResponse
    {
        $this->exigir(...self::VER_VENDAS);

        return RespostaApi::sucesso($this->servico->cabecalho(), 'Indicadores de vendas.');
    }

    /** GET /vendas/documentos/contagens — distintivos dos subseparadores da Facturação. */
    public function contagens(): JsonResponse
    {
        $this->exigir('vendas_faturacao_view');

        return RespostaApi::sucesso($this->servico->contagens(), 'Contagens dos documentos.');
    }

    /** GET /vendas/relatorios/alertas-stock — «Alertas de Stock Baixo» do Dashboard de Vendas. */
    public function alertasStock(): JsonResponse
    {
        $this->exigir('vendas_relatorios_view');

        return RespostaApi::sucesso($this->servico->alertasStock(), 'Alertas de stock baixo.');
    }

    /** GET /vendas/produtos/stock — stock actual por produto (coluna «STOCK» e «Stock Atual» da ficha). */
    public function stockProdutos(): JsonResponse
    {
        $this->exigir('vendas_produtos_view', 'vendas_faturacao_view', 'armazem_stock_view');

        return RespostaApi::sucesso((object) $this->servico->stockPorProduto(), 'Stock por produto.');
    }

    /** GET /vendas/saft/analise?inicio=&fim= — «Analisar Registos»: sumário do lote e dados detalhados (sem gerar o XML). */
    public function analiseSaft(Request $request): JsonResponse
    {
        $this->exigir('vendas_relatorios_view', 'vendas_fe_config');
        $d = $request->validate(['inicio' => ['required', 'date_format:Y-m-d'], 'fim' => ['required', 'date_format:Y-m-d', 'after_or_equal:inicio']]);

        return RespostaApi::sucesso($this->servico->analiseSaft($d['inicio'], $d['fim']), 'Análise dos registos do período.');
    }

    /** GET /vendas/faturacao-eletronica/diagnostico — «Dados a rever antes de activar» (só leitura; nada é enviado à AGT). */
    public function diagnosticoAgt(ServicoConfigFaturacaoEletronica $config): JsonResponse
    {
        $this->exigir('vendas_faturacao_view', 'vendas_fe_config');

        return RespostaApi::sucesso($this->servico->diagnosticoAgt($config->obter()['isencao_padrao'] ?? null), 'Dados a rever antes de activar.');
    }

    /** GET /vendas/meios-pagamento — meios de pagamento activos para o quadro «Meios de Pagamento» do formulário. */
    public function meiosPagamento(): JsonResponse
    {
        $this->exigir('vendas_fat_emitir');

        return RespostaApi::sucesso($this->servico->meiosPagamento(), 'Meios de pagamento.');
    }

    /** GET /vendas/guias-saida-pendentes?cliente_id= — guias de saída de venda por facturar (quadro do formulário). */
    public function guiasPendentes(Request $request): JsonResponse
    {
        $this->exigir('vendas_fat_emitir');
        $d = $request->validate(['cliente_id' => ['nullable', 'integer']]);

        return RespostaApi::sucesso($this->servico->guiasPendentes(isset($d['cliente_id']) ? (int) $d['cliente_id'] : null), 'Guias de saída pendentes de facturação.');
    }

    /** POST /vendas/documentos/{id}/recalcular-estado — «Forçar limpeza de estado» (recalcula pendente e estado). */
    public function recalcularEstado(int $venda): JsonResponse
    {
        $this->exigir('vendas_fat_emitir');
        $v = $this->servico->recalcularEstado(Venda::query()->findOrFail($venda));

        return RespostaApi::sucesso(['id' => $v->id, 'estado' => $v->estado, 'valor_pago' => $v->valor_pago, 'valor_pendente' => $v->valor_pendente],
            "Estado de {$v->numero_documento} recalculado: {$v->estado}.");
    }

    /** POST /vendas/documentos/{id}/pedido-compra — «Converter em Pedido de Compra» (encomendas). */
    public function pedidoCompra(int $venda, ServicoEncomendasClientes $encomendas): JsonResponse
    {
        $this->exigir('compras_gerar_pedidos', 'compras_ped_criar');
        $p = $this->servico->pedidoCompra(Venda::query()->findOrFail($venda), $encomendas);

        return RespostaApi::criado(['id' => $p->id, 'numero_pedido' => $p->numero_pedido], "Pedido de compra {$p->numero_pedido} gerado para a encomenda.");
    }

    /**
     * POST /vendas/produtos/editar-em-massa — «Editar em massa (N)» do catálogo de produtos e serviços. As contas já não
     * se gravam no produto (a categoria sobrepõe-se): «contas» preenchidas → 422 CONTAS_NA_CATEGORIA; para as mudar,
     * escolhe-se a categoria (dados.categoria_produto_id) ou editam-se as da categoria (categoria_contas, com
     * vendas_categorias_contas — vale para todos os produtos da categoria).
     */
    public function editarProdutosEmMassa(Request $request, ServicoProdutos $produtos): JsonResponse
    {
        $this->exigir('vendas_produtos_gerir');
        if ($request->filled('categoria_contas')) {
            $this->exigir('vendas_categorias_contas');   // autorização antes da validação (OWASP A01)
        }
        $empresa = app(ContextoEmpresa::class)->obrigatorio();
        $conta = ['nullable', 'string', 'max:20', 'regex:/^(__RETIRAR__|[0-9][0-9.]*)$/'];
        $d = $request->validate([
            'ids' => ['required_without:categoria_contas', 'array', 'max:1000'], 'ids.*' => ['integer', 'distinct'],
            'dados' => ['nullable', 'array'],
            'dados.preco' => ['nullable', 'array'],
            'dados.preco.modo' => ['required_with:dados.preco', Rule::in(['DEFINIR', 'PERCENT', 'VALOR'])],
            'dados.preco.valor' => ['required_with:dados.preco', 'numeric', 'min:-9999999999', 'max:9999999999'],
            'dados.taxa_imposto' => ['nullable', 'numeric', new TaxaIvaLegal],
            'dados.categoria_produto_id' => ['nullable', 'integer', Rule::exists('categorias_produtos', 'id')->where('empresa_id', $empresa)->whereNull('eliminado_em')],
            'dados.movimenta_stock' => ['nullable', 'boolean'], 'dados.e_servico' => ['nullable', 'boolean'], 'dados.bloqueado' => ['nullable', 'boolean'],
            'contas' => ['nullable', 'array'],
            'categoria_contas' => ['nullable', 'array'],
            'categoria_contas.categoria_produto_id' => ['required_with:categoria_contas', 'integer',
                Rule::exists('categorias_produtos', 'id')->where('empresa_id', $empresa)->whereNull('eliminado_em')],
            'categoria_contas.contas' => ['required_with:categoria_contas', 'array'],
            ...array_combine(array_map(fn ($c) => "categoria_contas.contas.{$c}", array_keys(CategoriaProduto::CONTAS)), array_fill(0, count(CategoriaProduto::CONTAS), $conta)),
        ], [], ['ids' => 'produtos', 'categoria_contas.categoria_produto_id' => 'categoria']);
        $dados = array_filter($d['dados'] ?? [], fn ($v) => $v !== null);
        $r = $this->servico->editarProdutosEmMassa($d['ids'] ?? [], $dados, (array) $request->input('contas', []), $produtos, $d['categoria_contas'] ?? null);

        $mensagens = [];
        if ($r['alterados']) {
            $mensagens[] = "{$r['alterados']} produto(s)/serviço(s) actualizado(s).";
        }
        if (isset($r['categoria'])) {
            $mensagens[] = "Contas da categoria «{$r['categoria']['categoria']}» actualizadas: aplicam-se aos {$r['categoria']['produtos']} produto(s) da categoria.";
        }

        return RespostaApi::sucesso($r, implode(' ', $mensagens));
    }
}
