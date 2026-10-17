<?php

namespace App\Http\Controllers\Api\Vendas;

use App\Http\Controllers\Controller;
use App\Services\Vendas\ServicoPesquisaClientes;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Escolha do cliente nos documentos de venda (r3_vendas):
 *   GET  /vendas/clientes/pesquisa?q=…&limite=…    — terceiros que são (ou podem passar a ser) clientes;
 *   POST /vendas/clientes/{terceiro}/papel-cliente — acrescenta o papel de cliente com a conta da classe 31.
 */
final class PesquisaClientesController extends Controller
{
    public function __construct(private readonly ServicoPesquisaClientes $pesquisa) {}

    public function pesquisar(Request $request): JsonResponse
    {
        $this->exigir('vendas_fat_emitir', 'vendas_faturacao_view', 'vendas_clientes_view');
        $f = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'limite' => ['nullable', 'integer', 'min:1', 'max:'.ServicoPesquisaClientes::LIMITE_MAXIMO]]);

        return RespostaApi::sucesso($this->pesquisa->pesquisar($f['q'] ?? null, isset($f['limite']) ? (int) $f['limite'] : null), 'Pesquisa de clientes.');
    }

    public function tornarCliente(Request $request, int $terceiro): JsonResponse
    {
        $this->exigir('vendas_clientes_gerir');
        $d = $request->validate(['conta_cliente' => ['required', 'string', 'max:20', 'regex:/^[0-9.]+$/']], [], ['conta_cliente' => 'conta de cliente']);

        return RespostaApi::sucesso($this->pesquisa->tornarCliente($terceiro, $d['conta_cliente']), 'A entidade passou a ser cliente.');
    }
}
