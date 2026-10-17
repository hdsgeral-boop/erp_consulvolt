<?php

namespace App\Http\Controllers\Api\Logistica;

use App\Http\Controllers\Controller;
use App\Services\Logistica\ServicoGuiasRemessaArmazem;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Armazém › «Emitir Guia de Saída» para clientes (js/ui_warehouse.js:603-1008): encomendas vinculadas a compras
 * (back-to-back), stock do armazém e emissão da guia de remessa das Vendas (ADR-043, ServicoGuiasRemessaArmazem).
 *
 * Permissões: ler o formulário exige «Emitir guias de saída» (armazem_guias_emitir). Emitir a GR exige ESSA permissão E
 * «Emitir orçamentos, encomendas, guias, facturas…» (vendas_fat_emitir): a GR é um documento das Vendas (numeração da
 * série, cliente, entregue da encomenda) — quem só emite guias de consumo no armazém não passa a emitir documentos de
 * venda, e quem só vende não dá saídas do armazém por este ecrã.
 */
final class GuiasRemessaArmazemController extends Controller
{
    public function __construct(private readonly ServicoGuiasRemessaArmazem $servico) {}

    /** GET /logistica/guias-saida/encomendas-clientes — cartões «Encomendas vinculadas a compras» e «Encomendas satisfeitas». */
    public function encomendas(): JsonResponse
    {
        $this->exigir('armazem_guias_emitir');

        return RespostaApi::sucesso($this->servico->encomendasVinculadas(), 'Encomendas de clientes vinculadas a compras.');
    }

    /** GET /logistica/guias-saida/stock-armazem?armazem_id= — «Stock Disp.» de cada produto no armazém de saída. */
    public function stock(Request $r): JsonResponse
    {
        $this->exigir('armazem_guias_emitir', 'armazem_stock_view');
        $d = $r->validate(['armazem_id' => ['required', 'integer', 'min:1']]);

        return RespostaApi::sucesso($this->servico->stockArmazem((int) $d['armazem_id']), 'Stock do armazém.');
    }

    /** POST /logistica/guias-saida/remessa — «Emitir e Baixar Stock» (Venda ou Saída de Encomenda Fechada). */
    public function emitir(Request $r): JsonResponse
    {
        $this->exigir('armazem_guias_emitir');
        $this->exigir('vendas_fat_emitir');
        $empresa = app(ContextoEmpresa::class)->obrigatorio();
        $daEmpresa = fn (string $tabela) => Rule::exists($tabela, 'id')->where('empresa_id', $empresa);
        $d = $r->validate([
            'tipo' => ['required', Rule::in(ServicoGuiasRemessaArmazem::TIPOS)],
            'armazem_id' => ['required', 'integer', $daEmpresa('armazens')->whereNull('eliminado_em')],
            'data' => ['required', 'date_format:Y-m-d'],
            'cliente_id' => ['nullable', 'integer', $daEmpresa('terceiros')->whereNull('eliminado_em')],
            'encomenda_id' => ['required_if:tipo,BACK_TO_BACK', 'nullable', 'integer', $daEmpresa('vendas')],
            'projeto_id' => ['nullable', 'integer', $daEmpresa('projetos')],
            'observacoes' => ['nullable', 'string', 'max:2000'],
            'linhas' => ['required', 'array', 'min:1', 'max:500'],
            'linhas.*.produto_id' => ['required', 'integer', $daEmpresa('produtos')->whereNull('eliminado_em')],
            'linhas.*.quantidade' => ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:999999999'],
            'linhas.*.item_origem_id' => ['nullable', 'integer'],
        ], [], ['armazem_id' => 'armazém de saída', 'cliente_id' => 'cliente', 'encomenda_id' => 'encomenda', 'linhas' => 'itens a sair']);
        $gr = $this->servico->emitir($d);

        return RespostaApi::criado([
            'id' => $gr->id, 'tipo_documento' => $gr->tipo_documento, 'numero_documento' => $gr->numero_documento, 'saida_armazem' => $gr->saida_armazem,
            'estado' => $gr->estado, 'cliente_id' => $gr->cliente_id, 'armazem_id' => $gr->armazem_id,
        ], "Guia de remessa {$gr->numero_documento} emitida e stock baixado.");
    }
}
