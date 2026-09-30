<?php

namespace App\Services\POS\Lavandaria;

use App\Exceptions\ErroNegocio;
use App\Models\ItemVenda;
use App\Models\PagamentoLavandaria;
use App\Models\PedidoLavandaria;
use App\Models\Produto;
use App\Models\ReclamacaoLavandaria;
use App\Models\Terceiro;
use App\Models\TerminalPOS;
use App\Models\Venda;
use Carbon\CarbonPeriod;

/**
 * Relatório da lavandaria por período e terminal (dadosRelatorio, js/lavandaria.js:1896-1986).
 * Facturado = documentos de venda (FT/FR) das ordens; recebido = recebimentos (recibos e facturas-recibo) — sempre em separado.
 */
final class ServicoRelatorioLavandaria
{
    public function gerar(string $de, string $ate, ?int $terminalId): array
    {
        [$de, $ate] = $de <= $ate ? [$de, $ate] : [$ate, $de];
        if (CarbonPeriod::create($de, $ate)->count() > 400) {
            throw new ErroNegocio('O período tem no máximo 400 dias.', 'PERIODO_INVALIDO', 422);
        }
        $noPeriodo = fn ($d) => $d && ($x = substr((string) $d, 0, 10)) >= $de && $x <= $ate;
        $diaLocal = fn ($iso) => $iso ? date('Y-m-d', strtotime((string) $iso)) : null;
        $ordens = PedidoLavandaria::query()->when($terminalId, fn ($q) => $q->where('terminal_pos_id', $terminalId))->get();
        $terminais = TerminalPOS::query()->withTrashed()->get(['id', 'codigo', 'nome', 'tipo'])->keyBy('id');
        $docs = Venda::query()->whereNotNull('pedido_lavandaria_id')->whereIn('tipo_documento', ['FT', 'FR'])
            ->whereBetween('data_emissao', ["{$de} 00:00:00", "{$ate} 23:59:59"])->when($terminalId, fn ($q) => $q->where('terminal_pos_id', $terminalId))->orderBy('id')->get();
        $ativos = $docs->where('estado', '<>', 'ANULADO');
        $pagamentos = PagamentoLavandaria::query()->where(fn ($q) => $q->whereNull('estado')->orWhere('estado', '<>', 'ANULADO'))
            ->whereBetween('data', [$de, $ate])->when($terminalId, fn ($q) => $q->where('terminal_pos_id', $terminalId))->orderBy('id')->get();
        $recibos = $pagamentos->where('natureza_registo', '<>', 'FR');
        $clientes = Terceiro::query()->withTrashed()->whereIn('id', $docs->pluck('cliente_id')->merge($pagamentos->pluck('cliente_id'))->filter()->unique())->pluck('nome', 'id');
        $soma = fn ($lista, $campo) => RegrasLavandaria::soma(collect($lista)->map(fn ($x) => ['total' => data_get($x, $campo)])->all());
        $recebidas = $ordens->filter(fn ($o) => $noPeriodo($diaLocal($o->recebido_em)));
        $etiquetas = fn ($o) => collect($o->itens ?? [])->where('estado', '<>', 'ANULADA')->sum(fn ($i) => count($i['etiquetas'] ?? []));
        $itensEntregues = $ordens->flatMap(fn ($o) => collect($o->itens ?? [])->filter(fn ($i) => $i['estado'] === 'ENTREGUE' && $noPeriodo($diaLocal($i['entregue_em'] ?? null))));
        $agora = now();
        $ind = $ordens->map(fn ($o) => RegrasLavandaria::indicadores($o->toArray(), $agora));
        $concluidas = $ind->filter(fn ($x) => $x['pronta_em'] && $noPeriodo($diaLocal($x['pronta_em'])));
        $pagoPorOrdem = PagamentoLavandaria::query()->where(fn ($q) => $q->whereNull('estado')->orWhere('estado', '<>', 'ANULADO'))
            ->selectRaw('pedido_lavandaria_id, sum(montante) as pago')->groupBy('pedido_lavandaria_id')->pluck('pago', 'pedido_lavandaria_id');
        $emAberto = $ordens->where('estado', '<>', 'ANULADA');
        $totalOrdem = fn ($o) => RegrasLavandaria::totais($o->itens ?? [], $o->extras ?? [], '0.00', '0.00')['total'];

        $resumo = [
            'recebidas' => $recebidas->count(), 'etiquetas' => $recebidas->sum($etiquetas),
            'valor_recebidas' => $soma($recebidas->where('estado', '<>', 'ANULADA')->map(fn ($o) => ['v' => $totalOrdem($o)]), 'v'),
            'entregues' => $ordens->filter(fn ($o, $k) => $o->estado === 'ENTREGUE' && $noPeriodo($diaLocal($ind[$k]['entregue_em'])))->count(),
            'pecas_entregues' => $itensEntregues->sum(fn ($i) => count($i['etiquetas'] ?? [])),
            'faturas_numero' => $ativos->where('tipo_documento', 'FT')->count(), 'faturas_valor' => $soma($ativos->where('tipo_documento', 'FT'), 'total_bruto'),
            'faturas_recibo_numero' => $ativos->where('tipo_documento', 'FR')->count(), 'faturas_recibo_valor' => $soma($ativos->where('tipo_documento', 'FR'), 'total_bruto'),
            'recibos_numero' => $recibos->count(), 'recibos_valor' => $soma($recibos, 'montante'),
            'base' => $soma($ativos, 'total_liquido'), 'iva' => $soma($ativos, 'total_imposto'), 'facturado' => $soma($ativos, 'total_bruto'),
            'recebido' => $soma($pagamentos, 'montante'), 'anulados' => $docs->count() - $ativos->count(),
            'concluidas' => $concluidas->count(), 'percentagem_no_prazo' => $concluidas->count() ? (int) round($concluidas->where('no_prazo', true)->count() / $concluidas->count() * 100) : null,
            'danos' => ReclamacaoLavandaria::query()->whereIn('pedido_lavandaria_id', $ordens->pluck('id'))->whereBetween('criado_em', ["{$de} 00:00:00", "{$ate} 23:59:59"])->count(),
            'saldo_aberto' => $soma($emAberto->map(fn ($o) => ['v' => max(0, (float) bcsub($totalOrdem($o), RegrasLavandaria::dinheiro($pagoPorOrdem[$o->id] ?? 0), 2))]), 'v'),
            'por_facturar' => $soma($emAberto->map(fn ($o) => ['v' => RegrasLavandaria::soma(RegrasLavandaria::linhasPorFacturar($o->itens ?? [], $o->extras ?? []))]), 'v'),
        ];
        $documentos = $docs->map(fn ($d) => ['data' => $d->data_emissao->toDateString(), 'numero' => $d->numero_documento, 'tipo' => $d->tipo_documento,
            'ordem' => $d->numero_pedido_lavandaria, 'cliente' => $clientes[$d->cliente_id] ?? null, 'terminal' => $d->codigo_terminal_pos ?? $terminais[$d->terminal_pos_id]?->codigo,
            'base' => (string) $d->total_liquido, 'iva' => (string) $d->total_imposto, 'total' => (string) $d->total_bruto, 'estado' => $d->estado])
            ->merge($recibos->map(fn ($p) => ['data' => $p->data->toDateString(), 'numero' => $p->numero_recibo, 'tipo' => $p->natureza_registo === 'ADIANTAMENTO' ? 'RECIBO_ADIANTAMENTO' : 'RECIBO',
                'ordem' => $p->numero_encomenda, 'cliente' => $clientes[$p->cliente_id] ?? null, 'terminal' => $terminais[$p->terminal_pos_id]?->codigo,
                'base' => null, 'iva' => null, 'total' => (string) $p->montante, 'estado' => $p->estado]))
            ->sortBy(fn ($x) => $x['data'].$x['numero'])->values();

        $porMeio = [];
        foreach ($pagamentos as $p) {
            foreach ($p->pos_pagamentos ?? [] as $m) {
                $k = $m['nome'] ?? $m['tipo'] ?? '—';
                $porMeio[$k] ??= ['meio' => $k, 'numero' => 0, 'total' => '0.00'];
                $porMeio[$k]['numero']++;
                $porMeio[$k]['total'] = bcadd($porMeio[$k]['total'], RegrasLavandaria::dinheiro($m['valor'] ?? 0), 2);
            }
        }
        $produtos = Produto::withTrashed()->get(['id', 'codigo', 'nome', 'lavandaria_grupo'])->keyBy('id');
        $porServico = [];
        foreach (ItemVenda::query()->whereIn('venda_id', $ativos->pluck('id'))->get() as $i) {
            $p = $produtos[$i->produto_id] ?? null;
            $porServico[$i->produto_id] ??= ['servico' => $p ? trim("{$p->codigo} · {$p->nome}") : $i->descricao, 'grupo' => $p?->lavandaria_grupo, 'quantidade' => '0.000', 'total' => '0.00'];
            $porServico[$i->produto_id]['quantidade'] = bcadd($porServico[$i->produto_id]['quantidade'], (string) $i->quantidade, 3);
            $porServico[$i->produto_id]['total'] = bcadd($porServico[$i->produto_id]['total'], RegrasLavandaria::dinheiro($i->total), 2);
        }
        $porPeca = [];
        foreach ($recebidas as $o) {
            foreach (collect($o->itens ?? [])->where('estado', '<>', 'ANULADA') as $i) {
                $k = ($i['nome_peca'] ?? '—').'|'.($i['unidade'] ?? 'PECA');
                $porPeca[$k] ??= ['peca' => $i['nome_peca'] ?? '—', 'unidade' => $i['unidade'] ?? 'PECA', 'quantidade' => '0.000', 'etiquetas' => 0, 'valor' => '0.00'];
                $porPeca[$k]['quantidade'] = bcadd($porPeca[$k]['quantidade'], (string) $i['quantidade'], 3);
                $porPeca[$k]['etiquetas'] += count($i['etiquetas'] ?? []);
                $porPeca[$k]['valor'] = bcadd($porPeca[$k]['valor'], RegrasLavandaria::valorItem($i), 2);
            }
        }
        $porDia = [];
        foreach (CarbonPeriod::create($de, $ate) as $dia) {
            $d = $dia->toDateString();
            $doDia = $recebidas->filter(fn ($o) => $diaLocal($o->recebido_em) === $d);
            $porDia[] = ['dia' => $d, 'recebidas' => $doDia->count(), 'etiquetas' => $doDia->sum($etiquetas),
                'entregues' => $itensEntregues->filter(fn ($i) => $diaLocal($i['entregue_em'] ?? null) === $d)->sum(fn ($i) => count($i['etiquetas'] ?? [])),
                'documentos' => $documentos->where('data', $d)->where('estado', '<>', 'ANULADO')->count(),
                'facturado' => $soma($ativos->filter(fn ($v) => $v->data_emissao->toDateString() === $d), 'total_bruto'),
                'recebido' => $soma($pagamentos->filter(fn ($p) => $p->data->toDateString() === $d), 'montante')];
        }
        $porTerminal = $terminais->filter(fn ($t) => ! $terminalId || $t->id === $terminalId)->map(fn ($t) => ['terminal' => "{$t->codigo} · {$t->nome}",
            'ordens' => $recebidas->where('terminal_pos_id', $t->id)->count(), 'documentos' => $ativos->where('terminal_pos_id', $t->id)->count() + $recibos->where('terminal_pos_id', $t->id)->count(),
            'facturado' => $soma($ativos->where('terminal_pos_id', $t->id), 'total_bruto'), 'recebido' => $soma($pagamentos->where('terminal_pos_id', $t->id), 'montante')])
            ->filter(fn ($x) => $x['ordens'] || $x['documentos'])->values();

        return ['periodo' => ['de' => $de, 'ate' => $ate], 'terminal_pos_id' => $terminalId, 'resumo' => $resumo, 'documentos' => $documentos,
            'por_meio' => array_values($porMeio), 'por_servico' => collect($porServico)->sortByDesc(fn ($x) => (float) $x['total'])->values(),
            'por_peca' => collect($porPeca)->sortByDesc(fn ($x) => (float) $x['valor'])->values(), 'por_dia' => $porDia, 'por_terminal' => $porTerminal];
    }
}
