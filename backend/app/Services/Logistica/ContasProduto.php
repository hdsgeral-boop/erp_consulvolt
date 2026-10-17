<?php

namespace App\Services\Logistica;

use App\Models\CategoriaProduto;
use App\Models\Produto;

/**
 * Resolvedor ÚNICO das contas contabilísticas de um produto (ronda 3): as contas definem-se na CATEGORIA e o produto
 * herda-as. Precedência, campo a campo:
 *   1. a conta da categoria do produto (incluindo categorias eliminadas logicamente — documentos antigos);
 *   2. a conta gravada no próprio produto (contas de recurso: produtos ainda não convertidos pelo comando
 *      erp:produtos:converter-contas-categorias e serviços geridos noutros ecrãs, como a tabela da lavandaria);
 *   3. nada (null) — o chamador aplica a conta por omissão da configuração (vendas, compras, logística).
 * A conversão copia para a categoria exactamente as contas que cada produto tinha, por isso o resultado contabilístico
 * dos produtos convertidos não muda. Toda a contabilização (vendas, compras, recepções, stock, CMV, inventário,
 * acréscimos, orçamento) lê as contas por aqui.
 */
final class ContasProduto
{
    /** Campo do resolvedor => coluna (igual na categoria e no produto). */
    public const COLUNAS = [
        'venda' => 'codigo_conta', 'custo' => 'conta_custo', 'compra' => 'conta_compra', 'inventario' => 'conta_inventario',
        'ivaLiquidado' => 'conta_iva_liquidado', 'ivaDedutivel' => 'conta_iva_dedutivel', 'quebra' => 'conta_quebra', 'sobra' => 'conta_sobra',
        'ativo' => 'conta_ativo',
    ];

    /**
     * @param  array<string, 'categoria'|'produto'|null>  $origem  de onde veio cada conta (por campo do resolvedor)
     */
    private function __construct(
        public readonly ?string $venda,
        public readonly ?string $custo,
        public readonly ?string $compra,
        public readonly ?string $inventario,
        public readonly ?string $ivaLiquidado,
        public readonly ?string $ivaDedutivel,
        public readonly ?string $quebra,
        public readonly ?string $sobra,
        public readonly ?string $ativo,
        public readonly array $origem,
    ) {}

    public static function de(?Produto $produto): self
    {
        $categoria = $produto ? self::categoria($produto) : null;
        $valores = [];
        $origem = [];
        foreach (self::COLUNAS as $campo => $coluna) {
            $daCategoria = self::texto($categoria?->getAttribute($coluna));
            $doProduto = self::texto($produto?->getAttribute($coluna));
            if ($campo === 'ivaLiquidado') {
                $doProduto ??= self::texto($produto?->getAttribute('conta_iva'));   // retrocompatibilidade do legado (conta_iva = IVA liquidado)
            }
            $valores[$campo] = $daCategoria ?? $doProduto;
            $origem[$campo] = $daCategoria !== null ? 'categoria' : ($doProduto !== null ? 'produto' : null);
        }

        return new self(...$valores, origem: $origem);
    }

    /** Contas pelo nome da coluna (codigo_conta, conta_custo, …) — para respostas da API. */
    public function porColuna(): array
    {
        $saida = [];
        foreach (self::COLUNAS as $campo => $coluna) {
            $saida[$coluna] = $this->{$campo};
        }

        return $saida;
    }

    private static function categoria(Produto $produto): ?CategoriaProduto
    {
        if (! $produto->categoria_produto_id) {
            return null;
        }
        if ($produto->relationLoaded('categoriaProduto') && $produto->categoriaProduto?->getKey() === (int) $produto->categoria_produto_id) {
            return $produto->categoriaProduto;
        }
        $categoria = CategoriaProduto::query()->withTrashed()->find($produto->categoria_produto_id);
        $produto->setRelation('categoriaProduto', $categoria);

        return $categoria;
    }

    private static function texto(mixed $v): ?string
    {
        $t = trim((string) ($v ?? ''));

        return $t === '' ? null : $t;
    }
}
