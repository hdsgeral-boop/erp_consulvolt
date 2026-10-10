<?php

namespace App\Services\POS;

use App\Exceptions\ErroNegocio;
use App\Models\Armazem;
use App\Models\Produto;
use App\Models\StockArmazem;
use App\Services\Logistica\ServicoStock;
use Illuminate\Support\Facades\DB;

/**
 * POS de armazém › catálogo de artigos do balcão e «⚖ Acertar stock deste armazém» (js/ui_pos_armazem.js:154-284,
 * RELATORIO 2.6.2-2.6.4).
 *
 * Artigos: todos os produtos de stock da empresa com a quantidade NO ARMAZÉM (0 quando não há linha — o legado mostrava
 * «Esgotado») e o preço unitário (para o «TOTAL DO PICKING»).
 *
 * Acerto: o legado comparava o stock global do produto com a soma dos armazéns e aplicava a diferença ao armazém
 * seleccionado, sem movimentos (o kardex deixava de bater com o saldo). Aqui, com a mesma regra (o stock global não muda;
 * o ajuste fica limitado ao que o armazém tem, para não ficar negativo), o acerto é um movimento AJUSTE do armazém pela
 * via única do stock (ServicoStock, com exercício aberto e sem inventário em curso), documento ACERTO_ARMAZEM, ao custo
 * médio; o total do produto e o custo médio são repostos no fim (a diferença já estava no total). Assim o histórico de
 * movimentos, o saldo por armazém e o total do produto voltam a bater (ADR-042).
 */
final class ServicoAcertoStockPOSArmazem
{
    public const DOCUMENTO = 'ACERTO_ARMAZEM';

    public function __construct(private readonly ServicoStock $stock) {}

    /** @return list<array{produto_id: int, codigo: ?string, nome: ?string, preco_unitario: string, quantidade: string, bloqueado: bool}> */
    public function artigos(int $armazem): array
    {
        Armazem::query()->findOrFail($armazem);

        return Produto::query()->where('produtos.movimenta_stock', true)
            ->leftJoin('stock_armazem as sa', fn ($j) => $j->on('sa.produto_id', '=', 'produtos.id')->where('sa.armazem_id', $armazem))
            ->orderBy('produtos.nome')->orderBy('produtos.id')
            ->get(['produtos.id', 'produtos.codigo', 'produtos.nome', 'produtos.preco_unitario', 'produtos.bloqueado', 'sa.quantidade_stock as stock_local'])
            ->map(fn ($p) => ['produto_id' => $p->id, 'codigo' => $p->codigo, 'nome' => $p->nome, 'preco_unitario' => number_format((float) ($p->preco_unitario ?? 0), 2, '.', ''),
                'quantidade' => number_format((float) ($p->stock_local ?? 0), 3, '.', ''), 'bloqueado' => (bool) $p->bloqueado])
            ->values()->all();
    }

    /**
     * Produtos cujo stock global difere da soma dos armazéns, com o ajuste proposto para o armazém indicado.
     *
     * @return list<array<string, mixed>>
     */
    public function diferencas(int $armazem): array
    {
        Armazem::query()->findOrFail($armazem);
        $produtos = Produto::query()->where('movimenta_stock', true)->orderBy('nome')->get(['id', 'codigo', 'nome', 'quantidade_stock']);
        if ($produtos->isEmpty()) {
            return [];
        }
        $somas = StockArmazem::query()->whereIn('produto_id', $produtos->pluck('id'))->groupBy('produto_id')
            ->selectRaw('produto_id, SUM(quantidade_stock) AS soma')->pluck('soma', 'produto_id');
        $locais = StockArmazem::query()->where('armazem_id', $armazem)->whereIn('produto_id', $produtos->pluck('id'))->pluck('quantidade_stock', 'produto_id');
        $lista = [];
        foreach ($produtos as $p) {
            $linha = $this->linha($p->id, $p->codigo, $p->nome, (string) ($p->quantidade_stock ?? '0'), (string) ($somas[$p->id] ?? '0'), (string) ($locais[$p->id] ?? '0'));
            if ($linha) {
                $lista[] = $linha;
            }
        }

        return $lista;
    }

    /**
     * @param  list<int>|null  $produtos  produtos a acertar (null = todos os que têm diferença)
     * @return array{acertados: int, linhas: list<array<string, mixed>>}
     */
    public function acertar(int $armazem, ?array $produtos = null): array
    {
        Armazem::query()->findOrFail($armazem);

        return DB::transaction(function () use ($armazem, $produtos) {
            $alvo = $produtos ?? array_column($this->diferencas($armazem), 'produto_id');
            $feitas = [];
            foreach (array_values(array_unique(array_map('intval', $alvo))) as $id) {
                $p = Produto::query()->lockForUpdate()->find($id) ?? throw new ErroNegocio("Produto #{$id} inexistente.", 'PRODUTO_INEXISTENTE', 422);
                if (! $p->movimenta_stock) {
                    throw new ErroNegocio("O produto {$p->codigo} não movimenta stock.", 'PRODUTO_SEM_STOCK', 422);
                }
                $global = (string) ($p->quantidade_stock ?? '0');
                $custo = (string) ($p->custo_medio ?? '0');
                $soma = (string) StockArmazem::query()->where('produto_id', $p->id)->lockForUpdate()->get(['quantidade_stock'])->sum('quantidade_stock');
                $local = (string) (StockArmazem::query()->where('produto_id', $p->id)->where('armazem_id', $armazem)->value('quantidade_stock') ?? '0');
                $linha = $this->linha($p->id, $p->codigo, $p->nome, $global, $soma, $local);
                if (! $linha || bccomp($linha['ajuste'], '0', 3) === 0) {
                    continue;
                }
                $positivo = bccomp($linha['ajuste'], '0', 3) > 0;
                $this->stock->ajustar($p->id, $armazem, $positivo ? 'E' : 'S', ltrim($linha['ajuste'], '-'), $custo, now()->toDateString(),
                    'Acerto do stock do armazém com o stock global (POS de armazém)', ['documento_tipo' => self::DOCUMENTO]);
                // o stock global não muda: a diferença já estava no total do produto
                $p->refresh()->forceFill(['quantidade_stock' => $global, 'custo_medio' => $custo])->save();
                $feitas[] = $linha;
            }
            if (! $feitas) {
                throw new ErroNegocio('Não há diferenças entre o stock global e os armazéns nos produtos indicados.', 'SEM_DIFERENCAS', 422);
            }

            return ['acertados' => count($feitas), 'linhas' => $feitas];
        });
    }

    /** @return array<string, mixed>|null */
    private function linha(int $id, ?string $codigo, ?string $nome, string $global, string $soma, string $local): ?array
    {
        $g = number_format((float) $global, 3, '.', '');
        $s = number_format((float) $soma, 3, '.', '');
        $l = number_format((float) $local, 3, '.', '');
        $dif = bcsub($g, $s, 3);
        if (bccomp($dif, '0', 3) === 0) {
            return null;
        }
        $minimo = bcmul($l, '-1', 3);
        $ajuste = bccomp($dif, $minimo, 3) < 0 ? $minimo : $dif;   // não deixa o armazém negativo (como o legado)

        return ['produto_id' => $id, 'codigo' => $codigo, 'nome' => $nome, 'global' => $g, 'armazens' => $s, 'local' => $l, 'diferenca' => $dif,
            'ajuste' => $ajuste, 'limitado' => bccomp($ajuste, $dif, 3) !== 0, 'fica' => bcadd($l, $ajuste, 3)];
    }
}
