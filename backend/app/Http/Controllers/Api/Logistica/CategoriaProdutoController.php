<?php

namespace App\Http\Controllers\Api\Logistica;

use App\Http\Controllers\Controller;
use App\Models\CategoriaProduto;
use App\Services\Logistica\ServicoProdutos;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /api/logistica/categorias-produtos — separador "Categorias" de Vendas no legado. */
final class CategoriaProdutoController extends Controller
{
    public function __construct(private readonly ServicoProdutos $produtos) {}

    public function index(): JsonResponse
    {
        $this->exigir('vendas_produtos_view', 'vendas_faturacao_view', 'armazem_stock_view', 'pos_view', 'pos_venda');

        return RespostaApi::sucesso(CategoriaProduto::query()->orderBy('nome')->get(['id', 'nome']), 'Categorias obtidas com sucesso.');
    }

    public function store(Request $request): JsonResponse
    {
        $this->exigir('vendas_produtos_gerir');
        $dados = $request->validate(['nome' => ['required', 'string', 'max:255']], [], ['nome' => 'nome da categoria']);

        return RespostaApi::criado($this->produtos->guardarCategoria($dados), 'Categoria criada com sucesso.');
    }

    public function update(Request $request, int $categoria): JsonResponse
    {
        $this->exigir('vendas_produtos_gerir');
        $dados = $request->validate(['nome' => ['required', 'string', 'max:255']], [], ['nome' => 'nome da categoria']);

        return RespostaApi::sucesso($this->produtos->guardarCategoria($dados, CategoriaProduto::query()->findOrFail($categoria)), 'Categoria actualizada com sucesso.');
    }

    public function destroy(int $categoria): JsonResponse
    {
        $this->exigir('vendas_dados_del');
        $this->produtos->eliminarCategoria(CategoriaProduto::query()->findOrFail($categoria));

        return RespostaApi::sucesso(null, 'Categoria eliminada com sucesso.');
    }
}
