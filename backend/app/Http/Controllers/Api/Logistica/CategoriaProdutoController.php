<?php

namespace App\Http\Controllers\Api\Logistica;

use App\Http\Controllers\Controller;
use App\Models\CategoriaProduto;
use App\Services\Logistica\ServicoProdutos;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * /api/logistica/categorias-produtos — separador "Categorias" de Vendas no legado (só o nome, js/ui_sales.js:5489).
 * Ronda 3: a categoria define as contas contabilísticas dos seus produtos. Alterar contas exige a tarefa sensível
 * vendas_categorias_contas (muda a contabilização de todos os produtos da categoria); o nome e a descrição, vendas_produtos_gerir.
 */
final class CategoriaProdutoController extends Controller
{
    private const VER = ['vendas_produtos_view', 'vendas_faturacao_view', 'armazem_stock_view', 'pos_view', 'pos_venda', 'compras_pedidos_view'];

    public function __construct(private readonly ServicoProdutos $produtos) {}

    public function index(): JsonResponse
    {
        $this->exigir(...self::VER);
        $contagens = DB::table('produtos')->where('empresa_id', app(ContextoEmpresa::class)->obrigatorio())->whereNull('eliminado_em')
            ->whereNotNull('categoria_produto_id')->groupBy('categoria_produto_id')->selectRaw('categoria_produto_id, COUNT(*) AS n')->pluck('n', 'categoria_produto_id');

        return RespostaApi::sucesso(CategoriaProduto::query()->ordenarPorNome()->get()
            ->map(fn (CategoriaProduto $c) => ServicoProdutos::categoriaParaApi($c, (int) ($contagens[$c->id] ?? 0)))->values(), 'Categorias obtidas com sucesso.');
    }

    public function show(int $categoria): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso(ServicoProdutos::categoriaParaApi(CategoriaProduto::query()->findOrFail($categoria)), 'Categoria obtida com sucesso.');
    }

    public function store(Request $request): JsonResponse
    {
        $dados = $this->validar($request, true);

        return RespostaApi::criado(ServicoProdutos::categoriaParaApi($this->produtos->guardarCategoria($dados)), 'Categoria criada com sucesso.');
    }

    public function update(Request $request, int $categoria): JsonResponse
    {
        $c = CategoriaProduto::query()->findOrFail($categoria);
        $dados = $this->validar($request, false, $c);

        return RespostaApi::sucesso(ServicoProdutos::categoriaParaApi($this->produtos->guardarCategoria($dados, $c)), 'Categoria actualizada com sucesso.');
    }

    public function destroy(int $categoria): JsonResponse
    {
        $this->exigir('vendas_dados_del');
        $this->produtos->eliminarCategoria(CategoriaProduto::query()->findOrFail($categoria));

        return RespostaApi::sucesso(null, 'Categoria eliminada com sucesso.');
    }

    /**
     * Autorização ANTES da validação (OWASP A01) e por campo: contas só com vendas_categorias_contas.
     *
     * @return array<string, mixed>
     */
    private function validar(Request $request, bool $criar, ?CategoriaProduto $actual = null): array
    {
        $this->exigir('vendas_produtos_gerir', 'vendas_categorias_contas');
        $contas = array_keys(CategoriaProduto::CONTAS);
        $mudaContas = collect($contas)->contains(fn ($c) => $request->has($c)
            && trim(str_replace(['.', ' '], '', (string) $request->input($c))) !== (string) ($actual?->getAttribute($c) ?? ''));
        if ($mudaContas && ! Gate::allows('vendas_categorias_contas')) {
            throw new AccessDeniedHttpException('Definir as contas das categorias exige a permissão «Definir as contas contabilísticas das categorias».');
        }

        return $request->validate([
            'nome' => [$criar ? 'required' : 'sometimes', 'string', 'max:255'],
            'descricao' => ['nullable', 'string', 'max:2000'],
            ...array_fill_keys($contas, ['nullable', 'string', 'max:20', 'regex:/^[0-9][0-9.]*$/']),
        ], [], ['nome' => 'nome da categoria']);
    }
}
