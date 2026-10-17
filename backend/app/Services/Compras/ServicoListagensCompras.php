<?php

namespace App\Services\Compras;

use App\Models\CentroCusto;
use App\Models\ContratoFornecedor;
use App\Models\CotacaoCompra;
use App\Models\EncomendaCompra;
use App\Models\FaturaCompra;
use App\Models\ItemCompra;
use App\Models\ItemVenda;
use App\Models\PedidoCompra;
use App\Models\RececaoCompra;
use App\Models\Terceiro;
use App\Models\UnidadeNegocio;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Colunas das listas de Compras do legado (renderCompras, js/ui_compras_v2.js:116-847) que não estão na tabela do documento:
 * «UNº / CC» (códigos da unidade de negócio e do centro de custo), «Itens / Qtd» dos pedidos, «Satisfação (E/F)» e contrato
 * das encomendas, fornecedor e n.º da encomenda das recepções e facturas, encomendas associadas e total encomendado dos
 * contratos, e se o utilizador pode decidir o pedido. Tudo em consultas por lote sobre a página carregada (sem N+1), só de
 * leitura; os campos são aditivos (os existentes mantêm-se).
 *
 * Também a «Cadeia de Documentos» do «Fluxo Documental»/«Ver Fluxo» (showPurchaseWorkflow, :4182-4365): pedido → propostas →
 * encomenda → recepções → facturas e os lançamentos contabilísticos das recepções e facturas contabilizadas.
 */
final class ServicoListagensCompras
{
    public function __construct(private readonly ContextoEmpresa $contexto) {}

    /** @param  Collection<int, Model>  $itens */
    public function dimensoes(Collection $itens): void
    {
        $un = UnidadeNegocio::query()->whereIn('id', $itens->pluck('unidade_negocio_id')->filter()->unique())->pluck('codigo', 'id');
        $cc = CentroCusto::query()->whereIn('id', $itens->pluck('centro_custo_id')->filter()->unique())->pluck('codigo', 'id');
        foreach ($itens as $m) {
            $m->setAttribute('unidade_negocio_codigo', $m->unidade_negocio_id ? ($un[$m->unidade_negocio_id] ?? null) : null);
            $m->setAttribute('centro_custo_codigo', $m->centro_custo_id ? ($cc[$m->centro_custo_id] ?? null) : null);
        }
    }

    /** Pedidos: resumo «Produto (qtd)» das linhas, n.º de linhas e quantidade total; `pode_decidir` para os pendentes. */
    public function pedidos(Collection $pedidos): void
    {
        $this->dimensoes($pedidos);
        $linhas = ItemCompra::query()->whereIn('pedido_compra_id', $pedidos->pluck('id'))
            ->whereNull('cotacao_compra_id')->whereNull('encomenda_compra_id')->whereNull('fatura_compra_id')
            ->with(RelacoesNomes::produto())->orderBy('id')->get()->groupBy('pedido_compra_id');
        $deliberacao = app(ServicoDeliberacaoCompras::class);
        $eu = $pedidos->contains('estado', 'PENDENTE') ? $deliberacao->meuColaborador() : null;   // uma leitura por página
        foreach ($pedidos as $p) {
            $ls = $linhas[$p->id] ?? collect();
            $p->setAttribute('itens_resumo', $ls->map(fn ($l) => ($l->produto?->nome ?? $l->descricao ?? 'Item').' ('.self::qtd($l->quantidade).')')->implode(', '));
            $p->setAttribute('itens_linhas', $ls->count());
            $p->setAttribute('itens_quantidade', self::qtd($ls->sum(fn ($l) => (float) $l->quantidade)));
            $p->setAttribute('pode_decidir', $p->estado === 'PENDENTE' && $deliberacao->podeDecidir($p, $eu)['pode']);
        }
    }

    /** Encomendas: % entregue e % facturada (Σ quantidades das linhas) e a referência do contrato. */
    public function encomendas(Collection $encomendas): void
    {
        $this->dimensoes($encomendas);
        $ids = $encomendas->pluck('id');
        $somas = ItemCompra::query()->whereIn('encomenda_compra_id', $ids)->where('tipo_documento_origem', 'ENCOMENDA')
            ->groupBy('encomenda_compra_id')
            ->selectRaw('encomenda_compra_id, SUM(quantidade) AS q, SUM(COALESCE(quantidade_recebida, 0)) AS r, SUM(COALESCE(quantidade_faturada, 0)) AS f')
            ->get()->keyBy('encomenda_compra_id');
        $ligacoes = DB::table('contratos_fornecedores_encomendas')->where('empresa_id', $this->contexto->obrigatorio())->whereIn('encomenda_compra_id', $ids)
            ->pluck('contrato_fornecedor_id', 'encomenda_compra_id');
        $contratos = ContratoFornecedor::query()->whereIn('id', $ligacoes->values()->merge($encomendas->pluck('contrato_fornecedor_id'))->filter()->unique())
            ->get(['id', 'referencia', 'estado'])->keyBy('id');
        foreach ($encomendas as $e) {
            $s = $somas[$e->id] ?? null;
            $q = (float) ($s->q ?? 0);
            $e->setAttribute('percentagem_entregue', $q > 0 ? round(min(100, (float) $s->r / $q * 100), 1) : 0.0);
            $e->setAttribute('percentagem_faturada', $q > 0 ? round(min(100, (float) $s->f / $q * 100), 1) : 0.0);
            $c = $contratos[$ligacoes[$e->id] ?? $e->contrato_fornecedor_id] ?? null;
            $e->setAttribute('contrato', $c ? ['id' => $c->id, 'referencia' => $c->referencia, 'estado' => $c->estado] : null);
        }
    }

    /** Recepções: n.º da encomenda e fornecedor (da encomenda). */
    public function rececoes(Collection $rececoes): void
    {
        $this->dimensoes($rececoes);
        $encs = EncomendaCompra::query()->whereIn('id', $rececoes->pluck('encomenda_compra_id')->filter()->unique())->get(['id', 'numero_encomenda', 'fornecedor_id'])->keyBy('id');
        $forn = Terceiro::query()->withTrashed()->whereIn('id', $encs->pluck('fornecedor_id')->filter()->unique())->get(['id', 'nome', 'nif'])->keyBy('id');
        foreach ($rececoes as $r) {
            $e = $encs[$r->encomenda_compra_id] ?? null;
            $f = $e ? ($forn[$e->fornecedor_id] ?? null) : null;
            $r->setAttribute('numero_encomenda', $e?->numero_encomenda);
            $r->setAttribute('fornecedor', $f ? ['id' => $f->id, 'nome' => $f->nome, 'nif' => $f->nif] : null);
        }
    }

    /** Facturas: n.º da encomenda de origem. */
    public function faturas(Collection $faturas): void
    {
        $this->dimensoes($faturas);
        $encs = EncomendaCompra::query()->whereIn('id', $faturas->pluck('encomenda_compra_id')->filter()->unique())->pluck('numero_encomenda', 'id');
        foreach ($faturas as $f) {
            $f->setAttribute('numero_encomenda', $f->encomenda_compra_id ? ($encs[$f->encomenda_compra_id] ?? null) : null);
        }
    }

    /** Contratos: encomendas associadas (não anuladas), total encomendado (sem IVA) e % do valor previsto. */
    public function contratos(Collection $contratos): void
    {
        $ligacoes = DB::table('contratos_fornecedores_encomendas')->where('empresa_id', $this->contexto->obrigatorio())
            ->whereIn('contrato_fornecedor_id', $contratos->pluck('id'))->get(['contrato_fornecedor_id', 'encomenda_compra_id'])->groupBy('contrato_fornecedor_id');
        $encs = EncomendaCompra::query()->whereIn('id', $ligacoes->flatten(1)->pluck('encomenda_compra_id')->unique())
            ->where(fn ($q) => $q->whereNull('estado')->orWhere('estado', '<>', 'ANULADA'))->orderBy('data')
            ->get(['id', 'numero_encomenda', 'estado', 'montante_total'])->keyBy('id');
        foreach ($contratos as $c) {
            $lista = ($ligacoes[$c->id] ?? collect())->map(fn ($l) => $encs[$l->encomenda_compra_id] ?? null)->filter()->values();
            $total = number_format((float) $lista->sum(fn ($e) => (float) $e->montante_total), 2, '.', '');
            $c->setAttribute('encomendas', $lista->map(fn ($e) => ['id' => $e->id, 'numero_encomenda' => $e->numero_encomenda, 'estado' => $e->estado, 'montante_total' => $e->montante_total])->all());
            $c->setAttribute('total_encomendado', $total);
            $c->setAttribute('percentagem_encomendada', (float) $c->valor_total > 0 ? (int) round((float) $total / (float) $c->valor_total * 100) : null);
        }
    }

    /**
     * Cadeia de documentos do processo a que pertence o documento (tipo: pedido|proposta|encomenda|rececao|fatura).
     *
     * @return array<string, mixed>
     */
    public function fluxoDocumental(string $tipo, int $id): array
    {
        $pedido = $proposta = $encomenda = null;
        $rececoes = collect();
        $faturas = collect();
        switch ($tipo) {
            case 'pedido':
                $pedido = PedidoCompra::query()->findOrFail($id);
                break;
            case 'proposta':
                $proposta = CotacaoCompra::query()->findOrFail($id);
                $encomenda = EncomendaCompra::query()->where('cotacao_compra_id', $proposta->id)->orderByDesc('id')->first();
                break;
            case 'encomenda':
                $encomenda = EncomendaCompra::query()->findOrFail($id);
                break;
            case 'rececao':
                $r = RececaoCompra::query()->findOrFail($id);
                $encomenda = $r->encomenda_compra_id ? EncomendaCompra::query()->find($r->encomenda_compra_id) : null;
                $rececoes = collect([$r]);
                break;
            case 'fatura':
                $f = FaturaCompra::query()->findOrFail($id);
                $encomenda = $f->encomenda_compra_id ? EncomendaCompra::query()->find($f->encomenda_compra_id) : null;
                $faturas = collect([$f]);
                break;
        }
        if ($encomenda && ! $proposta && $encomenda->cotacao_compra_id) {
            $proposta = CotacaoCompra::query()->find($encomenda->cotacao_compra_id);
        }
        $pedidoId = $pedido?->id ?? $proposta?->pedido_compra_id ?? $encomenda?->pedido_compra_id;
        $pedido ??= $pedidoId ? PedidoCompra::query()->find($pedidoId) : null;
        if ($pedido && ! $encomenda) {
            $encomenda = EncomendaCompra::query()->where('pedido_compra_id', $pedido->id)->where(fn ($q) => $q->whereNull('estado')->orWhere('estado', '<>', 'ANULADA'))
                ->orderByDesc('id')->first();
        }
        $propostas = $pedido ? CotacaoCompra::query()->where('pedido_compra_id', $pedido->id)->with(RelacoesNomes::fornecedor())->orderBy('id')->get()
            : collect($proposta ? [$proposta->load(RelacoesNomes::fornecedor())] : []);
        if ($encomenda) {
            $encomenda->load(RelacoesNomes::fornecedor());
            $rececoes = RececaoCompra::query()->where('encomenda_compra_id', $encomenda->id)->orderBy('data')->orderBy('id')->get();
            $faturas = FaturaCompra::query()->where('encomenda_compra_id', $encomenda->id)->orderBy('data')->orderBy('id')->get();
        }
        $faturas->each(fn ($f) => $f->loadMissing(RelacoesNomes::fornecedor()));

        return [
            'pedido' => $pedido?->only(['id', 'numero_pedido', 'data', 'estado', 'nome_requerente']),
            'propostas' => $propostas->map(fn ($p) => $p->only(['id', 'numero_proposta', 'referencia', 'data', 'estado', 'montante_total', 'codigo_moeda']) + ['fornecedor' => $p->fornecedor?->only(['id', 'nome'])])->values(),
            'encomenda' => $encomenda ? $encomenda->only(['id', 'numero_encomenda', 'data', 'estado', 'montante_total']) + ['fornecedor' => $encomenda->fornecedor?->only(['id', 'nome'])] : null,
            'rececoes' => $rececoes->map(fn ($r) => $r->only(['id', 'numero_rececao', 'numero_entrega', 'data', 'estado', 'validado', 'contabilizado', 'numero_lan_contabilizacao', 'valor_total_kz']))->values(),
            'faturas' => $faturas->map(fn ($f) => $f->only(['id', 'numero_fatura', 'data', 'estado', 'contabilizado', 'numero_lan_contabilizacao', 'montante_total', 'total_imposto']))->values(),
            'lancamentos' => $this->lancamentos($rececoes, $faturas),
        ];
    }

    /** Linhas dos lançamentos das recepções e facturas contabilizadas (2. Integração Contabilística), se o perfil as puder ver. */
    private function lancamentos(Collection $rececoes, Collection $faturas): array
    {
        if (! Gate::any(['lancamentos_view', 'compras_faturacao_view', 'compras_fact_contabilizar', 'armazem_rececoes_view'])) {
            return [];
        }
        $docs = $rececoes->filter(fn ($r) => $r->contabilizado && $r->numero_lan_contabilizacao)
            ->map(fn ($r) => ['documento' => $r->numero_rececao ?: ($r->numero_entrega ?: "#{$r->id}"), 'numero_lan' => (string) $r->numero_lan_contabilizacao])
            ->merge($faturas->filter(fn ($f) => $f->contabilizado && $f->numero_lan_contabilizacao)
                ->map(fn ($f) => ['documento' => $f->numero_fatura, 'numero_lan' => (string) $f->numero_lan_contabilizacao]))->values();
        if ($docs->isEmpty()) {
            return [];
        }
        $linhas = DB::table('lancamentos_contabeis')->where('empresa_id', $this->contexto->obrigatorio())->whereIn('numero_lan', $docs->pluck('numero_lan')->unique())
            ->orderBy('id')->get(['numero_lan', 'codigo_conta', 'tipo_dc', 'valor'])->groupBy('numero_lan');

        return $docs->map(fn ($d) => $d + ['linhas' => ($linhas[$d['numero_lan']] ?? collect())
            ->map(fn ($l) => ['codigo_conta' => trim((string) $l->codigo_conta), 'tipo_dc' => $l->tipo_dc, 'valor' => number_format((float) $l->valor, 2, '.', '')])->values()->all()])
            ->all();
    }

    /**
     * Preço de referência por artigo vindo das encomendas de clientes (NE) que deram origem ao pedido
     * (itens_venda.pedido_compra_id): preço unitário em Kz, sem IVA e já com o desconto da linha, médio ponderado pela
     * quantidade. Serve o aviso da proposta de fornecedor (custo ≥ 70 % ou acima do valor da encomenda). Só para quem
     * regista propostas ou vê as vendas (o preço de venda não é exposto a outros perfis).
     *
     * @return array<int, array{preco_kz: string, documentos: list<string>}> indexado pelo produto_id
     */
    public function referenciaEncomendas(PedidoCompra $pedido): array
    {
        if (! Gate::any(['compras_new_proposal', 'vendas_faturacao_view'])) {
            return [];
        }
        $linhas = ItemVenda::query()->where('pedido_compra_id', $pedido->id)->whereNotNull('produto_id')
            ->whereHas('venda', fn ($q) => $q->where('tipo_documento', 'NE')->where('estado', '<>', 'ANULADO'))
            ->with('venda:id,numero_documento')->get(['id', 'venda_id', 'produto_id', 'quantidade', 'preco_unitario', 'percentagem_desconto']);

        return $linhas->groupBy('produto_id')->map(function (Collection $g) {
            $qtd = $g->sum(fn ($l) => (float) $l->quantidade);
            $valor = $g->sum(fn ($l) => (float) $l->quantidade * (float) $l->preco_unitario * (1 - (float) $l->percentagem_desconto / 100));

            return ['preco_kz' => number_format($qtd > 0 ? $valor / $qtd : 0, 2, '.', ''),
                'documentos' => $g->pluck('venda.numero_documento')->filter()->unique()->values()->all()];
        })->all();
    }

    private static function qtd(mixed $v): string
    {
        $t = rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.');

        return $t === '' ? '0' : $t;
    }
}
