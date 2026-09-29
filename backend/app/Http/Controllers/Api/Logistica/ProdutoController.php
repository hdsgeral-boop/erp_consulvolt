<?php

namespace App\Http\Controllers\Api\Logistica;

use App\Http\Controllers\Controller;
use App\Http\Requests\Logistica\GuardarProdutoRequest;
use App\Http\Resources\Logistica\ProdutoResource;
use App\Models\Produto;
use App\Services\Logistica\ServicoProdutos;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /api/logistica/produtos — ecrã "vendas_produtos" do legado (produtos, serviços, quartos, lavandaria). */
final class ProdutoController extends Controller
{
    private const VER = ['vendas_produtos_view', 'vendas_faturacao_view', 'armazem_stock_view', 'compras_pedidos_view', 'pos_view'];

    public function __construct(private readonly ServicoProdutos $produtos) {}

    public function index(Request $request): JsonResponse
    {
        $this->exigir(...self::VER);
        $f = $request->validate([
            'pesquisa' => ['nullable', 'string', 'max:200'], 'categoria_produto_id' => ['nullable', 'integer'],
            'bloqueado' => ['nullable', 'boolean'], 'movimenta_stock' => ['nullable', 'boolean'],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:500'], 'pagina' => ['nullable', 'integer', 'min:1'],
        ]);

        return RespostaApi::paginado($this->produtos->listar($f), ProdutoResource::class);
    }

    /** GET /catalogo — produtos activos para facturação/POS (cache Redis). */
    public function catalogo(): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso($this->produtos->catalogo(), 'Catálogo obtido com sucesso.');
    }

    public function show(int $produto): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso(ProdutoResource::make(Produto::query()->findOrFail($produto)), 'Produto obtido com sucesso.');
    }

    public function store(GuardarProdutoRequest $request): JsonResponse
    {
        $this->exigir('vendas_produtos_gerir');

        return RespostaApi::criado(ProdutoResource::make($this->produtos->guardar($request->validated())), 'Produto criado com sucesso.');
    }

    public function update(GuardarProdutoRequest $request, int $produto): JsonResponse
    {
        $this->exigir('vendas_produtos_gerir');

        return RespostaApi::sucesso(ProdutoResource::make($this->produtos->guardar($request->validated(), Produto::query()->findOrFail($produto))),
            'Produto actualizado com sucesso.');
    }

    /** POST /{id}/bloquear — alterna bloqueado/desbloqueado (paridade: toggleBlockProduct). */
    public function bloquear(int $produto): JsonResponse
    {
        $this->exigir('vendas_produtos_gerir');
        $p = $this->produtos->alternarBloqueio(Produto::query()->findOrFail($produto));

        return RespostaApi::sucesso(ProdutoResource::make($p), $p->bloqueado ? 'Produto bloqueado.' : 'Produto desbloqueado.');
    }

    public function destroy(int $produto): JsonResponse
    {
        $this->exigir('vendas_dados_del');
        $this->produtos->eliminar(Produto::query()->findOrFail($produto));

        return RespostaApi::sucesso(null, 'Produto eliminado com sucesso.');
    }
}
