<?php

namespace App\Services\Logistica;

use App\Exceptions\ErroNegocio;
use App\Models\Armazem;
use App\Models\EncomendaCompra;
use App\Models\ItemVenda;
use App\Models\PedidoCompra;
use App\Models\Produto;
use App\Models\RececaoCompra;
use App\Models\StockArmazem;
use App\Models\Terceiro;
use App\Models\Venda;
use App\Services\Vendas\ServicoDocumentosVenda;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * «Emitir Guia de Saída (Entrega/Consumo)» do Armazém para clientes (showGuiaSaidaForm / loadGSFromSaleDirect /
 * loadGSFromBackToBackSelect / saveGuiaSaida, js/ui_warehouse.js:603-1008).
 *
 * ADR-043: a saída para o cliente é a guia de remessa (GR) das Vendas — stock ao custo médio e CMV no lançamento da GR. O
 * legado gravava a guia do armazém E uma «GR LOG» espelho nas vendas (dois documentos para a mesma saída, contabilizáveis os
 * dois); aqui emite-se só a GR, pelo serviço das Vendas, no armazém escolhido, marcada com o tipo do formulário
 * (vendas.saida_armazem = VENDA | BACK_TO_BACK) para aparecer na lista do armazém:
 *   - com encomenda (NE): conversão NE → GR com as quantidades do formulário — a encomenda fica com o entregue actualizado
 *     (o legado somava delivered_qty por produto, sem limite: entregava mais do que o encomendado);
 *   - sem encomenda: GR directa ao cliente, ao preço da ficha (como a «GR LOG»).
 * Correcções: o stock do armazém é verificado no servidor com bloqueio (o legado só o via no ecrã) e tudo corre numa
 * transacção (o legado gravava guia, stock e espelho em passos soltos e engolia os erros da integração com as vendas).
 *
 * «Encomendas vinculadas a compras»: NE não anuladas com quantidades por entregar e ligadas a compras (pedido de compra com
 * venda_origem_id, linhas com pedido_compra_id, ou encomenda de compra com venda_origem_id). «Satisfeita»: uma encomenda de
 * compra ligada já foi recebida (RECEBIDO/CONCLUIDO ou com uma recepção validada) — no legado, RECEBIDO/CONCLUIDO/VALIDADO.
 */
final class ServicoGuiasRemessaArmazem
{
    public const TIPOS = ['VENDA', 'BACK_TO_BACK'];

    public function __construct(private readonly ServicoDocumentosVenda $documentos) {}

    /** @return list<array<string, mixed>> */
    public function encomendasVinculadas(): array
    {
        $porEntregar = 'itens_venda.quantidade - COALESCE(itens_venda.quantidade_entregue, 0) > 0.0005';
        $nes = Venda::query()->where('tipo_documento', 'NE')->where('estado', '<>', 'ANULADO')
            ->whereHas('itensVenda', fn ($q) => $q->whereRaw($porEntregar))
            ->with(['cliente' => fn ($q) => $q->withTrashed()->select(['id', 'nome', 'nif'])])
            ->orderByDesc('data_emissao')->orderByDesc('id')->limit(500)->get();
        if ($nes->isEmpty()) {
            return [];
        }
        $ids = $nes->pluck('id')->all();

        // pedidos de compra (não anulados) de cada encomenda: pela origem do pedido e pelas linhas enviadas a compras
        $pedidosPorNe = [];
        foreach (PedidoCompra::query()->where('estado', '<>', 'ANULADO')->whereIn('venda_origem_id', $ids)->get(['id', 'venda_origem_id']) as $p) {
            $pedidosPorNe[$p->venda_origem_id][$p->id] = true;
        }
        $linhasEmPedido = ItemVenda::query()->whereIn('venda_id', $ids)->whereNotNull('pedido_compra_id')->get(['venda_id', 'pedido_compra_id']);
        $activos = PedidoCompra::query()->where('estado', '<>', 'ANULADO')->whereIn('id', $linhasEmPedido->pluck('pedido_compra_id')->unique())->pluck('id')->flip();
        foreach ($linhasEmPedido as $l) {
            if (isset($activos[$l->pedido_compra_id])) {
                $pedidosPorNe[$l->venda_id][$l->pedido_compra_id] = true;
            }
        }
        $pedidos = collect($pedidosPorNe)->flatMap(fn ($p) => array_keys($p))->unique()->values()->all();

        // encomendas de compra (não anuladas) ligadas à NE directamente ou pelo pedido
        $encomendas = EncomendaCompra::query()->where('estado', '<>', 'ANULADA')
            ->where(fn ($q) => $q->whereIn('venda_origem_id', $ids)->orWhereIn('pedido_compra_id', $pedidos ?: [0]))
            ->get(['id', 'numero_encomenda', 'estado', 'venda_origem_id', 'pedido_compra_id']);
        $validadas = RececaoCompra::query()->whereIn('encomenda_compra_id', $encomendas->pluck('id')->all() ?: [0])->where('estado', '<>', 'ANULADO')
            ->where(fn ($q) => $q->where('validado', true)->orWhere('estado', 'VALIDADO'))->pluck('encomenda_compra_id')->flip();

        $linhas = ItemVenda::query()->whereIn('venda_id', $ids)->whereRaw($porEntregar)
            ->with(['produto' => fn ($q) => $q->withTrashed()->select(['id', 'codigo', 'nome', 'movimenta_stock'])])->orderBy('id')->get()->groupBy('venda_id');

        $res = [];
        foreach ($nes as $ne) {
            $pcs = array_keys($pedidosPorNe[$ne->id] ?? []);
            $ecs = $encomendas->filter(fn ($e) => (int) $e->venda_origem_id === $ne->id || in_array((int) $e->pedido_compra_id, $pcs, true))->values();
            if (! $pcs && $ecs->isEmpty()) {
                continue;   // sem processo de compra ligado: não é back-to-back
            }
            $res[] = [
                'id' => $ne->id, 'numero_documento' => $ne->numero_documento, 'data_emissao' => $ne->data_emissao?->toDateString(),
                'cliente_id' => $ne->cliente_id, 'cliente' => $ne->cliente ? ['id' => $ne->cliente->id, 'nome' => $ne->cliente->nome, 'nif' => $ne->cliente->nif] : null,
                'projeto_id' => $ne->projeto_id,
                'satisfeita' => $ecs->contains(fn ($e) => in_array($e->estado, ['RECEBIDO', 'CONCLUIDO'], true) || isset($validadas[$e->id])),
                'compras' => $ecs->map(fn ($e) => ['id' => $e->id, 'numero_encomenda' => $e->numero_encomenda, 'estado' => $e->estado,
                    'recebida' => in_array($e->estado, ['RECEBIDO', 'CONCLUIDO'], true) || isset($validadas[$e->id])])->all(),
                'linhas' => $this->linhasPorEntregar($linhas->get($ne->id, collect())),
            ];
        }

        return $res;
    }

    /** Stock de cada produto no armazém (coluna «Stock Disp.» do formulário — reloadGSStocks). @return array<int, string> */
    public function stockArmazem(int $armazem): array
    {
        Armazem::query()->findOrFail($armazem);

        return StockArmazem::query()->where('armazem_id', $armazem)->pluck('quantidade_stock', 'produto_id')->map(fn ($q) => (string) $q)->all();
    }

    /**
     * Emite a GR do armazém.
     *
     * @param  array{tipo: string, armazem_id: int, data: string, cliente_id?: ?int, encomenda_id?: ?int, projeto_id?: ?int, observacoes?: ?string,
     *               linhas: list<array{produto_id: int, quantidade: mixed, item_origem_id?: ?int}>}  $d
     */
    public function emitir(array $d): Venda
    {
        if (! in_array($d['tipo'], self::TIPOS, true)) {
            throw new ErroNegocio('Tipo de saída inválido para uma guia de remessa.', 'TIPO_INVALIDO', 422);
        }
        if ($d['tipo'] === 'BACK_TO_BACK' && empty($d['encomenda_id'])) {
            throw new ErroNegocio('Escolha a encomenda satisfeita a expedir.', 'ENCOMENDA_EM_FALTA', 422);
        }
        if (empty($d['encomenda_id']) && empty($d['cliente_id'])) {
            throw new ErroNegocio('Selecione o cliente ou a encomenda de origem.', 'CLIENTE_EM_FALTA', 422);
        }
        if (! $d['linhas']) {
            throw new ErroNegocio('Adicione pelo menos um item.', 'SEM_LINHAS', 422);
        }
        $armazem = (int) Armazem::query()->findOrFail($d['armazem_id'])->id;

        return DB::transaction(function () use ($d, $armazem) {
            $data = substr($d['data'], 0, 10);
            if (! empty($d['encomenda_id'])) {
                // a encomenda e as suas linhas ficam bloqueadas até ao fim: duas guias em simultâneo não entregam a mesma quantidade
                $ne = Venda::query()->lockForUpdate()->find($d['encomenda_id']);
                if (! $ne || $ne->tipo_documento !== 'NE') {
                    throw new ErroNegocio('A encomenda de origem não existe.', 'ENCOMENDA_INVALIDA', 422);
                }
                if ($ne->estado === 'ANULADO') {
                    throw new ErroNegocio("A encomenda {$ne->numero_documento} está anulada.", 'DOCUMENTO_ANULADO', 422);
                }
                if (! empty($d['cliente_id']) && (int) $d['cliente_id'] !== (int) $ne->cliente_id) {
                    throw new ErroNegocio("O cliente não é o da encomenda {$ne->numero_documento}.", 'CLIENTE_DIFERENTE', 422);
                }
                if ($d['tipo'] === 'BACK_TO_BACK' && ! $this->satisfeita($ne)) {
                    throw new ErroNegocio("A compra ligada à encomenda {$ne->numero_documento} ainda não foi recebida.", 'ENCOMENDA_NAO_SATISFEITA', 422);
                }
                $itens = $ne->itensVenda()->lockForUpdate()->orderBy('id')->get()->keyBy('id');
                $quantidades = [];
                foreach ($d['linhas'] as $n => $l) {
                    $item = isset($l['item_origem_id']) ? $itens->get((int) $l['item_origem_id']) : null;
                    if (! $item || (int) $item->produto_id !== (int) $l['produto_id']) {
                        throw new ErroNegocio('Linha '.($n + 1).": o artigo não pertence à encomenda {$ne->numero_documento} (emita uma guia à parte para outros artigos).",
                            'LINHA_FORA_DA_ENCOMENDA', 422, ['linha' => $n + 1]);
                    }
                    if (isset($quantidades[$item->id])) {
                        throw new ErroNegocio('Linha '.($n + 1).': a linha da encomenda está repetida.', 'LINHA_REPETIDA', 422, ['linha' => $n + 1]);
                    }
                    $quantidades[$item->id] = (float) $l['quantidade'];
                }
                $this->exigirStock($armazem, $d['linhas']);
                $gr = $this->documentos->converter($ne, 'GR', ['data_emissao' => $data, 'armazem_id' => $armazem, 'observacoes' => $d['observacoes'] ?? null,
                    'quantidades' => $quantidades]);
            } else {
                $cliente = Terceiro::query()->findOrFail($d['cliente_id']);
                $this->exigirStock($armazem, $d['linhas']);
                $gr = $this->documentos->emitir(['tipo_documento' => 'GR', 'cliente_id' => $cliente->id, 'data_emissao' => $data, 'armazem_id' => $armazem,
                    'projeto_id' => $d['projeto_id'] ?? null, 'observacoes' => $d['observacoes'] ?? null,
                    'linhas' => array_map(fn ($l) => ['produto_id' => (int) $l['produto_id'], 'quantidade' => (string) $l['quantidade']], $d['linhas'])]);
            }
            // forceFill: a coluna (migração 2026_10_16_210000) não está no $fillable gerado
            $gr->forceFill(['saida_armazem' => $d['tipo']])->save();

            return $gr->refresh();
        });
    }

    /** A compra ligada à encomenda já foi recebida (RECEBIDO/CONCLUIDO ou com uma recepção validada). */
    private function satisfeita(Venda $ne): bool
    {
        $pcs = PedidoCompra::query()->where('estado', '<>', 'ANULADO')
            ->where(fn ($q) => $q->where('venda_origem_id', $ne->id)
                ->orWhereIn('id', ItemVenda::query()->where('venda_id', $ne->id)->whereNotNull('pedido_compra_id')->select('pedido_compra_id')))
            ->pluck('id')->all();
        $ecs = EncomendaCompra::query()->where('estado', '<>', 'ANULADA')
            ->where(fn ($q) => $q->where('venda_origem_id', $ne->id)->orWhereIn('pedido_compra_id', $pcs ?: [0]))->get(['id', 'estado']);

        return $ecs->contains(fn ($e) => in_array($e->estado, ['RECEBIDO', 'CONCLUIDO'], true))
            || RececaoCompra::query()->whereIn('encomenda_compra_id', $ecs->pluck('id')->all() ?: [0])->where('estado', '<>', 'ANULADO')
                ->where(fn ($q) => $q->where('validado', true)->orWhere('estado', 'VALIDADO'))->exists();
    }

    /**
     * Quantidade disponível no armazém (saveGuiaSaida: «Quantidade indisponível no Armazém selecionado.»), verificada com os
     * produtos e o stock do armazém bloqueados — pela mesma ordem do motor de stock (produto, depois armazém) — até ao commit.
     *
     * @param  list<array{produto_id: int, quantidade: mixed}>  $linhas
     */
    private function exigirStock(int $armazem, array $linhas): void
    {
        $pedido = [];
        foreach ($linhas as $l) {
            $pedido[(int) $l['produto_id']] = bcadd($pedido[(int) $l['produto_id']] ?? '0', number_format((float) $l['quantidade'], 3, '.', ''), 3);
        }
        ksort($pedido);
        $produtos = Produto::query()->whereIn('id', array_keys($pedido))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $noArmazem = StockArmazem::query()->where('armazem_id', $armazem)->whereIn('produto_id', array_keys($pedido))->orderBy('produto_id')->lockForUpdate()
            ->pluck('quantidade_stock', 'produto_id');
        foreach ($pedido as $id => $q) {
            $p = $produtos->get($id) ?? throw new ErroNegocio('Produto inexistente.', 'PRODUTO_INEXISTENTE', 422);
            if (! $p->movimenta_stock) {
                continue;   // serviços: sem stock a verificar (a GR também não os movimenta)
            }
            $existe = number_format((float) ($noArmazem[$id] ?? 0), 3, '.', '');
            if (bccomp($existe, $q, 3) < 0) {
                throw new ErroNegocio("Quantidade indisponível no armazém selecionado: {$p->codigo} — {$p->nome} (existe {$existe}, pedido {$q}).", 'STOCK_INSUFICIENTE', 422,
                    ['produto_id' => $id, 'disponivel' => $existe]);
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function linhasPorEntregar(Collection $itens): array
    {
        return $itens->map(fn (ItemVenda $i) => [
            'id' => $i->id, 'produto_id' => $i->produto_id, 'descricao' => $i->descricao,
            'produto' => $i->produto ? ['id' => $i->produto->id, 'codigo' => $i->produto->codigo, 'nome' => $i->produto->nome] : null,
            'movimenta_stock' => (bool) $i->produto?->movimenta_stock,
            'quantidade' => (string) $i->quantidade, 'quantidade_entregue' => number_format((float) $i->quantidade_entregue, 3, '.', ''),
            'por_entregar' => number_format(max(0, (float) $i->quantidade - (float) $i->quantidade_entregue), 3, '.', ''),
        ])->values()->all();
    }
}
