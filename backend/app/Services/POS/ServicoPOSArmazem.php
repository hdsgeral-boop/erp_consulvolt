<?php

namespace App\Services\POS;

use App\Exceptions\ErroNegocio;
use App\Models\Armazem;
use App\Models\GuiaSaida;
use App\Models\ItemVenda;
use App\Models\Produto;
use App\Models\StockArmazem;
use App\Models\Terceiro;
use App\Models\Venda;
use App\Services\Logistica\ServicoGuiasSaida;
use App\Services\Vendas\ServicoDocumentosVenda;
use Illuminate\Support\Facades\DB;

/**
 * POS de armazém (js/ui_pos_armazem.js), sem duplicar a lógica de stock nem de documentos:
 *   - venda ao balcão (finalizePOSWhSale, :461-578) = guia de saída VENDA_BALCAO (ServicoGuiasSaida::emitirVendaBalcao):
 *     só a guia, sem factura nem pagamento, como no legado; stock do armazém ao custo médio e CMV no diário GS;
 *   - picking (renderPOSPickingTab/startOrderPicking/finalizeOrderPicking, :804-1120) sobre as encomendas de clientes (NE):
 *     a expedição é a conversão NE → GR das Vendas (ServicoDocumentosVenda::converter, ADR-043), ligada à encomenda,
 *     com stock e CMV da GR. Correcções: a expedição exige stock de TODAS as linhas por expedir no armazém escolhido, sob
 *     lock (o legado só desactivava a caixa no ecrã e depois cortava o stock a zero); os serviços (sem stock) não bloqueiam
 *     (no legado ficavam com stock 0 e impediam a expedição); expede-se só o que falta entregar (o legado voltava a tirar
 *     a quantidade toda); uma guia (GR) numerada pela série das Vendas em vez de «GE PICK AAAA/contagem+1» com um diário
 *     adivinhado; duas expedições simultâneas da mesma encomenda não duplicam a saída (lock na encomenda).
 *     «EM PICKING» não se grava: no legado era só um marcador posto ao abrir o ecrã e nunca retirado («Pausar» deixava-o);
 *     o estado resulta das quantidades entregues (POR_EXPEDIR / EXPEDIDA_PARCIAL) e a encomenda sai da fila quando está
 *     toda entregue. A encomenda só fica CONCLUIDA quando facturada (ADR-043), não na expedição como no legado.
 *   - acerto de stock (posWhAcertarStock, :154-214): não se porta. Espalhava a diferença entre o stock global do produto
 *     e a soma dos armazéns sem movimentos; no sistema novo o total do produto é sempre a soma dos armazéns, obtida dos
 *     movimentos (ADR-042), e as regularizações fazem-se por ajuste ou inventário (Logística).
 *   - senhas (:1130-1254): contador em memória do navegador, sem dados — fica no frontend.
 */
final class ServicoPOSArmazem
{
    public function __construct(
        private readonly ServicoGuiasSaida $guias,
        private readonly ServicoDocumentosVenda $documentos,
    ) {}

    /** @param  array{armazem_id: int, terceiro_id?: ?int, observacoes?: ?string, linhas: list<array{produto_id: int, quantidade: mixed}>}  $d */
    public function vender(array $d): GuiaSaida
    {
        Armazem::query()->findOrFail($d['armazem_id']);
        $ids = array_column($d['linhas'], 'produto_id');
        $produtos = Produto::query()->whereIn('id', $ids)->get()->keyBy('id');
        foreach ($d['linhas'] as $l) {
            $p = $produtos[$l['produto_id']] ?? throw new ErroNegocio("Produto #{$l['produto_id']} inexistente.", 'PRODUTO_INEXISTENTE', 422);
            if ($p->bloqueado) {
                throw new ErroNegocio("O produto {$p->codigo} está bloqueado.", 'PRODUTO_BLOQUEADO', 422);
            }
            if (! $p->movimenta_stock) {
                throw new ErroNegocio("O produto {$p->codigo} não é de stock: a guia de saída só leva mercadoria.", 'PRODUTO_SEM_STOCK', 422);
            }
        }

        return $this->guias->emitirVendaBalcao($d);
    }

    /**
     * Fila de picking: encomendas de clientes não anuladas nem concluídas com quantidades por entregar (o legado filtrava
     * pelo estado «Pendente»/«EM PICKING»).
     *
     * @return list<array<string, mixed>>
     */
    public function fila(): array
    {
        $encomendas = Venda::query()->where('tipo_documento', 'NE')->where(fn ($q) => $q->whereNull('estado')->orWhereNotIn('estado', ['ANULADO', 'CONCLUIDO']))
            ->with('itensVenda')->orderBy('data_emissao')->orderBy('id')->get();
        $clientes = Terceiro::query()->withTrashed()->whereIn('id', $encomendas->pluck('cliente_id')->filter()->unique())->pluck('nome', 'id');
        $fila = [];
        foreach ($encomendas as $e) {
            $porEntregar = $e->itensVenda->filter(fn ($i) => $this->porExpedir($i) !== '0.000');
            if ($porEntregar->isEmpty()) {
                continue;
            }
            $parcial = $e->itensVenda->contains(fn ($i) => (float) $i->quantidade_entregue > 0);
            $fila[] = ['id' => $e->id, 'numero_documento' => $e->numero_documento, 'data_emissao' => $e->data_emissao?->toDateString(), 'cliente_id' => $e->cliente_id,
                'cliente' => $clientes[$e->cliente_id] ?? null, 'total_bruto' => (string) $e->total_bruto, 'estado' => $e->estado,
                'estado_picking' => $parcial ? 'EXPEDIDA_PARCIAL' : 'POR_EXPEDIR', 'linhas_por_expedir' => $porEntregar->count()];
        }

        return $fila;
    }

    /**
     * Lista de recolha (recalculatePickingStocks, :949-990): por linha, o que falta expedir e o stock do armazém escolhido.
     *
     * @return array{encomenda: array<string, mixed>, armazem_id: int, linhas: list<array<string, mixed>>, pode_expedir: bool}
     */
    public function lista(Venda $ne, int $armazem, bool $bloquear = false): array
    {
        $this->exigirEncomenda($ne);
        Armazem::query()->findOrFail($armazem);
        $itens = $ne->itensVenda()->orderBy('id')->get();
        $produtos = Produto::query()->withTrashed()->whereIn('id', $itens->pluck('produto_id'))->get()->keyBy('id');
        $pedido = [];
        foreach ($itens as $i) {
            $pedido[$i->produto_id] = bcadd($pedido[$i->produto_id] ?? '0', $this->porExpedir($i), 3);
        }
        $saldos = [];
        foreach (array_keys($pedido) as $pid) {
            $q = StockArmazem::query()->where('armazem_id', $armazem)->where('produto_id', $pid);
            $saldos[$pid] = (string) (($bloquear ? $q->lockForUpdate() : $q)->value('quantidade_stock') ?? '0');
        }
        $linhas = [];
        foreach ($itens as $i) {
            $p = $produtos[$i->produto_id] ?? null;
            $stock = (bool) $p?->movimenta_stock;
            $falta = $this->porExpedir($i);
            $linhas[] = ['item_id' => $i->id, 'produto_id' => $i->produto_id, 'codigo' => $p?->codigo, 'nome' => $p?->nome ?? $i->descricao,
                'quantidade' => number_format((float) $i->quantidade, 3, '.', ''), 'quantidade_entregue' => number_format((float) $i->quantidade_entregue, 3, '.', ''),
                'por_expedir' => $falta, 'movimenta_stock' => $stock, 'stock_armazem' => $stock ? $saldos[$i->produto_id] : null,
                'disponivel' => $falta === '0.000' || ! $stock || bccomp($saldos[$i->produto_id], $pedido[$i->produto_id], 3) >= 0];
        }
        $pendentes = array_filter($linhas, fn ($l) => $l['por_expedir'] !== '0.000');

        return ['encomenda' => $ne->only(['id', 'numero_documento', 'cliente_id', 'estado', 'total_bruto']), 'armazem_id' => $armazem, 'linhas' => $linhas,
            'pode_expedir' => $pendentes && ! array_filter($pendentes, fn ($l) => ! $l['disponivel'])];
    }

    /** Expedição (finalizeOrderPicking, :1017-1120): GR da encomenda com tudo o que falta entregar, a partir do armazém escolhido. */
    public function expedir(Venda $ne, int $armazem, ?string $observacoes = null): Venda
    {
        return DB::transaction(function () use ($ne, $armazem, $observacoes) {
            $ne = Venda::query()->lockForUpdate()->findOrFail($ne->id);   // duas expedições da mesma encomenda não saem as duas
            $lista = $this->lista($ne, $armazem, true);
            $faltam = array_values(array_filter($lista['linhas'], fn ($l) => ! $l['disponivel']));
            if ($faltam) {
                throw new ErroNegocio('Stock insuficiente no armazém escolhido: '.implode(', ', array_map(fn ($l) => "{$l['codigo']} (disponível {$l['stock_armazem']}, por expedir {$l['por_expedir']})", $faltam)).'.',
                    'STOCK_INSUFICIENTE', 422, ['linhas' => array_map(fn ($l) => array_intersect_key($l, array_flip(['produto_id', 'codigo', 'stock_armazem', 'por_expedir'])), $faltam)]);
            }
            if (! $lista['pode_expedir']) {
                throw new ErroNegocio('A encomenda já foi toda expedida.', 'JA_CONVERTIDO', 422);
            }

            return $this->documentos->converter($ne, 'GR', ['armazem_id' => $armazem, 'observacoes' => $observacoes ?? "Picking da encomenda {$ne->numero_documento}"]);
        });
    }

    private function exigirEncomenda(Venda $ne): void
    {
        if ($ne->tipo_documento !== 'NE') {
            throw new ErroNegocio('O picking faz-se sobre encomendas de clientes (NE).', 'NAO_E_ENCOMENDA', 422);
        }
        if (in_array($ne->estado, ['ANULADO', 'CONCLUIDO'], true)) {
            throw new ErroNegocio("A encomenda {$ne->numero_documento} está ".($ne->estado === 'ANULADO' ? 'anulada' : 'concluída').'.', 'ENCOMENDA_FECHADA', 422);
        }
    }

    private function porExpedir(ItemVenda $i): string
    {
        $falta = bcsub(number_format((float) $i->quantidade, 3, '.', ''), number_format((float) $i->quantidade_entregue, 3, '.', ''), 3);

        return bccomp($falta, '0', 3) > 0 ? $falta : '0.000';
    }
}
