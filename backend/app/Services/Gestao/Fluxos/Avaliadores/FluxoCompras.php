<?php

namespace App\Services\Gestao\Fluxos\Avaliadores;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fluxo de compras (carregar/avaliar, js/fluxo_compras.js:38-335): do pedido interno ao pagamento do fornecedor.
 * Um processo = pedido interno (com as propostas, a encomenda, as recepções e as facturas que dele resultam), ou encomenda
 * sem pedido, ou factura directa sem encomenda; pedidos rejeitados e documentos cancelados/anulados ficam de fora.
 * Etapas: pedido interno (com a deliberação por valor) → prospecção e adjudicação → encomenda → recepção → factura e
 * contabilização → pagamento. Pagamento (igual ao legado): créditos na conta do fornecedor (classe 3, excepto 34) no
 * lançamento da factura, liquidados por compensação ou por débitos na mesma conta com o número da factura (pagamento
 * integrado da Tesouraria), consumidos factura a factura; pagamentos na Tesouraria por integrar ficam assinalados.
 * O lançamento da factura é o do número de lançamento da contabilização (ADR-025) ou, nas facturas migradas, o da origem
 * FATURA_COMPRA com o id da factura.
 * Diferença de dados: as linhas das recepções não existem como tabela própria; «por validar no armazém» = recepção não
 * validada de uma encomenda com artigos que movimentam stock (o legado lia delivery_items).
 */
final class FluxoCompras extends AvaliadorFluxo
{
    public function temActividade(): bool
    {
        $e = $this->empresa();

        return DB::table('pedidos_compra')->where('empresa_id', $e)->exists() || DB::table('encomendas_compra')->where('empresa_id', $e)->exists()
            || DB::table('faturas_compra')->where('empresa_id', $e)->exists();
    }

    public function avaliar(): array
    {
        $e = $this->empresa();
        $cancel = fn ($s) => (bool) preg_match('/REJEIT|CANCEL|ANULAD/i', (string) $s);
        $P = DB::table('pedidos_compra')->where('empresa_id', $e)->orderBy('id')->get();
        $Q = DB::table('cotacoes_compra')->where('empresa_id', $e)->get();
        $O = DB::table('encomendas_compra')->where('empresa_id', $e)->orderBy('id')->get();
        $R = DB::table('rececoes_compra')->where('empresa_id', $e)->get();
        $F = DB::table('faturas_compra')->where('empresa_id', $e)->get();
        $terc = DB::table('terceiros')->where('empresa_id', $e)->whereIn('id', $O->pluck('fornecedor_id')->merge($F->pluck('fornecedor_id'))->merge($Q->pluck('fornecedor_id'))->filter()->unique())
            ->get(['id', 'nome', 'codigo_conta'])->keyBy('id');
        $itensEnc = DB::table('itens_compra as i')->leftJoin('produtos as p', 'p.id', '=', 'i.produto_id')->where('i.empresa_id', $e)->where('i.tipo_documento_origem', 'ENCOMENDA')
            ->whereNotNull('i.encomenda_compra_id')->get(['i.encomenda_compra_id', 'i.quantidade', 'i.quantidade_recebida', 'i.preco_unitario', 'p.movimenta_stock'])->groupBy('encomenda_compra_id');
        $numeros = $F->pluck('numero_fatura')->filter()->unique()->values();
        $lans = $F->pluck('numero_lan_contabilizacao')->filter()->unique()->values();
        $linhasFat = DB::table('lancamentos_contabeis')->where('empresa_id', $e)->whereNull('estornado_por_id')->whereNull('estorno_de_id')
            ->where(fn ($q) => $q->whereIn('numero_lan', $lans)->orWhere('tipo_documento_origem', 'FATURA_COMPRA'))
            ->get(['numero_lan', 'tipo_documento_origem', 'documento_origem_id', 'codigo_conta', 'tipo_dc', 'valor', 'numero_documento', 'reconciliacao_codigo']);
        $porLan = $linhasFat->whereNotNull('numero_lan')->groupBy('numero_lan');
        $porOrigem = $linhasFat->where('tipo_documento_origem', 'FATURA_COMPRA')->groupBy('documento_origem_id');
        $debitos = [];
        if ($numeros->isNotEmpty()) {
            foreach (DB::table('lancamentos_contabeis')->where('empresa_id', $e)->where('tipo_dc', 'D')->whereRaw("TRIM(codigo_conta) LIKE '3%'")->whereIn('numero_documento', $numeros)
                ->where(fn ($q) => $q->whereNull('tipo_documento_origem')->orWhere('tipo_documento_origem', '<>', 'FATURA_COMPRA'))
                ->where(fn ($q) => $q->whereNull('numero_lan')->orWhereNotIn('numero_lan', $lans))
                ->groupBy(DB::raw('TRIM(codigo_conta)'), 'numero_documento')->selectRaw('TRIM(codigo_conta) AS c, numero_documento AS d, SUM(valor) AS v')->get() as $r) {
                $debitos["{$r->c}|{$r->d}"] = self::dinheiro($r->v);
            }
        }
        $tesPend = $numeros->isEmpty() ? collect() : DB::table('itens_documento_tesouraria as i')->join('documentos_tesouraria as d', 'd.id', '=', 'i.documento_tesouraria_id')
            ->where('d.empresa_id', $e)->where('d.tipo', 'PAGAMENTO')->whereNotIn('d.estado', ['ANULADO', 'INTEGRADO'])->where('i.tipo_dc', 'D')->whereIn('i.numero_documento', $numeros)
            ->get(['i.numero_documento', 'i.valor', 'd.id', 'd.referencia'])->groupBy('numero_documento');

        $encPorPedido = $O->whereNotNull('pedido_compra_id')->groupBy('pedido_compra_id');
        $propPorPedido = $Q->whereNotNull('pedido_compra_id')->groupBy('pedido_compra_id');
        $recPorEnc = $R->whereNotNull('encomenda_compra_id')->groupBy('encomenda_compra_id');
        $factPorEnc = $F->whereNotNull('encomenda_compra_id')->groupBy('encomenda_compra_id');
        $usadas = [];
        $grupos = [];
        foreach ($P as $p) {
            if ($cancel($p->estado)) {
                continue;
            }
            $encs = ($encPorPedido[$p->id] ?? collect())->reject(fn ($o) => $cancel($o->estado))->values();
            foreach ($encs as $o) {
                $usadas[$o->id] = true;
            }
            $grupos[] = ['chave' => 'pedido-'.$p->id, 'pedido' => $p, 'propostas' => $propPorPedido[$p->id] ?? collect(), 'encomendas' => $encs, 'directas' => collect()];
        }
        foreach ($O as $o) {
            if (! isset($usadas[$o->id]) && ! $cancel($o->estado)) {
                $grupos[] = ['chave' => 'encomenda-'.$o->id, 'pedido' => null, 'propostas' => collect(), 'encomendas' => collect([$o]), 'directas' => collect()];
            }
        }
        $idsEnc = $O->pluck('id')->flip();
        foreach ($F as $f) {
            if (! ($f->encomenda_compra_id && isset($idsEnc[$f->encomenda_compra_id])) && ! $cancel($f->estado)) {
                $grupos[] = ['chave' => 'fatura-'.$f->id, 'pedido' => null, 'propostas' => collect(), 'encomendas' => collect(), 'directas' => collect([$f])];
            }
        }
        $processos = [];
        foreach ($grupos as $g) {
            $g['recepcoes'] = $g['encomendas']->flatMap(fn ($o) => $recPorEnc[$o->id] ?? collect())->values();
            $g['facturas'] = $g['encomendas']->flatMap(fn ($o) => $factPorEnc[$o->id] ?? collect())->merge($g['directas'])->reject(fn ($f) => $cancel($f->estado))->values();
            $processos[] = $this->processo($g, $terc, $itensEnc, $porLan, $porOrigem, $debitos, $tesPend);
        }
        usort($processos, fn ($a, $b) => strcmp((string) $b['data'], (string) $a['data']));
        $porPagar = array_reduce($processos, fn ($s, $p) => bcadd($s, $p['valor_pendente'], 2), '0.00');

        return ['processos' => $processos, 'kpis' => array_merge(self::kpisBase($processos, 6), [
            self::kpi('por_aprovar', 'Pedidos por aprovar', count(array_filter($processos, fn ($p) => $p['etapas']['ped']['estado'] === 'curso'))),
            self::kpi('por_pagar', 'Valor por pagar', $porPagar, 'kz', bccomp($porPagar, '0.01', 2) > 0),
        ])];
    }

    private function processo(array $pr, Collection $terc, Collection $itensEnc, Collection $porLan, Collection $porOrigem, array &$debitos, Collection $tesPend): array
    {
        $E = [];
        $enc = $pr['encomendas'][0] ?? null;
        $adj = $pr['propostas']->first(fn ($q) => $q->estado === 'ADJUDICADO');
        $fornId = $enc?->fornecedor_id ?: ($pr['facturas'][0]->fornecedor_id ?? null) ?: $adj?->fornecedor_id;
        $forn = $terc[$fornId] ?? null;
        $pedidoTxt = $pr['pedido'] ? ($pr['pedido']->numero_pedido ?: 'Pedido #'.$pr['pedido']->id) : '';
        // 1. Pedido interno
        if (! $pr['pedido']) {
            $E['ped'] = self::naoAplica($enc ? 'Encomenda sem pedido' : 'Compra directa');
        } else {
            $p = $pr['pedido'];
            $st = strtoupper((string) ($p->estado ?: 'PENDENTE'));
            $dl = json_decode((string) $p->deliberacao, true) ?: null;
            $etapasDl = $dl['etapas'] ?? [];
            $pend = collect($etapasDl)->firstWhere('estado', 'PENDENTE');
            $factos = array_merge([self::f('Pedido', $pedidoTxt), self::f('Requisitante', $p->nome_requerente ?: '—'), self::f('Data', $p->data, 'data')], $p->descricao ? [self::f('Descrição', $p->descricao)] : [],
                $dl ? [self::f('Valor estimado', self::dinheiro($dl['valor'] ?? 0), 'kz'), self::f('Níveis aprovados', collect($etapasDl)->where('estado', 'APROVADO')->count().' de '.count($etapasDl))] : []);
            $E['ped'] = $st === 'PENDENTE'
                ? self::etapa('curso', $pend ? "Aguarda nível {$pend['ordem']}: {$pend['nome']}" : 'Por aprovar', $factos,
                    $pend ? [self::aviso('Aprovação pendente de '.($pend['nome_aprovador'] ?? $pend['nome']).(! empty($pend['revisao']) ? ' (revisão pedida na adjudicação, porque a proposta passou de escalão)' : '').'.')] : [],
                    [self::accao('Aprovar em Pedidos Internos', 'compras_pedidos', true)])
                : self::feito($st === 'ADJUDICADO' ? 'Aprovado e adjudicado' : 'Aprovado', $factos, [self::accao('Abrir Pedidos Internos', 'compras_pedidos')]);
        }
        // 2. Prospecção e adjudicação
        $nProp = $pr['propostas']->count();
        if (! $pr['pedido']) {
            $E['prosp'] = self::naoAplica();
        } elseif ($E['ped']['estado'] !== 'concluida') {
            $E['prosp'] = self::porFazer('Depois de aprovar');
        } elseif ($adj) {
            $nomeAdj = $terc[$adj->fornecedor_id]->nome ?? 'Adjudicado';
            $E['prosp'] = self::feito($nomeAdj, [self::f('Propostas recebidas', $nProp, 'num'), self::f('Adjudicado a', $nomeAdj), self::f('Valor da proposta', self::dinheiro($adj->montante_total), 'kz')]);
        } elseif ($pr['propostas']->contains(fn ($q) => $q->estado === 'PROPOSTA_ADJUDICACAO')) {
            $E['prosp'] = self::etapa('curso', 'Adjudicação por aprovar', [self::f('Propostas recebidas', $nProp, 'num')], [], [self::accao('Aprovar adjudicação em Prospeção', 'compras_prospeccao', true)]);
        } elseif ($nProp) {
            $E['prosp'] = self::etapa('curso', "{$nProp} proposta(s) em análise", [self::f('Propostas recebidas', $nProp, 'num')], [], [self::accao('Comparar e adjudicar', 'compras_prospeccao', true)]);
        } else {
            $E['prosp'] = self::etapa('curso', 'Aguarda propostas', [], [], [self::accao('Registar propostas em Prospeção', 'compras_prospeccao', true)]);
        }
        // 3. Encomenda
        $totalEnc = '0.00';
        if (! $enc) {
            $E['enc'] = ! $pr['pedido'] ? self::naoAplica()
                : ($E['prosp']['estado'] === 'concluida' ? self::etapa('curso', 'Por encomendar', [], [], [self::accao('Abrir Encomendas', 'compras_encomendas', true)]) : self::porFazer('Depois de adjudicar'));
        } else {
            $itens = $itensEnc[$enc->id] ?? collect();
            $totalEnc = self::somar($itens, fn ($i) => bcmul((string) $i->quantidade, (string) $i->preco_unitario, 4));
            $E['enc'] = self::feito($enc->numero_encomenda ?: "Encomenda #{$enc->id}", array_merge([self::f('Encomenda', $enc->numero_encomenda ?: "#{$enc->id}"), self::f('Fornecedor', $forn->nome ?? '—'),
                self::f('Data', $enc->data, 'data'), self::f('Valor (sem IVA)', $totalEnc, 'kz')], $enc->contrato_fornecedor_id ? [self::f('Contrato', '#'.$enc->contrato_fornecedor_id)] : []));
        }
        // 4. Recepção
        if (! $enc) {
            $E['rec'] = $pr['pedido'] ? self::porFazer('Depois de encomendar') : self::naoAplica('Compra directa');
        } else {
            $itens = $itensEnc[$enc->id] ?? collect();
            $qEnc = (float) $itens->sum(fn ($i) => (float) $i->quantidade);
            $qRec = (float) $itens->sum(fn ($i) => min((float) $i->quantidade_recebida, (float) $i->quantidade));
            $recs = $pr['recepcoes'];
            $guias = $recs->map(fn ($r) => $r->numero_rececao ?: ($r->numero_entrega ?: "#{$r->id}"));
            $stock = $itens->contains(fn ($i) => (bool) $i->movimenta_stock);
            $porValidar = $recs->filter(fn ($r) => ! $r->validado && ! preg_match('/VALID/i', (string) $r->estado) && $stock);
            $total = $enc->estado === 'RECEBIDO' || ($qEnc > 0 && $qRec >= $qEnc - 0.0001);
            if ($recs->isEmpty()) {
                $E['rec'] = self::etapa('curso', 'Por receber', [self::f('Quantidade encomendada', $qEnc, 'num')], [], [self::accao('Registar recepção', 'compras_rececoes', true)]);
            } elseif (! $total) {
                $E['rec'] = self::etapa('curso', 'Recepção parcial', [self::f('Recebido', "{$qRec} de {$qEnc}"), self::f('Guia(s)', self::lista($guias))], [], [self::accao('Registar o restante', 'compras_rececoes', true)]);
            } elseif ($porValidar->isNotEmpty()) {
                $E['rec'] = self::etapa('curso', 'Por validar no armazém', [self::f('Guia(s) por validar', self::lista($porValidar->map(fn ($r) => $r->numero_rececao ?: ($r->numero_entrega ?: "#{$r->id}"))))], [],
                    [self::accao('Validar entradas no Armazém', 'armazem_rececoes', true)]);
            } else {
                $E['rec'] = self::feito('Recebida', [self::f('Guia(s)', self::lista($guias)), self::f('Última recepção', $recs->pluck('data')->filter()->sort()->last(), 'data')]);
            }
        }
        // 5. Factura e contabilização
        $facts = $pr['facturas'];
        $numsF = self::lista($facts->map(fn ($f) => $f->numero_fatura ?: "#{$f->id}"));
        $totalFact = self::somar($facts, fn ($f) => $f->montante_total);
        $linhasDe = fn ($f) => ($f->numero_lan_contabilizacao ? ($porLan[$f->numero_lan_contabilizacao] ?? collect()) : collect())->merge($porOrigem[(string) $f->id] ?? collect());
        if ($facts->isEmpty()) {
            $E['fact'] = $enc ? ($E['rec']['estado'] === 'concluida' ? self::etapa('curso', 'Por facturar', [], [], [self::accao('Registar a factura do fornecedor', 'compras_faturacao', true)]) : self::porFazer('Depois de receber'))
                : self::porFazer('Depois de encomendar');
        } else {
            $porContab = $facts->filter(fn ($f) => ! $f->contabilizado);
            $lansF = $facts->flatMap(fn ($f) => $linhasDe($f)->pluck('numero_lan'))->filter()->unique()->implode(', ');
            $semConta = $forn && ! trim((string) $forn->codigo_conta);
            $E['fact'] = $porContab->isNotEmpty()
                ? self::etapa($semConta ? 'bloqueada' : 'curso', $porContab->count().' por contabilizar', [self::f('Factura(s)', $numsF), self::f('Valor com IVA', $totalFact, 'kz')],
                    $semConta ? [self::erro("O fornecedor {$forn->nome} não tem conta contabilística: a factura não pode ser contabilizada.")] : [],
                    $semConta ? [self::accao('Corrigir o fornecedor', 'compras_fornecedores', true), self::accao('Abrir Faturação', 'compras_faturacao')] : [self::accao('Contabilizar em Faturação', 'compras_faturacao', true)])
                : self::feito($numsF, [self::f('Factura(s)', $numsF), self::f('Valor com IVA', $totalFact, 'kz'), self::f('Lançamento(s)', $lansF ?: '—')]);
        }
        // 6. Pagamento
        $porPagar = '0.00';
        $contab = $facts->filter(fn ($f) => $f->contabilizado)->values();
        if ($contab->isEmpty()) {
            $E['pag'] = self::porFazer('Depois de contabilizar');
        } else {
            [$devido, $comp, $pago, $pendTes] = ['0.00', '0.00', '0.00', '0.00'];
            $refs = [];
            $contas = [];
            foreach ($contab as $f) {
                foreach ($linhasDe($f)->filter(fn ($l) => $l->tipo_dc === 'C' && str_starts_with(trim((string) $l->codigo_conta), '3') && ! str_starts_with(trim((string) $l->codigo_conta), '34')) as $l) {
                    $v = self::dinheiro($l->valor);
                    $devido = bcadd($devido, $v, 2);
                    $c = trim((string) $l->codigo_conta);
                    $contas[$c] = true;
                    if ($l->reconciliacao_codigo) {
                        $comp = bcadd($comp, $v, 2);

                        continue;
                    }
                    $k = "{$c}|{$l->numero_documento}";
                    $usa = self::min($v, $debitos[$k] ?? '0.00');
                    $pago = bcadd($pago, $usa, 2);
                    $debitos[$k] = bcsub($debitos[$k] ?? '0.00', $usa, 2);
                }
                foreach ($tesPend[$f->numero_fatura] ?? [] as $t) {
                    $pendTes = bcadd($pendTes, self::dinheiro($t->valor), 2);
                    $refs[] = $t->referencia ?: "TES-{$t->id}";
                }
            }
            $liquidado = self::min($devido, bcadd($comp, $pago, 2));
            $falta = self::max0(bcsub($devido, $liquidado, 2));
            $porPagar = $falta;
            $via = implode(' e ', array_filter([bccomp($comp, '0', 2) > 0 ? 'compensação' : null, bccomp($pago, '0', 2) > 0 ? 'pagamento' : null]));
            $factos = array_merge([self::f('Valor a pagar', $devido, 'kz'), self::f('Liquidado', $liquidado, 'kz'), self::f('Via', $via ?: '—')],
                bccomp($falta, '0.01', 2) > 0 ? [self::f('Em falta', $falta, 'kz')] : [], $refs ? [self::f('Pagamento(s) por integrar', self::lista($refs))] : []);
            if (bccomp($devido, '0', 2) <= 0) {
                $E['pag'] = self::etapa('curso', 'Sem linha do fornecedor', [], [self::aviso('O lançamento da factura não tem linha a crédito numa conta de fornecedor (classe 3). Verifique a contabilização.')],
                    [self::accao('Abrir lançamentos', 'lancamentos', true)]);
            } elseif (bccomp($falta, '0.01', 2) <= 0) {
                $E['pag'] = self::feito($via ? "Liquidado por {$via}" : 'Liquidado', $factos, [self::accao('Ver extracto de conta', 'relatorios_contabeis')]);
            } elseif (bccomp($pendTes, '0', 2) > 0) {
                $E['pag'] = self::etapa('curso', 'Pagamento por integrar', $factos, [self::aviso('Pagamento registado na Tesouraria ainda por integrar ('.self::lista($refs).').')],
                    [self::accao('Integrar na Tesouraria', 'teso_contab_integracao', true)]);
            } else {
                $E['pag'] = self::etapa('curso', bccomp($liquidado, '0', 2) > 0 ? 'Pago parcialmente' : 'Aguarda pagamento', $factos,
                    [self::aviso('Faltam '.self::kz($falta).' da(s) factura(s) '.self::lista($contab->pluck('numero_fatura')).'. Pague na Tesouraria ou compense no extracto da conta '.implode(', ', array_keys($contas)).'.')],
                    [self::accao('Importar pendentes na Tesouraria', 'teso_gestao_pagamentos', true), self::accao('Ver extracto por compensar', 'relatorios_contabeis')]);
            }
        }
        $datas = collect([$pr['pedido']->data ?? null])->merge($pr['encomendas']->pluck('data'))->merge($pr['recepcoes']->pluck('data'))->merge($facts->pluck('data'))
            ->filter()->map(fn ($d) => substr((string) $d, 0, 10))->sort()->values();
        $titulo = ($enc?->numero_encomenda) ?: (($facts[0]->numero_fatura ?? null) ?: ($pedidoTxt ?: $pr['chave']));
        $valor = bccomp($totalFact, '0', 2) !== 0 ? $totalFact : (bccomp($totalEnc, '0', 2) !== 0 ? $totalEnc : ($adj ? self::dinheiro($adj->montante_total) : '0.00'));

        return [
            'chave' => $pr['chave'], 'titulo' => $titulo, 'subtitulo' => implode(' · ', array_filter([$pedidoTxt && $titulo !== $pedidoTxt ? $pedidoTxt : null, $forn->nome ?? null])),
            'data' => $datas->last(), 'valor' => $valor, 'valor_pendente' => $porPagar, 'etapas' => $E,
            'documentos' => array_merge($pr['pedido'] ? [['tipo' => 'pedido_compra', 'id' => $pr['pedido']->id, 'numero' => $pedidoTxt]] : [],
                $pr['encomendas']->map(fn ($o) => ['tipo' => 'encomenda_compra', 'id' => $o->id, 'numero' => $o->numero_encomenda])->all(),
                $pr['recepcoes']->map(fn ($r) => ['tipo' => 'rececao_compra', 'id' => $r->id, 'numero' => $r->numero_rececao ?: $r->numero_entrega])->all(),
                $facts->map(fn ($f) => ['tipo' => 'fatura_compra', 'id' => $f->id, 'numero' => $f->numero_fatura])->all()),
        ] + self::contagem($E);
    }
}
