<?php

namespace App\Services\Gestao\Fluxos\Avaliadores;

use App\Services\Vendas\ServicoConfigVendas;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fluxo de vendas (carregarVendas/avaliarVenda, js/fluxo_vendas.js:84-288). Um processo é a cadeia de documentos ligados
 * pela origem (vendas_documentos_relacionados: venda_id = documento gerado, venda_relacionada_id = origem), a partir do
 * documento raiz. Etapas: orçamento/proforma → encomenda → entrega (guia) → factura → contabilização → recebimento ou
 * compensação. As vendas do POS e da lavandaria ficam de fora (têm fluxo próprio); documentos anulados não contam.
 * Recebimento (igual ao legado): débitos na conta do cliente (classe 3) com o número da factura, liquidados por compensação
 * (reconciliacao_codigo), por créditos não compensados na mesma conta com o mesmo número (recebimento integrado da
 * Tesouraria), por recibos contabilizados ou por notas de crédito da factura; o valor pago gravado no documento conta
 * quando é maior. Recebimentos na Tesouraria ainda por integrar aparecem como «Recebimento por integrar».
 * Correcção: o bloqueio «cliente sem conta» só se aplica à FT e à NC e só quando não há conta de clientes por omissão
 * (ADR-050: a FR não lança na conta do cliente); o legado bloqueava qualquer documento de cliente sem conta.
 */
final class FluxoVendas extends AvaliadorFluxo
{
    public function __construct(ContextoEmpresa $contexto, private readonly ServicoConfigVendas $config)
    {
        parent::__construct($contexto);
    }

    public function temActividade(): bool
    {
        return DB::table('vendas')->where('empresa_id', $this->empresa())->whereNull('sessao_pos_id')->whereNull('pedido_lavandaria_id')->exists();
    }

    public function avaliar(): array
    {
        $e = $this->empresa();
        $V = DB::table('vendas as v')->leftJoin('terceiros as c', 'c.id', '=', 'v.cliente_id')->where('v.empresa_id', $e)
            ->whereNull('v.sessao_pos_id')->whereNull('v.pedido_lavandaria_id')->whereRaw("COALESCE(v.estado, '') !~* '(ANUL|CANCEL)'")
            ->get(['v.id', 'v.tipo_documento', 'v.numero_documento', 'v.data_emissao', 'v.total_bruto', 'v.total_liquido', 'v.estado', 'v.contabilizado', 'v.valor_pago',
                'v.cliente_id', 'v.data_entrega', 'v.local_entrega', 'v.numero_lan_contabilizacao', 'c.nome as cliente', 'c.codigo_conta as conta_cliente'])->keyBy('id');
        $pais = DB::table('vendas_documentos_relacionados')->where('empresa_id', $e)->get(['venda_id', 'venda_relacionada_id'])
            ->filter(fn ($r) => isset($V[$r->venda_id], $V[$r->venda_relacionada_id]));
        $filhos = $pais->groupBy('venda_relacionada_id')->map(fn ($g) => $g->pluck('venda_id')->all());
        $temPai = $pais->pluck('venda_id')->flip();
        $recebido = DB::table('itens_recibo_venda as i')->join('recibos_venda as r', 'r.id', '=', 'i.recibo_venda_id')->where('i.empresa_id', $e)
            ->whereRaw("COALESCE(r.estado, '') !~* '(ANUL|CANCEL)'")->whereIn('i.venda_id', $V->keys())
            ->groupBy('i.venda_id')->selectRaw("i.venda_id, SUM(CASE WHEN r.contabilizado THEN i.montante_pago ELSE 0 END) AS contabilizado, string_agg(DISTINCT r.numero_recibo, ', ') AS numeros")
            ->get()->keyBy('venda_id');
        $numeros = $V->filter(fn ($s) => in_array($s->tipo_documento, ['FT', 'FR', 'NC'], true) && $s->numero_documento)->pluck('numero_documento')->unique()->values();
        $linhas = $numeros->isEmpty() ? collect() : DB::table('lancamentos_contabeis')->where('empresa_id', $e)->whereRaw("TRIM(codigo_conta) LIKE '3%'")
            ->whereIn('numero_documento', $numeros)->get(['numero_documento', 'codigo_conta', 'tipo_dc', 'valor', 'terceiro_id', 'reconciliacao_codigo', 'numero_lan'])->groupBy('numero_documento');
        $tesPend = $numeros->isEmpty() ? collect() : DB::table('itens_documento_tesouraria as i')->join('documentos_tesouraria as d', 'd.id', '=', 'i.documento_tesouraria_id')
            ->where('d.empresa_id', $e)->where('d.tipo', 'RECEBIMENTO')->whereNotIn('d.estado', ['ANULADO', 'INTEGRADO'])->where('i.tipo_dc', 'C')->whereIn('i.numero_documento', $numeros)
            ->get(['i.numero_documento', 'i.valor', 'd.id', 'd.referencia'])->groupBy('numero_documento');
        $contaPadrao = $this->config->conta('clientes_default');

        $processos = [];
        foreach ($V as $raiz) {
            if (isset($temPai[$raiz->id])) {
                continue;
            }
            $docs = [];
            $vistos = [];
            $visitar = function ($id) use (&$visitar, &$docs, &$vistos, $V, $filhos) {
                if (isset($vistos[$id])) {
                    return;
                }
                $vistos[$id] = true;
                $docs[] = $V[$id];
                foreach ($filhos[$id] ?? [] as $f) {
                    $visitar($f);
                }
            };
            $visitar($raiz->id);
            $processos[] = $this->avaliarProcesso($raiz, collect($docs), $filhos, $recebido, $linhas, $tesPend, $contaPadrao, $V);
        }
        usort($processos, fn ($a, $b) => strcmp((string) $b['data'], (string) $a['data']));
        $total = 6;
        $porReceber = array_reduce($processos, fn ($s, $p) => bcadd($s, $p['valor_pendente'], 2), '0.00');

        return ['processos' => $processos, 'kpis' => [
            self::kpi('em_curso', 'Processos em curso', count(array_filter($processos, fn ($p) => ! $p['_bloqueado'] && $p['_concluidas'] < $total))),
            self::kpi('bloqueados', 'Bloqueados', $b = count(array_filter($processos, fn ($p) => $p['_bloqueado'])), 'num', $b > 0),
            self::kpi('concluidos', 'Concluídos', count(array_filter($processos, fn ($p) => $p['_concluidas'] === $total))),
            self::kpi('por_facturar', 'Por facturar', count(array_filter($processos, fn ($p) => $p['etapas']['fact']['estado'] === 'curso'))),
            self::kpi('por_receber', 'Valor por receber', $porReceber, 'kz', bccomp($porReceber, '0.01', 2) > 0),
        ]];
    }

    private function avaliarProcesso(object $raiz, Collection $docs, Collection $filhos, Collection $recebido, Collection $linhasPorDoc, Collection $tesPend, ?string $contaPadrao, Collection $V): array
    {
        $tipo = fn (array $t) => $docs->filter(fn ($s) => in_array($s->tipo_documento, $t, true))->values();
        $orcs = $tipo(['OR', 'PF']);
        $encs = $tipo(['NE']);
        $guias = $tipo(['GR']);
        $facts = $tipo(['FT', 'FR']);
        $ncs = $tipo(['NC']);
        $nums = fn (Collection $c) => self::lista($c->map(fn ($s) => $s->numero_documento ?: "#{$s->id}"));
        $convertido = fn ($s) => ! empty($filhos[$s->id]) || preg_match('/conclu/i', (string) $s->estado);
        $E = [];

        // 1. Orçamento / proforma
        if ($orcs->isEmpty()) {
            $E['orc'] = self::naoAplica($encs->isNotEmpty() ? 'Sem orçamento' : 'Venda directa');
        } else {
            $factos = [self::f('Documento(s)', $nums($orcs)), self::f('Data', $orcs[0]->data_emissao, 'data'), self::f('Valor', self::dinheiro($orcs[0]->total_bruto), 'kz')];
            $E['orc'] = $orcs->contains(fn ($s) => ! $convertido($s))
                ? self::etapa('curso', 'Aguarda aceitação do cliente', $factos, [], [self::accao('Converter em Faturação', 'vendas_faturacao', true)])
                : self::feito('Convertido', $factos);
        }
        // 2. Encomenda do cliente
        if ($encs->isEmpty()) {
            $E['enc'] = $orcs->isNotEmpty() && $facts->isEmpty() && $guias->isEmpty() && $E['orc']['estado'] === 'curso'
                ? self::porFazer('Depois de aceite') : self::naoAplica($orcs->isNotEmpty() ? 'Sem encomenda' : 'Não aplicável');
        } else {
            $factos = [self::f('Encomenda(s)', $nums($encs)), self::f('Valor', self::dinheiro($encs[0]->total_bruto), 'kz')];
            $E['enc'] = $encs->contains(fn ($s) => ! $convertido($s))
                ? self::etapa('curso', 'Por satisfazer', $factos, [], [self::accao('Entregar ou facturar em Faturação', 'vendas_faturacao', true)])
                : self::feito($nums($encs), $factos);
        }
        // 3. Entrega (guia de remessa)
        if ($guias->isNotEmpty()) {
            $E['ent'] = $guias->contains(fn ($g) => preg_match('/ABERTO/i', (string) $g->estado))
                ? self::etapa('curso', 'Guia em aberto', [self::f('Guia(s)', $nums($guias)), self::f('Local', $guias[0]->local_entrega ?: '—'), self::f('Data de entrega', $guias[0]->data_entrega, 'data')], [],
                    [self::accao('Concluir a guia em Faturação', 'vendas_faturacao', true)])
                : self::feito('Entregue', [self::f('Guia(s)', $nums($guias)), self::f('Data de entrega', $guias[0]->data_entrega ?: $guias[0]->data_emissao, 'data')]);
        } elseif ($facts->isNotEmpty()) {
            $E['ent'] = self::naoAplica('Sem guia');
        } elseif ($encs->isNotEmpty() && $E['enc']['estado'] === 'curso') {
            $E['ent'] = self::etapa('curso', 'Por entregar ou facturar', [], [], [self::accao('Abrir Faturação', 'vendas_faturacao', true)]);
        } else {
            $E['ent'] = $orcs->isNotEmpty() ? self::porFazer('Depois da encomenda') : self::naoAplica();
        }
        // 4. Factura
        $totalFact = $facts->reduce(fn ($s, $f) => bcadd($s, self::dinheiro($f->total_bruto), 2), '0.00');
        if ($facts->isEmpty()) {
            $E['fact'] = $E['orc']['estado'] === 'concluida' && $E['enc']['estado'] === 'concluida' && $E['ent']['estado'] === 'concluida'
                ? self::etapa('curso', 'Por facturar', [], [], [self::accao('Emitir a factura em Faturação', 'vendas_faturacao', true)]) : self::porFazer('Depois de aceite/entregue');
        } else {
            $E['fact'] = self::feito($nums($facts), array_merge([self::f('Factura(s)', $nums($facts)), self::f('Cliente', $raiz->cliente ?: '—'), self::f('Valor com IVA', $totalFact, 'kz')],
                $ncs->isNotEmpty() ? [self::f('Nota(s) de crédito', $nums($ncs))] : []));
        }
        // 5. Contabilização
        if ($facts->isEmpty()) {
            $E['contab'] = self::porFazer('Depois de facturar');
        } else {
            $porContab = $facts->merge($ncs)->filter(fn ($s) => ! $s->contabilizado)->values();
            $semConta = $porContab->contains(fn ($s) => in_array($s->tipo_documento, ['FT', 'NC'], true) && $s->cliente_id && ! trim((string) $s->conta_cliente) && ! $contaPadrao);
            $lans = $facts->flatMap(fn ($f) => ($linhasPorDoc[$f->numero_documento] ?? collect())->pluck('numero_lan')->push($f->numero_lan_contabilizacao))->filter()->unique()->values();
            $E['contab'] = $porContab->isNotEmpty()
                ? self::etapa($semConta ? 'bloqueada' : 'curso', $porContab->count().' por contabilizar', [self::f('Documento(s)', $nums($porContab))],
                    $semConta ? [self::erro("O cliente {$raiz->cliente} não tem conta contabilística e não há conta de clientes por omissão: o documento não pode ser contabilizado.")] : [],
                    $semConta ? [self::accao('Corrigir o cliente', 'vendas_clientes', true)] : [self::accao('Contabilizar em Faturação', 'vendas_faturacao', true)])
                : self::feito($lans->first() ?: 'Contabilizado', [self::f('Lançamento(s)', $lans->implode(', ') ?: '—')]);
        }
        // 6. Recebimento ou compensação
        $porReceber = '0.00';
        $contabilizadas = $facts->filter(fn ($f) => $f->contabilizado)->values();
        if ($contabilizadas->isEmpty()) {
            $E['rec'] = self::porFazer('Depois de contabilizar');
        } else {
            [$devido, $comp, $recConta, $recibos, $notas, $pendTes] = ['0.00', '0.00', '0.00', '0.00', '0.00', '0.00'];
            $contas = [];
            $refsPend = [];
            $numsRecibos = [];
            foreach ($contabilizadas as $f) {
                $ls = ($linhasPorDoc[$f->numero_documento] ?? collect())->filter(fn ($l) => ! $raiz->cliente_id || ! $l->terceiro_id || (int) $l->terceiro_id === (int) $raiz->cliente_id);
                $deb = $ls->where('tipo_dc', 'D');
                $dev = $deb->reduce(fn ($s, $l) => bcadd($s, self::dinheiro($l->valor), 2), '0.00');
                $devido = bcadd($devido, bccomp($dev, '0', 2) !== 0 ? $dev : self::dinheiro($f->total_bruto), 2);
                foreach ($deb as $l) {
                    $contas[trim((string) $l->codigo_conta)] = true;
                }
                $comp = bcadd($comp, $deb->filter(fn ($l) => $l->reconciliacao_codigo)->reduce(fn ($s, $l) => bcadd($s, self::dinheiro($l->valor), 2), '0.00'), 2);
                $recConta = bcadd($recConta, $ls->filter(fn ($l) => $l->tipo_dc === 'C' && ! $l->reconciliacao_codigo)->reduce(fn ($s, $l) => bcadd($s, self::dinheiro($l->valor), 2), '0.00'), 2);
                if ($rc = $recebido[$f->id] ?? null) {
                    $recibos = bcadd($recibos, self::dinheiro($rc->contabilizado), 2);
                    $numsRecibos[] = $rc->numeros;
                }
                foreach ($filhos[$f->id] ?? [] as $idFilho) {
                    if (($V[$idFilho]->tipo_documento ?? null) === 'NC') {
                        $notas = bcadd($notas, self::dinheiro($V[$idFilho]->total_bruto), 2);
                    }
                }
                foreach ($tesPend[$f->numero_documento] ?? [] as $t) {
                    $pendTes = bcadd($pendTes, self::dinheiro($t->valor), 2);
                    $refsPend[] = $t->referencia ?: "TES-{$t->id}";
                }
            }
            $liquidado = self::min($devido, self::soma($comp, $recConta, $recibos, $notas));
            $pagoDoc = $contabilizadas->reduce(fn ($s, $f) => bcadd($s, self::dinheiro($f->valor_pago), 2), '0.00');
            if (bccomp($pagoDoc, $liquidado, 2) > 0) {
                $liquidado = self::min($devido, $pagoDoc);
            }
            $falta = self::max0(bcsub($devido, $liquidado, 2));
            $porReceber = $falta;
            $vias = array_values(array_filter([bccomp($comp, '0', 2) > 0 ? 'compensação' : null, bccomp($recConta, '0', 2) > 0 ? 'recebimento' : null,
                bccomp($recibos, '0', 2) > 0 ? 'recibo' : null, bccomp($notas, '0', 2) > 0 ? 'nota de crédito' : null]));
            $factos = array_merge([self::f('Valor a receber', $devido, 'kz'), self::f('Recebido', $liquidado, 'kz'), self::f('Vias', $vias ? implode(', ', $vias) : '—')],
                bccomp($falta, '0.01', 2) > 0 ? [self::f('Em falta', $falta, 'kz')] : [], $numsRecibos ? [self::f('Recibo(s)', self::lista(explode(', ', implode(', ', $numsRecibos))))] : [],
                $refsPend ? [self::f('Recebimento(s) por integrar', self::lista($refsPend))] : []);
            if (bccomp($falta, '0.01', 2) <= 0) {
                $E['rec'] = self::feito($vias ? 'Liquidado por '.implode(' e ', $vias) : 'Liquidado', $factos);
            } elseif (bccomp($pendTes, '0', 2) > 0) {
                $E['rec'] = self::etapa('curso', 'Recebimento por integrar', $factos, [self::aviso('Recebimento registado na Tesouraria ainda por integrar ('.self::lista($refsPend).').')],
                    [self::accao('Integrar na Tesouraria', 'teso_contab_integracao', true)]);
            } else {
                $numsF = $contabilizadas->pluck('numero_documento')->filter();
                $E['rec'] = self::etapa('curso', bccomp($liquidado, '0', 2) > 0 ? 'Recebido parcialmente' : 'Aguarda recebimento', $factos,
                    [self::aviso('Faltam '.self::kz($falta).' da(s) factura(s) '.self::lista($numsF).'. Receba na Tesouraria ou compense no extracto da conta '.(implode(', ', array_keys($contas)) ?: 'do cliente').'.')],
                    [self::accao('Importar pendentes na Tesouraria', 'teso_gestao_recebimentos', true), self::accao('Ver extracto por compensar', 'relatorios_contabeis')]);
            }
        }
        $datas = $docs->map(fn ($s) => substr((string) $s->data_emissao, 0, 10))->filter()->sort()->values();
        $valor = bccomp($totalFact, '0', 2) !== 0 ? $totalFact : self::dinheiro(($encs[0] ?? $orcs[0] ?? $raiz)->total_bruto);
        $principal = $facts[0] ?? null;

        return [
            'chave' => 'venda-'.$raiz->id, 'titulo' => ($principal?->numero_documento) ?: ($raiz->numero_documento ?: "#{$raiz->id}"),
            'subtitulo' => implode(' · ', array_filter([$principal && $principal->id !== $raiz->id ? $raiz->numero_documento : null, $raiz->cliente])),
            'data' => $datas->last(), 'valor' => $valor, 'valor_pendente' => $porReceber, 'etapas' => $E,
            'documentos' => $docs->map(fn ($s) => ['tipo' => 'venda', 'id' => $s->id, 'tipo_documento' => $s->tipo_documento, 'numero' => $s->numero_documento])->values()->all(),
        ] + self::contagem($E);
    }
}
