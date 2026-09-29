<?php

namespace App\Services\Logistica;

use App\Exceptions\ErroNegocio;
use App\Models\Armazem;
use App\Models\MovimentoInventario;
use App\Models\Produto;
use App\Models\StockArmazem;
use Illuminate\Support\Facades\DB;

/**
 * Movimentos de stock — ÚNICA via de alteração do stock (ADR-028). No legado havia três fontes desalinhadas
 * (products.stock_qty, warehouse_stock e movimentos) actualizadas em sítios diferentes e sem transacção.
 * Aqui, cada movimento actualiza, na mesma transacção e com o produto bloqueado (FOR UPDATE):
 *   movimentos_inventario (histórico), stock_armazem (por armazém) e produtos.quantidade_stock (total);
 * nas ENTRADAS recalcula o custo médio ponderado (o legado nunca o calculava).
 * As saídas são valorizadas ao custo indicado (ex.: o da entrada que se estorna) ou ao custo médio.
 */
final class ServicoStock
{
    /** @return array{movimento: MovimentoInventario, custo_unitario: string} */
    public function entrada(int $produtoId, int $armazemId, string $quantidade, string $custoUnitario, string $data, string $referencia, ?int $terceiroId = null, ?int $projetoId = null): array
    {
        return $this->movimentar('ENTRADA', $produtoId, $armazemId, $quantidade, $custoUnitario, $data, $referencia, $terceiroId, $projetoId);
    }

    /** @return array{movimento: MovimentoInventario, custo_unitario: string} */
    public function saida(int $produtoId, int $armazemId, string $quantidade, ?string $custoUnitario, string $data, string $referencia, ?int $terceiroId = null, ?int $projetoId = null, bool $permitirNegativo = false): array
    {
        return $this->movimentar('SAIDA', $produtoId, $armazemId, $quantidade, $custoUnitario, $data, $referencia, $terceiroId, $projetoId, $permitirNegativo);
    }

    private function movimentar(string $tipo, int $produtoId, int $armazemId, string $quantidade, ?string $custo, string $data, string $referencia,
        ?int $terceiroId, ?int $projetoId, bool $permitirNegativo = false): array
    {
        if (bccomp($quantidade, '0', 3) <= 0) {
            throw new ErroNegocio('A quantidade do movimento de stock tem de ser positiva.', 'QUANTIDADE_INVALIDA', 422);
        }
        Armazem::query()->findOrFail($armazemId);

        return DB::transaction(function () use ($tipo, $produtoId, $armazemId, $quantidade, $custo, $data, $referencia, $terceiroId, $projetoId, $permitirNegativo) {
            $produto = Produto::query()->lockForUpdate()->findOrFail($produtoId);
            if (! $produto->movimenta_stock) {
                throw new ErroNegocio("O produto {$produto->codigo} não movimenta stock.", 'PRODUTO_SEM_STOCK', 422);
            }
            $linha = StockArmazem::query()->where('armazem_id', $armazemId)->where('produto_id', $produtoId)->lockForUpdate()->first()
                ?? StockArmazem::create(['armazem_id' => $armazemId, 'produto_id' => $produtoId, 'quantidade_stock' => 0]);

            $total = (string) ($produto->quantidade_stock ?? '0');
            $noArmazem = (string) ($linha->quantidade_stock ?? '0');
            $custoMedio = (string) ($produto->custo_medio ?? '0');

            if ($tipo === 'ENTRADA') {
                $custo = (string) $custo;
                $novoTotal = bcadd($total, $quantidade, 3);
                // custo médio ponderado sobre o stock existente positivo
                $base = bccomp($total, '0', 3) > 0 ? $total : '0';
                $custoMedio = bccomp(bcadd($base, $quantidade, 3), '0', 3) > 0
                    ? bcdiv(bcadd(bcmul($base, $custoMedio, 8), bcmul($quantidade, $custo, 8), 8), bcadd($base, $quantidade, 3), 6) : $custo;
                $novoArmazem = bcadd($noArmazem, $quantidade, 3);
            } else {
                $custo ??= $custoMedio;
                if (! $permitirNegativo && bccomp($noArmazem, $quantidade, 3) < 0) {
                    throw new ErroNegocio("Stock insuficiente de {$produto->codigo} no armazém (existe {$noArmazem}, pedido {$quantidade}).", 'STOCK_INSUFICIENTE', 422,
                        ['produto_id' => $produto->id, 'disponivel' => $noArmazem]);
                }
                $novoTotal = bcsub($total, $quantidade, 3);
                $novoArmazem = bcsub($noArmazem, $quantidade, 3);
            }

            $linha->update(['quantidade_stock' => $novoArmazem]);
            $produto->forceFill(['quantidade_stock' => $novoTotal, 'custo_medio' => $custoMedio])->save();
            $movimento = MovimentoInventario::create([
                'produto_id' => $produtoId, 'armazem_id' => $armazemId, 'tipo' => $tipo, 'quantidade' => $quantidade, 'data' => $data,
                'terceiro_id' => $terceiroId, 'referencia' => mb_substr($referencia, 0, 255), 'projeto_id' => $projetoId,
                'preco_unitario' => number_format((float) $custo, 2, '.', ''),
            ]);

            return ['movimento' => $movimento, 'custo_unitario' => (string) $custo];
        });
    }
}
