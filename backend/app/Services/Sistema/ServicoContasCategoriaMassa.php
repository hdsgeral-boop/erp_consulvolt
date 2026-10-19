<?php

namespace App\Services\Sistema;

use App\Exceptions\ErroNegocio;
use App\Models\CategoriaProduto;
use App\Services\Logistica\ServicoProdutos;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Contas contabilísticas na edição em massa de produtos (Sistema › Migração de dados e Vendas › Produtos «Editar em
 * massa»). Ronda 3: as contas definem-se na CATEGORIA e sobrepõem-se às do produto (ContasProduto::de), por isso a
 * edição em massa deixou de gravar contas no produto (ficariam escondidas pela categoria). Em vez disso:
 *  - escolhe-se a categoria dos produtos seleccionados (dados.categoria_produto_id), ou
 *  - editam-se as contas de UMA categoria (categoria_contas), com a tarefa sensível vendas_categorias_contas — a
 *    alteração vale para todos os produtos da categoria, não só para os seleccionados.
 */
final class ServicoContasCategoriaMassa
{
    public const RETIRAR = '__RETIRAR__';

    public const MENSAGEM_CONTAS_NO_PRODUTO = 'As contas contabilísticas dos produtos vêm da categoria (sobrepõe-se às do produto) e já não se gravam no produto. '
        .'Escolha a categoria dos produtos seleccionados ou edite as contas da categoria.';

    public function __construct(
        private readonly ServicoProdutos $produtos,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    /** Recusa contas de produto (qualquer valor preenchido) com uma mensagem que explica o caminho certo. */
    public function recusarContasNoProduto(array $contas): void
    {
        $preenchidas = array_keys(array_filter($contas, fn ($v) => $v !== null && $v !== ''));
        if ($preenchidas) {
            throw new ErroNegocio(self::MENSAGEM_CONTAS_NO_PRODUTO, 'CONTAS_NA_CATEGORIA', 422, ['contas' => $preenchidas]);
        }
    }

    /**
     * Altera as contas de uma categoria (código, ou «__RETIRAR__» para a deixar vazia — os produtos voltam à conta de
     * recurso do produto ou à da configuração). Valida cada conta como a ficha da categoria (movimento + classe).
     *
     * @param  array<string, string|null>  $contas  campo => código | «__RETIRAR__»
     * @return array{categoria_id: int, categoria: string, contas: array<string, ?string>, produtos: int}
     */
    public function editarContas(int $categoriaId, array $contas, string $origem): array
    {
        if (! Gate::allows('vendas_categorias_contas')) {
            throw new ErroNegocio('Editar as contas da categoria exige a permissão «Definir as contas contabilísticas das categorias».', 'SEM_PERMISSAO', 403);
        }
        $alteracao = [];
        foreach ($contas as $campo => $codigo) {
            if (! array_key_exists($campo, CategoriaProduto::CONTAS) || $codigo === null || $codigo === '') {
                continue;
            }
            $alteracao[$campo] = $codigo === self::RETIRAR ? null : (string) $codigo;
        }
        if (! $alteracao) {
            throw new ErroNegocio('Escolha pelo menos uma conta da categoria a alterar.', 'SEM_ALTERACOES', 422);
        }

        return DB::transaction(function () use ($categoriaId, $alteracao, $origem) {
            $categoria = CategoriaProduto::query()->lockForUpdate()->find($categoriaId)
                ?? throw new ErroNegocio('A categoria escolhida não existe nesta empresa.', 'CATEGORIA_INEXISTENTE', 422);
            $antes = array_intersect_key(ServicoProdutos::categoriaParaApi($categoria), $alteracao);
            $categoria = $this->produtos->guardarCategoria($alteracao, $categoria);
            $depois = array_intersect_key(ServicoProdutos::categoriaParaApi($categoria), $alteracao);
            $n = DB::table('produtos')->where('categoria_produto_id', $categoria->id)->whereNull('eliminado_em')->count();
            $this->auditoria->registar($origem, 'Editar contas da categoria', "Categoria «{$categoria->nome}»: ".implode(', ', array_keys($alteracao))
                ." alterada(s); aplica-se aos {$n} produto(s) da categoria.", 'categorias_produtos', $categoria->id, $antes, $depois);

            return ['categoria_id' => (int) $categoria->id, 'categoria' => (string) $categoria->nome, 'contas' => $depois, 'produtos' => $n];
        });
    }
}
