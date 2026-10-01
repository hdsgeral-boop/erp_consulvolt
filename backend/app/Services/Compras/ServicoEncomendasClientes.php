<?php

namespace App\Services\Compras;

use App\Exceptions\ErroNegocio;
use App\Models\ItemVenda;
use App\Models\PedidoCompra;
use App\Models\Venda;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Encomendas de clientes → pedidos de compra (ecrã compras_encomendas_clientes, generatePurchaseRequestFromSales,
 * js/ui_compras_v2.js:3909-3998). No legado o ecrã ficava vazio; aqui:
 *   - lista as encomendas de clientes (NE) com as linhas por comprar, a quantidade pendente e o stock disponível;
 *   - gera UM pedido de compra consolidado por produto a partir das linhas escolhidas, com preço estimado ao custo
 *     médio (o legado deixava 0) e o utilizador como criador (o legado deixava criado_por vazio → auto-aprovação);
 *   - cada linha só entra num pedido activo (o legado dependia de uma marca sem verificação); anular o pedido
 *     liberta as linhas.
 */
final class ServicoEncomendasClientes
{
    public function __construct(private readonly ServicoProcessoCompras $processo) {}

    public function listar(): array
    {
        $vendas = Venda::query()->where('tipo_documento', 'NE')->where(fn ($q) => $q->whereNull('estado')->orWhere('estado', '<>', 'ANULADO'))
            ->with(['cliente' => fn ($q) => $q->withTrashed()->select(['id', 'nome']), 'itensVenda.produto:id,codigo,nome,quantidade_stock,movimenta_stock'])->orderByDesc('data_emissao')->get();
        $pedidos = PedidoCompra::query()->whereIn('id', $vendas->flatMap(fn ($v) => $v->itensVenda->pluck('pedido_compra_id'))->filter()->unique())
            ->get(['id', 'numero_pedido', 'estado'])->keyBy('id');

        return $vendas->map(fn (Venda $v) => [
            'id' => $v->id, 'numero_documento' => $v->numero_documento, 'data_emissao' => $v->data_emissao?->toDateString(), 'cliente_id' => $v->cliente_id,
            'cliente' => $v->cliente ? ['id' => $v->cliente->id, 'nome' => $v->cliente->nome] : null, 'estado' => $v->estado,
            'linhas' => $v->itensVenda->map(function (ItemVenda $i) use ($pedidos) {
                $p = $i->pedido_compra_id ? ($pedidos[$i->pedido_compra_id] ?? null) : null;
                $ativo = $p && $p->estado !== 'ANULADO';

                return ['id' => $i->id, 'produto_id' => $i->produto_id, 'produto' => $i->produto?->nome, 'descricao' => $i->descricao,
                    'quantidade' => $i->quantidade, 'pendente' => number_format(max(0, (float) $i->quantidade - (float) $i->quantidade_faturada), 3, '.', ''),
                    'stock_disponivel' => $i->produto?->movimenta_stock ? $i->produto->quantidade_stock : null,
                    'pedido_compra' => $ativo ? ['id' => $p->id, 'numero_pedido' => $p->numero_pedido, 'estado' => $p->estado] : null, 'por_comprar' => ! $ativo];
            })->values(),
        ])->filter(fn ($v) => $v['linhas']->isNotEmpty())->values()->all();
    }

    /** @param  list<int>  $itens  ids de itens_venda */
    public function gerarPedido(array $itens, ?string $nomeRequerente = null, ?string $descricao = null): PedidoCompra
    {
        return DB::transaction(function () use ($itens, $nomeRequerente, $descricao) {
            $linhas = ItemVenda::query()->whereIn('id', $itens)->with(['venda', 'produto'])->lockForUpdate()->get();
            if ($linhas->count() !== count(array_unique($itens)) || $linhas->isEmpty()) {
                throw new ErroNegocio('Linhas de encomenda inexistentes.', 'LINHAS_INVALIDAS', 422);
            }
            $ativos = PedidoCompra::query()->whereIn('id', $linhas->pluck('pedido_compra_id')->filter())->where('estado', '<>', 'ANULADO')->pluck('numero_pedido', 'id');
            foreach ($linhas as $l) {
                if ($l->venda?->tipo_documento !== 'NE' || $l->venda->estado === 'ANULADO') {
                    throw new ErroNegocio('Só se geram pedidos a partir de encomendas de clientes (NE) não anuladas.', 'LINHAS_INVALIDAS', 422);
                }
                if ($l->pedido_compra_id && isset($ativos[$l->pedido_compra_id])) {
                    throw new ErroNegocio("A linha {$l->descricao} já está no pedido {$ativos[$l->pedido_compra_id]}.", 'LINHA_JA_EM_PEDIDO', 422);
                }
            }
            $porProduto = $linhas->groupBy('produto_id')->map(fn ($g) => [
                'produto_id' => (int) $g->first()->produto_id, 'descricao' => $g->first()->produto?->nome ?? $g->first()->descricao,
                'quantidade' => number_format($g->sum(fn ($l) => max(0, (float) $l->quantidade - (float) $l->quantidade_faturada)), 3, '.', ''),
                'preco_unitario' => (string) ($g->first()->produto?->custo_medio ?? 0),
            ])->filter(fn ($l) => (float) $l['quantidade'] > 0)->values()->all();
            if (! $porProduto) {
                throw new ErroNegocio('As linhas escolhidas já não têm quantidades pendentes.', 'SEM_QUANTIDADES', 422);
            }
            $vendas = $linhas->pluck('venda_id')->unique();
            $pedido = $this->processo->criarPedido([
                'nome_requerente' => $nomeRequerente ?: (Auth::user()?->nome_utilizador ?? 'Encomendas de clientes'),
                'descricao' => $descricao ?: 'Compra para encomendas de clientes: '.$linhas->pluck('venda.numero_documento')->unique()->implode(', '),
                'linhas' => $porProduto,
            ]);
            if ($vendas->count() === 1) {
                $pedido->update(['venda_origem_id' => $vendas->first()]);
            }
            ItemVenda::query()->whereIn('id', $linhas->pluck('id'))->update(['pedido_compra_id' => $pedido->id]);

            return $pedido->refresh();
        });
    }
}
