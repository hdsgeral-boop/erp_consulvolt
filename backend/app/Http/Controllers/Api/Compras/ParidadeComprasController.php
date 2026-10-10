<?php

namespace App\Http\Controllers\Api\Compras;

use App\Http\Controllers\Controller;
use App\Models\ContratoFornecedor;
use App\Models\EncomendaCompra;
use App\Models\FaturaCompra;
use App\Services\Compras\RelacoesNomes;
use App\Services\Compras\ServicoContratosFornecedores;
use App\Services\Compras\ServicoEdicaoFaturaCompra;
use App\Services\Compras\ServicoListagensCompras;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Paridade das listas de Compras com o legado (verificacao_visual/RELATORIO.md, secção 5): acções por linha que ainda não
 * tinham endpoint — «Fluxo Documental»/«Ver Fluxo» (showPurchaseWorkflow), «Associar» a encomenda a um contrato a partir da
 * lista (associarContratoEncomenda) e «Editar Fatura» (showNewDirectPurchaseInvoice com id, só por contabilizar).
 * Autorização pelas permissões do catálogo, como os restantes endpoints de /api/compras.
 */
final class ParidadeComprasController extends Controller
{
    private const VER = ['compras_pedidos_view', 'compras_prospeccao_view', 'compras_encomendas_view', 'compras_rececoes_view', 'armazem_rececoes_view', 'compras_faturacao_view'];

    public function __construct(private readonly ServicoListagensCompras $listagens) {}

    /** GET /compras/fluxo-documental/{tipo}/{id} — cadeia de documentos e lançamentos do processo. */
    public function fluxoDocumental(string $tipo, int $id): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso($this->listagens->fluxoDocumental($tipo, $id), 'Fluxo documental da compra.');
    }

    /** POST /compras/encomendas/{id}/contrato — {contrato_fornecedor_id|null}: associa, muda ou retira o contrato da encomenda. */
    public function contratoEncomenda(Request $r, int $id): JsonResponse
    {
        $this->exigir('compras_contratos_gerir', 'compras_enc_criar');
        $empresa = app(ContextoEmpresa::class)->obrigatorio();
        $d = $r->validate(['contrato_fornecedor_id' => ['nullable', 'integer', Rule::exists('contratos_fornecedores', 'id')->where('empresa_id', $empresa)]]);
        $encomenda = EncomendaCompra::query()->findOrFail($id);
        $servico = app(ServicoContratosFornecedores::class);

        $res = DB::transaction(function () use ($d, $encomenda, $servico, $empresa) {
            $atual = DB::table('contratos_fornecedores_encomendas')->where('empresa_id', $empresa)->where('encomenda_compra_id', $encomenda->id)->value('contrato_fornecedor_id')
                ?? $encomenda->contrato_fornecedor_id;
            $novo = $d['contrato_fornecedor_id'] ?? null;
            if (! $novo) {
                return $atual ? $servico->desassociarEncomenda(ContratoFornecedor::query()->findOrFail($atual), $encomenda) : null;
            }

            // escolha explícita na lista: se a encomenda estava noutro contrato, passa para o escolhido
            return $servico->associarEncomendas(ContratoFornecedor::query()->findOrFail($novo), [$encomenda->id], true);
        });

        return RespostaApi::sucesso($res, ($d['contrato_fornecedor_id'] ?? null) ? (($res['consumo']['excedido'] ?? false)
            ? 'Encomenda associada ao contrato — atenção: o valor encomendado excede o contratado.' : 'Encomenda associada ao contrato.') : 'Encomenda retirada do contrato.');
    }

    /** PUT /compras/faturas/{id} — corrige o cabeçalho (n.º, datas) de uma factura por contabilizar. */
    public function editarFatura(Request $r, int $id): JsonResponse
    {
        $this->exigir('compras_fact_registar');
        $d = $r->validate(['numero_fatura' => ['required', 'string', 'max:50'], 'data' => ['required', 'date_format:Y-m-d'],
            'data_vencimento' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:data']], [], ['numero_fatura' => 'n.º da factura', 'data_vencimento' => 'data de vencimento']);
        $f = app(ServicoEdicaoFaturaCompra::class)->editarCabecalho(FaturaCompra::query()->findOrFail($id), $d);

        return RespostaApi::sucesso($f->load(RelacoesNomes::fornecedor())->toArray(), "Factura {$f->numero_fatura} actualizada.");
    }
}
