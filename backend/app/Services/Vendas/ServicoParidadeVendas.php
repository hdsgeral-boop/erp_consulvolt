<?php

namespace App\Services\Vendas;

use App\Exceptions\ErroNegocio;
use App\Models\ItemVenda;
use App\Models\MeioPagamento;
use App\Models\PedidoCompra;
use App\Models\Produto;
use App\Models\Terceiro;
use App\Models\Venda;
use App\Services\Compras\ServicoEncomendasClientes;
use App\Services\Logistica\ServicoNiveisStock;
use App\Services\Logistica\ServicoProdutos;
use App\Services\Sistema\ServicoContasCategoriaMassa;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Paridade de Vendas com o sistema anterior (verificacao_visual/RELATORIO.md, secção 4): indicadores do cabeçalho
 * «Gestão de Vendas», contadores dos separadores da Facturação, alertas de stock baixo do Dashboard, análise prévia do
 * SAF-T, meios de pagamento do formulário, guias de saída por facturar, «Forçar limpeza de estado», «Converter em
 * Pedido de Compra» e a edição em massa de produtos. Regras de negócio aqui; os controladores só validam e autorizam.
 *
 * Pendentes e saldo a receber usam o ESTADO do documento (e o pendente gravado), não só valor_pago: as FR do POS
 * migradas têm valor_pago 0 com estado PAGO e não estão por receber.
 */
final class ServicoParidadeVendas
{
    /** Separadores da Facturação (renderFaturaçãoTab do legado) → tipos de documento. */
    public const GRUPOS = ['orcamentos' => ['OR', 'PF'], 'encomendas' => ['NE'], 'faturas' => ['FT', 'FR', 'NC', 'ND'], 'guias' => ['GR', 'GD']];

    /**
     * Saldo por receber de um documento (alias «v»): o pendente gravado ou, quando falta (documentos migrados sem
     * pendente), o bruto menos o pago. Partilhado pelo cabeçalho e pelo Dashboard de Vendas (4.0.2/4.3.3).
     */
    public const SQL_SALDO = 'COALESCE(v.valor_pendente, v.total_bruto - COALESCE(v.valor_pago, 0), 0)';

    /** Facturas (FT/FR) por receber: pelo ESTADO (as FR do POS migradas têm valor_pago 0 e estado PAGO) e com saldo. */
    public const SQL_POR_RECEBER = "v.tipo_documento IN ('FT', 'FR') AND COALESCE(v.estado, '') NOT IN ('PAGO', 'ANULADO', 'CONCLUIDO') AND ".self::SQL_SALDO.' > 0';

    /** Produtos SEM níveis de stock definidos: abaixo disto aparecem em «Alertas de Stock Baixo» (legado: < 5). */
    public const LIMITE_STOCK_BAIXO = 5;

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoEstadoVenda $estado,
    ) {}

    /**
     * Cabeçalho do agregador (updateSalesSummaryHeader do legado): n.º de facturas pendentes, saldo a receber (todas as
     * datas) e facturado no mês corrente (FT + FR, com IVA).
     *
     * @return array{pendentes: int, a_receber: string, mensal: string, mes: string}
     */
    public function cabecalho(?string $hoje = null): array
    {
        $empresa = $this->contexto->obrigatorio();
        $hoje ??= now()->toDateString();
        $inicioMes = substr($hoje, 0, 7).'-01';
        $fimMes = date('Y-m-d', strtotime("{$inicioMes} +1 month"));
        $pendente = self::SQL_SALDO;
        $porReceber = self::SQL_POR_RECEBER;

        $r = DB::table('vendas as v')->where('v.empresa_id', $empresa)->selectRaw(
            "COUNT(*) FILTER (WHERE {$porReceber}) AS pendentes,
             COALESCE(SUM({$pendente}) FILTER (WHERE {$porReceber}), 0) AS a_receber,
             COALESCE(SUM(COALESCE(v.total_bruto, 0)) FILTER (WHERE v.tipo_documento IN ('FT', 'FR') AND COALESCE(v.estado, '') <> 'ANULADO'
                 AND v.data_emissao >= ? AND v.data_emissao < ?), 0) AS mensal", [$inicioMes, $fimMes]
        )->first();

        return ['pendentes' => (int) $r->pendentes, 'a_receber' => self::dec($r->a_receber), 'mensal' => self::dec($r->mensal), 'mes' => substr($hoje, 0, 7)];
    }

    /**
     * Contadores dos subseparadores da Facturação (distintivo «Faturas / N.CRÉDITO 36» do legado) e dos recibos.
     *
     * @return array<string, int>
     */
    public function contagens(): array
    {
        $empresa = $this->contexto->obrigatorio();
        $porTipo = DB::table('vendas')->where('empresa_id', $empresa)->groupBy('tipo_documento')
            ->selectRaw('tipo_documento, COUNT(*) AS n')->pluck('n', 'tipo_documento');
        $saida = [];
        foreach (self::GRUPOS as $grupo => $tipos) {
            $saida[$grupo] = (int) collect($tipos)->sum(fn ($t) => (int) ($porTipo[$t] ?? 0));
        }
        $saida['recibos'] = DB::table('recibos_venda')->where('empresa_id', $empresa)->count();

        return $saida;
    }

    /**
     * «Alertas de Stock Baixo» do Dashboard de Vendas. Ronda 3: os produtos com níveis de stock definidos seguem as regras
     * de ServicoNiveisStock (CRITICO — abaixo do mínimo/segurança; REPOSICAO — no ponto de encomenda, com a quantidade
     * sugerida até ao máximo); os que não têm níveis mantêm a regra do legado (stock somado < 5 un.).
     *
     * @return list<array{id: int, codigo: string|null, nome: string|null, stock: string, minimo: string, estado: string, motivo: string|null, quantidade_sugerida: string|null}>
     */
    public function alertasStock(int $limite = 20): array
    {
        $empresa = $this->contexto->obrigatorio();
        $niveis = array_values(array_filter(app(ServicoNiveisStock::class)->linhas(), fn ($l) => $l['estado'] !== ServicoNiveisStock::EXCESSO));
        $alertas = array_map(fn ($l) => ['id' => $l['produto_id'], 'codigo' => $l['codigo'], 'nome' => $l['nome'], 'stock' => $l['stock'],
            'minimo' => $l['stock_minimo'] ?? $l['stock_seguranca'] ?? $l['ponto_encomenda'] ?? '0.000', 'estado' => $l['estado'], 'motivo' => $l['motivo'],
            'quantidade_sugerida' => $l['quantidade_sugerida']], $niveis);

        $stock = DB::table('stock_armazem')->where('empresa_id', $empresa)->groupBy('produto_id')
            ->selectRaw('produto_id, SUM(COALESCE(quantidade_stock, 0)) AS q');
        $legado = DB::table('produtos as p')->where('p.empresa_id', $empresa)->whereNull('p.eliminado_em')
            ->where('p.movimenta_stock', true)->where(fn ($q) => $q->whereNull('p.bloqueado')->orWhere('p.bloqueado', false))
            ->where(fn ($q) => $q->whereNull('p.stock_minimo')->orWhere('p.stock_minimo', '<=', 0))
            ->where(fn ($q) => $q->whereNull('p.stock_seguranca')->orWhere('p.stock_seguranca', '<=', 0))
            ->where(fn ($q) => $q->whereNull('p.ponto_encomenda')->orWhere('p.ponto_encomenda', '<=', 0))
            ->where(fn ($q) => $q->whereNull('p.stock_maximo')->orWhere('p.stock_maximo', '<=', 0))
            ->leftJoinSub($stock, 's', 's.produto_id', '=', 'p.id')
            ->whereRaw('COALESCE(s.q, 0) < ?', [self::LIMITE_STOCK_BAIXO])
            ->orderByRaw('COALESCE(s.q, 0)')->ordenarPorNome('p.nome')->limit($limite)
            ->get(['p.id', 'p.codigo', 'p.nome', DB::raw('COALESCE(s.q, 0) AS stock')])
            ->map(fn ($p) => ['id' => (int) $p->id, 'codigo' => $p->codigo, 'nome' => $p->nome, 'stock' => number_format((float) $p->stock, 3, '.', ''),
                'minimo' => number_format(self::LIMITE_STOCK_BAIXO, 3, '.', ''), 'estado' => 'STOCK_BAIXO', 'motivo' => 'Stock baixo (sem níveis definidos: < 5 un.)',
                'quantidade_sugerida' => null])
            ->all();

        $todos = array_merge($alertas, $legado);
        usort($todos, fn ($a, $b) => [(float) $a['stock'], (string) $a['nome']] <=> [(float) $b['stock'], (string) $b['nome']]);

        return array_slice($todos, 0, $limite);
    }

    /**
     * Coluna «STOCK» do catálogo de produtos (legado: products.stock_qty): stock actual somado de todos os armazéns.
     *
     * @return array<int, string> produto_id => quantidade
     */
    public function stockPorProduto(): array
    {
        return DB::table('stock_armazem')->where('empresa_id', $this->contexto->obrigatorio())->whereNotNull('produto_id')->groupBy('produto_id')
            ->selectRaw('produto_id, SUM(COALESCE(quantidade_stock, 0)) AS q')->pluck('q', 'produto_id')
            ->map(fn ($q) => number_format((float) $q, 3, '.', ''))->all();
    }

    /**
     * «Analisar Registos» do ecrã SAFT-AO (previewSAFTData do legado): sumário do lote fiscal (FT/FR menos NC, não
     * anulados) e os dados detalhados por linha de artigo, com a data e o meio do último pagamento.
     *
     * @return array{periodo: array{inicio: string, fim: string}, sumario: array<string, mixed>, linhas: list<array<string, mixed>>}
     */
    public function analiseSaft(string $inicio, string $fim): array
    {
        if ($inicio > $fim) {
            throw new ErroNegocio('A data de início não pode ser posterior à data de fim.', 'PERIODO_INVALIDO', 422);
        }
        $empresa = $this->contexto->obrigatorio();
        $fimExclusivo = date('Y-m-d', strtotime("{$fim} +1 day"));
        $docs = DB::table('vendas as v')->where('v.empresa_id', $empresa)->whereIn('v.tipo_documento', Venda::FISCAIS)
            ->where('v.data_emissao', '>=', $inicio)->where('v.data_emissao', '<', $fimExclusivo)
            ->where(fn ($q) => $q->whereNull('v.estado')->orWhere('v.estado', '<>', 'ANULADO'))
            ->leftJoin('terceiros as t', 't.id', '=', 'v.cliente_id')
            ->orderBy('v.data_emissao')->orderBy('v.id')
            ->get(['v.id', 'v.tipo_documento', 'v.numero_documento', 'v.data_emissao', 'v.cliente_id', 't.nome as cliente', 'v.total_liquido', 'v.total_imposto',
                'v.total_bruto', 'v.meio_pagamento']);
        $ids = $docs->pluck('id')->all();

        // último pagamento por documento (recibos não anulados); a FR é paga no acto
        $pagamentos = $ids ? DB::table('itens_recibo_venda as i')->join('recibos_venda as r', 'r.id', '=', 'i.recibo_venda_id')
            ->where('r.empresa_id', $empresa)->whereIn('i.venda_id', $ids)
            ->where(fn ($q) => $q->whereNull('r.estado')->orWhere('r.estado', '<>', 'ANULADO'))->whereNull('r.anulado_em')
            ->orderBy('r.data')->get(['i.venda_id', 'r.data', 'r.meio_pagamento'])->keyBy('venda_id') : collect();
        $itens = $ids ? DB::table('itens_venda as i')->leftJoin('produtos as p', 'p.id', '=', 'i.produto_id')
            ->whereIn('i.venda_id', $ids)->orderBy('i.id')
            ->get(['i.venda_id', 'i.produto_id', 'i.descricao', 'p.codigo', 'p.nome', 'i.quantidade', 'i.preco_unitario', 'i.taxa_imposto'])
            ->groupBy('venda_id') : collect();

        $liquido = $imposto = $bruto = '0.00';
        $faturas = $notas = 0;
        $linhas = [];
        foreach ($docs as $d) {
            $sinal = $d->tipo_documento === 'NC' ? '-1' : '1';
            $d->tipo_documento === 'NC' ? $notas++ : $faturas++;
            $liquido = bcadd($liquido, bcmul((string) ($d->total_liquido ?? 0), $sinal, 2), 2);
            $imposto = bcadd($imposto, bcmul((string) ($d->total_imposto ?? 0), $sinal, 2), 2);
            $bruto = bcadd($bruto, bcmul((string) ($d->total_bruto ?? 0), $sinal, 2), 2);
            $pag = $pagamentos[$d->id] ?? null;
            $dataPag = $d->tipo_documento === 'FR' ? substr((string) $d->data_emissao, 0, 10) : ($pag ? substr((string) $pag->data, 0, 10) : null);
            $meioPag = $d->tipo_documento === 'FR' ? ($d->meio_pagamento ?: ($pag->meio_pagamento ?? null)) : ($pag->meio_pagamento ?? null);
            foreach ($itens[$d->id] ?? [] as $i) {
                $preco = (float) $i->preco_unitario;
                $linhas[] = [
                    'venda_id' => (int) $d->id, 'data' => substr((string) $d->data_emissao, 0, 10), 'tipo_documento' => $d->tipo_documento,
                    'documento' => trim("{$d->tipo_documento} {$d->numero_documento}"), 'cliente' => $d->cliente ?? 'Consumidor final',
                    'artigo' => trim(($i->codigo ? "{$i->codigo} — " : '').($i->nome ?? $i->descricao ?? '')),
                    'quantidade' => number_format((float) $i->quantidade, 3, '.', ''),
                    'preco_sem_iva' => self::dec($preco), 'preco_com_iva' => self::dec($preco * (1 + (float) $i->taxa_imposto / 100)),
                    'doc_liquido' => self::dec($d->total_liquido), 'doc_iva' => self::dec($d->total_imposto), 'doc_bruto' => self::dec($d->total_bruto),
                    'data_pagamento' => $dataPag, 'meio_pagamento' => $meioPag,
                ];
            }
        }

        return [
            'periodo' => ['inicio' => $inicio, 'fim' => $fim],
            'sumario' => [
                'faturas' => $faturas, 'notas_credito' => $notas,
                'clientes' => $docs->pluck('cliente_id')->filter()->unique()->count(),
                'artigos' => $itens->flatten(1)->pluck('produto_id')->filter()->unique()->count(),
                'total_liquido' => $liquido, 'total_imposto' => $imposto, 'total_bruto' => $bruto,
            ],
            'linhas' => $linhas,
        ];
    }

    /**
     * Meios de pagamento activos da Tesouraria para o quadro «Meios de Pagamento» do formulário de venda.
     *
     * @return list<array{id: int, nome: string|null, codigo_conta: string|null, iban: string|null, swift: string|null, predefinido: bool, codigo_moeda: string|null}>
     */
    public function meiosPagamento(): array
    {
        return MeioPagamento::query()->where(fn ($q) => $q->whereNull('ativo')->orWhere('ativo', true))->orderByDesc('predefinido')->ordenarPorNome()
            ->get(['id', 'nome', 'codigo_conta', 'iban', 'swift', 'predefinido', 'codigo_moeda'])
            ->map(fn ($m) => ['id' => (int) $m->id, 'nome' => $m->nome, 'codigo_conta' => $m->codigo_conta, 'iban' => $m->iban, 'swift' => $m->swift,
                'predefinido' => (bool) $m->predefinido, 'codigo_moeda' => $m->codigo_moeda])
            ->values()->all();
    }

    /**
     * Contas dos meios de pagamento escolhidas no formulário → cópia gravada no documento (os dados bancários vêm da
     * Tesouraria, nunca do pedido — o cliente não pode pôr no documento um IBAN que a empresa não tem).
     *
     * @param  list<int>  $ids
     * @return list<array{meio_pagamento_id: int, nome: string|null, codigo_conta: string|null, iban: string|null, swift: string|null}>
     */
    public function contasPagamento(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (! $ids) {
            return [];
        }
        $meios = MeioPagamento::query()->whereIn('id', $ids)->get()->keyBy('id');
        $saida = [];
        foreach ($ids as $id) {
            $m = $meios[$id] ?? throw new ErroNegocio('Meio de pagamento inexistente.', 'MEIO_PAGAMENTO_INVALIDO', 422);
            if ($m->ativo === false) {
                throw new ErroNegocio("O meio de pagamento {$m->nome} está inactivo.", 'MEIO_PAGAMENTO_INVALIDO', 422);
            }
            $saida[] = ['meio_pagamento_id' => (int) $m->id, 'nome' => $m->nome, 'codigo_conta' => $m->codigo_conta, 'iban' => $m->iban, 'swift' => $m->swift];
        }

        return $saida;
    }

    /**
     * «Guias de Saída Pendentes de Faturação» (showSaleForm do legado): guias de saída de VENDA do armazém, não
     * facturadas nem anuladas, com as linhas (quantidade da guia e preço da ficha).
     *
     * @return list<array<string, mixed>>
     */
    public function guiasPendentes(?int $clienteId = null): array
    {
        $empresa = $this->contexto->obrigatorio();
        $guias = DB::table('guias_saida as g')->where('g.empresa_id', $empresa)->where('g.tipo', 'VENDA')
            ->where(fn ($q) => $q->whereNull('g.estado')->orWhereNotIn('g.estado', ['FATURADA', 'ANULADA']))->whereNull('g.anulado_em')
            ->when($clienteId, fn ($q, $c) => $q->where('g.terceiro_id', $c))
            ->leftJoin('terceiros as t', 't.id', '=', 'g.terceiro_id')
            ->orderBy('g.data')->orderBy('g.id')->limit(100)
            ->get(['g.id', 'g.numero_documento', 'g.data', 'g.terceiro_id', 't.nome as cliente', 'g.armazem_id', 'g.projeto_id']);
        $linhas = $guias->isEmpty() ? collect() : DB::table('itens_guia_saida as i')->leftJoin('produtos as p', 'p.id', '=', 'i.produto_id')
            ->where('i.empresa_id', $empresa)->whereIn('i.guia_saida_id', $guias->pluck('id'))->orderBy('i.id')
            ->get(['i.guia_saida_id', 'i.produto_id', 'p.codigo', 'p.nome', 'p.preco_unitario', 'p.taxa_imposto', 'i.quantidade'])->groupBy('guia_saida_id');

        return $guias->map(fn ($g) => [
            'id' => (int) $g->id, 'numero_documento' => $g->numero_documento, 'data' => $g->data, 'estado' => 'PENDENTE',
            'cliente' => $g->terceiro_id ? ['id' => (int) $g->terceiro_id, 'nome' => $g->cliente] : null,
            'armazem_id' => $g->armazem_id ? (int) $g->armazem_id : null, 'projeto_id' => $g->projeto_id ? (int) $g->projeto_id : null,
            'linhas' => collect($linhas[$g->id] ?? [])->map(fn ($l) => ['produto_id' => (int) $l->produto_id, 'codigo' => $l->codigo, 'nome' => $l->nome,
                'quantidade' => number_format((float) $l->quantidade, 3, '.', ''), 'preco_unitario' => $l->preco_unitario, 'taxa_imposto' => $l->taxa_imposto])->values()->all(),
        ])->values()->all();
    }

    /**
     * Guias de saída a facturar num documento: do cliente do documento, de VENDA, por facturar. Bloqueia-as (FOR UPDATE)
     * para duas facturas em simultâneo não facturarem a mesma guia. Chamado dentro da transacção da emissão.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    public function bloquearGuiasParaFacturar(array $ids, int $clienteId, string $tipo): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (! $ids) {
            return [];
        }
        if (! in_array($tipo, ['FT', 'FR'], true)) {
            throw new ErroNegocio('As guias de saída só se facturam numa factura ou factura-recibo.', 'GUIA_TIPO_INVALIDO', 422);
        }
        $empresa = $this->contexto->obrigatorio();
        $guias = DB::table('guias_saida')->where('empresa_id', $empresa)->whereIn('id', $ids)->lockForUpdate()->get(['id', 'numero_documento', 'tipo', 'estado', 'terceiro_id', 'anulado_em']);
        if ($guias->count() !== count($ids)) {
            throw new ErroNegocio('Uma das guias de saída não existe.', 'GUIA_INEXISTENTE', 422);
        }
        foreach ($guias as $g) {
            if ($g->tipo !== 'VENDA' || $g->anulado_em !== null || in_array($g->estado, ['FATURADA', 'ANULADA'], true)) {
                throw new ErroNegocio("A guia {$g->numero_documento} já foi facturada ou não é uma guia de venda.", 'GUIA_JA_FATURADA', 422);
            }
            if ((int) $g->terceiro_id !== $clienteId) {
                throw new ErroNegocio("A guia {$g->numero_documento} é de outro cliente.", 'GUIA_OUTRO_CLIENTE', 422);
            }
        }

        return $ids;
    }

    /** Depois de emitida a factura: as guias ficam FATURADA e ligadas a ela (o stock já saiu na guia). */
    public function marcarGuiasFacturadas(array $ids, Venda $venda): void
    {
        if (! $ids) {
            return;
        }
        DB::table('guias_saida')->where('empresa_id', $venda->empresa_id)->whereIn('id', $ids)
            ->update(['estado' => 'FATURADA', 'estado_original' => 'FATURADA', 'venda_relacionada_id' => $venda->id, 'atualizado_em' => now()]);
    }

    /**
     * «Forçar limpeza de estado» (forceClearSale do legado). O legado apagava recibos e documentos de tesouraria; aqui
     * nada se apaga (os recibos anulam-se com rasto): o pendente e o estado da factura são recalculados a partir do
     * pago registado e das notas de crédito não anuladas, o que desbloqueia uma factura com estado incoerente.
     */
    public function recalcularEstado(Venda $venda): Venda
    {
        if ($venda->tipo_documento !== 'FT') {
            throw new ErroNegocio('Só as facturas (FT) têm estado de pagamento a recalcular (a factura-recibo é paga no acto).', 'TIPO_NAO_SUPORTADO', 422);
        }
        if ($venda->estado === 'ANULADO') {
            throw new ErroNegocio('O documento está anulado.', 'DOCUMENTO_ANULADO', 422);
        }

        return DB::transaction(function () use ($venda) {
            $v = Venda::query()->lockForUpdate()->findOrFail($venda->id);
            $this->estado->recalcular($v);

            return $v->refresh();
        });
    }

    /**
     * «Converter em Pedido de Compra» de uma encomenda (convertToPurchaseRequest do legado): pedido de compra com as
     * linhas ainda por facturar e que não estejam já noutro pedido activo (mesma regra de Compras › Encomendas Clientes).
     */
    public function pedidoCompra(Venda $venda, ServicoEncomendasClientes $encomendas): PedidoCompra
    {
        if ($venda->tipo_documento !== 'NE') {
            throw new ErroNegocio('Só as encomendas de clientes se convertem em pedido de compra.', 'TIPO_NAO_SUPORTADO', 422);
        }
        if ($venda->estado === 'ANULADO') {
            throw new ErroNegocio('A encomenda está anulada.', 'DOCUMENTO_ANULADO', 422);
        }
        $activos = PedidoCompra::query()->where('estado', '<>', 'ANULADO')->pluck('id')->flip();
        $itens = ItemVenda::query()->where('venda_id', $venda->id)->orderBy('id')->get()
            ->filter(fn (ItemVenda $i) => (float) $i->quantidade - (float) $i->quantidade_faturada > 0.0005 && ! ($i->pedido_compra_id && isset($activos[$i->pedido_compra_id])))
            ->pluck('id')->all();
        if (! $itens) {
            throw new ErroNegocio('A encomenda não tem linhas por comprar (já facturadas ou já num pedido de compra).', 'SEM_QUANTIDADES', 422);
        }

        return $encomendas->gerarPedido($itens, "Vendas (Ref: {$venda->numero_documento})", "Compra para a encomenda {$venda->numero_documento}");
    }

    /**
     * «Editar em massa» de produtos (mapeamento_massa.js do legado): dados (preço — definir, ajustar % ou somar —, IVA,
     * categoria, stock, serviço, bloqueio). Cada produto passa pelas regras da ficha (ServicoProdutos::guardar), numa só
     * transacção. Ronda 3: as contas já NÃO se gravam no produto (a categoria sobrepõe-se — ContasProduto::de): pedidos
     * com contas recebem 422 CONTAS_NA_CATEGORIA; muda-se a categoria dos produtos ou editam-se as contas da categoria
     * ($categoriaContas, com vendas_categorias_contas — vale para todos os produtos da categoria).
     *
     * @param  list<int>  $ids
     * @param  array<string, mixed>  $dados
     * @param  array<string, string|null>  $contas  contas de produto (recusadas se preenchidas)
     * @param  array{categoria_produto_id: int, contas: array<string, string|null>}|null  $categoriaContas
     * @return array{alterados: int, categoria?: array<string, mixed>}
     */
    public function editarProdutosEmMassa(array $ids, array $dados, array $contas, ServicoProdutos $produtos, ?array $categoriaContas = null): array
    {
        $massa = app(ServicoContasCategoriaMassa::class);
        $massa->recusarContasNoProduto($contas);
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $lista = Produto::query()->whereIn('id', $ids)->get();
        if ($lista->count() !== count($ids)) {
            throw new ErroNegocio('Um dos produtos não existe.', 'PRODUTO_INEXISTENTE', 422);
        }
        $comuns = array_intersect_key($dados, array_flip(['taxa_imposto', 'categoria_produto_id', 'movimenta_stock', 'e_servico', 'bloqueado']));
        $preco = $dados['preco'] ?? null;
        if (! $comuns && ! $preco && ! $categoriaContas) {
            throw new ErroNegocio('Escolha pelo menos um campo a alterar.', 'SEM_ALTERACOES', 422);
        }
        if ($lista->isEmpty() && ($comuns || $preco)) {
            throw new ErroNegocio('Seleccione os produtos a alterar.', 'SEM_PRODUTOS', 422);
        }

        return DB::transaction(function () use ($lista, $comuns, $preco, $produtos, $categoriaContas, $massa) {
            $r = ['alterados' => 0];
            if ($categoriaContas) {
                $r['categoria'] = $massa->editarContas((int) $categoriaContas['categoria_produto_id'], $categoriaContas['contas'] ?? [], 'Vendas/Produtos');
            }
            if ($comuns || $preco) {
                $this->aplicarDadosEmMassa($lista, $comuns, $preco, $produtos);
                $r['alterados'] = $lista->count();
            }

            return $r;
        });
    }

    /** @param  Collection<int, Produto>  $lista */
    private function aplicarDadosEmMassa($lista, array $comuns, ?array $preco, ServicoProdutos $produtos): void
    {
        foreach ($lista as $p) {
            $alteracao = $comuns;
            if ($preco) {
                $actual = (string) ($p->preco_unitario ?? '0');
                $valor = (string) $preco['valor'];
                $novo = match ($preco['modo']) {
                    'DEFINIR' => $valor,
                    'PERCENT' => bcadd($actual, bcdiv(bcmul($actual, $valor, 6), '100', 6), 6),
                    'VALOR' => bcadd($actual, $valor, 6),
                };
                $novo = number_format(round((float) $novo, 2), 2, '.', '');
                if (bccomp($novo, '0', 2) < 0) {
                    throw new ErroNegocio("O preço de {$p->codigo} ficaria negativo.", 'PRECO_INVALIDO', 422);
                }
                $alteracao['preco_unitario'] = $novo;
            }
            $produtos->guardar($alteracao, $p);
        }
    }

    /**
     * «Dados a rever antes de activar» do ecrã de facturação electrónica do legado (facturacao_agt_ui.js:238): dados que
     * entram em cada documento enviado à AGT — produtos sem código, isentos sem motivo, com taxa fora de 0/5/7/14 %,
     * clientes sem NIF e o NIF da empresa. Só leitura; não envia nada à AGT.
     *
     * @return array{empresa_nif: string|null, sem_codigo: array<string, mixed>, isentos_sem_motivo: array<string, mixed>, taxa_fora: array<string, mixed>, clientes_sem_nif: array<string, mixed>}
     */
    public function diagnosticoAgt(?string $isencaoPadrao, int $amostra = 6): array
    {
        $empresa = $this->contexto->obrigatorio();
        $produtos = fn () => DB::table('produtos')->where('empresa_id', $empresa)->whereNull('eliminado_em')
            ->where(fn ($q) => $q->whereNull('bloqueado')->orWhere('bloqueado', false));
        $bloco = function ($consulta, callable $nome) use ($amostra): array {
            $total = (clone $consulta)->count();

            return ['total' => $total, 'exemplos' => $total ? (clone $consulta)->ordenarPorNome()->limit($amostra)->get()->map($nome)->values()->all() : []];
        };
        $nif = DB::table('empresas')->where('id', $empresa)->value('nif');
        $nifValido = is_string($nif) && preg_match('/^\d{9}$|^\d{10}$|^\d{9}[A-Z]{2}\d{3}$/', trim($nif)) === 1;

        return [
            'empresa_nif' => $nifValido ? null : 'O NIF da empresa está em falta ou não tem um formato válido (ficha da empresa).',
            'sem_codigo' => $bloco($produtos()->where(fn ($q) => $q->whereNull('codigo')->orWhereRaw("TRIM(codigo) = ''")), fn ($p) => (string) $p->nome),
            'isentos_sem_motivo' => $isencaoPadrao ? ['total' => 0, 'exemplos' => []]
                : $bloco($produtos()->whereRaw('COALESCE(taxa_imposto, 0) = 0')->where(fn ($q) => $q->whereNull('codigo_isencao_fe')->orWhereRaw("TRIM(codigo_isencao_fe) = ''")), fn ($p) => (string) $p->nome),
            'taxa_fora' => $bloco($produtos()->whereNotNull('taxa_imposto')->whereNotIn('taxa_imposto', [0, 5, 7, 14]), fn ($p) => "{$p->nome} (".rtrim(rtrim(number_format((float) $p->taxa_imposto, 2, ',', ''), '0'), ',').'%)'),
            'clientes_sem_nif' => $bloco(DB::table('terceiros')->where('empresa_id', $empresa)->whereNull('eliminado_em')
                ->whereIn('tipo', Terceiro::TIPOS_CLIENTE)->where(fn ($q) => $q->whereNull('nif')->orWhereRaw("TRIM(nif) = ''")), fn ($c) => trim((string) $c->nome)),
        ];
    }

    private static function dec(mixed $v): string
    {
        return number_format(round((float) $v, 2), 2, '.', '');
    }
}
