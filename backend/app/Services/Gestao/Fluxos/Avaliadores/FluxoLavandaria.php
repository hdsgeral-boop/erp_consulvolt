<?php

namespace App\Services\Gestao\Fluxos\Avaliadores;

use App\Models\PedidoLavandaria;
use App\Services\POS\Lavandaria\RegrasLavandaria;
use App\Services\POS\Lavandaria\ServicoConfigLavandaria;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;

/**
 * Fluxo da lavandaria e alfaiataria (carregar/avaliar, js/fluxo_lavandaria.js:40-250). Um processo é uma ordem de serviço
 * não anulada. Etapas: recepção → orçamento (alfaiataria) → execução → entrega → facturação e contabilização →
 * recebimento ou compensação. Regras do legado, com os dados do módulo (ADR-049):
 *   - estados das linhas: ORCAMENTO, RECEBIDA, EM_EXECUCAO, PRONTA, ENTREGUE, ANULADA; valor das linhas e das taxas pelas
 *     regras do módulo (RegrasLavandaria::valorItem — valor facturável AGT);
 *   - danos e reclamações por tratar (AGUARDA_COMPROVATIVO, COMPROVADO, APROVADA) ficam como pendência na entrega;
 *   - peças prontas há mais dias do que os de armazenagem gratuita são assinaladas;
 *   - documentos = vendas da ordem não anuladas (FT conta corrente, FR venda directa), contabilizados na integração da sessão;
 *   - recebido = pagamentos não anulados da ordem + compensação/recebimentos integrados das FT contabilizadas.
 */
final class FluxoLavandaria extends AvaliadorFluxo
{
    private const DANOS_ABERTOS = ['AGUARDA_COMPROVATIVO', 'COMPROVADO', 'APROVADA'];

    public function __construct(ContextoEmpresa $contexto, private readonly ServicoConfigLavandaria $config)
    {
        parent::__construct($contexto);
    }

    public function temActividade(): bool
    {
        return DB::table('pedidos_lavandaria')->where('empresa_id', $this->empresa())->exists();
    }

    public function avaliar(): array
    {
        $e = $this->empresa();
        $def = $this->config->obter();
        $ordens = PedidoLavandaria::query()->where('estado', '<>', 'ANULADA')->orderByDesc('recebido_em')->get();
        $ids = $ordens->pluck('id');
        $clientes = DB::table('terceiros')->where('empresa_id', $e)->whereIn('id', $ordens->pluck('cliente_id')->filter())->pluck('nome', 'id');
        $pagos = DB::table('pagamentos_lavandaria')->where('empresa_id', $e)->whereIn('pedido_lavandaria_id', $ids)->whereRaw("COALESCE(estado, '') <> 'ANULADO'")
            ->get(['pedido_lavandaria_id', 'montante', 'numero_recibo', 'natureza_registo', 'lans_contabilizacao'])->groupBy('pedido_lavandaria_id');
        $danos = DB::table('reclamacoes_lavandaria')->where('empresa_id', $e)->whereIn('pedido_lavandaria_id', $ids)->whereIn('estado', self::DANOS_ABERTOS)
            ->get(['pedido_lavandaria_id', 'nome_item', 'estado'])->groupBy('pedido_lavandaria_id');
        $docs = DB::table('vendas')->where('empresa_id', $e)->whereIn('pedido_lavandaria_id', $ids)->whereRaw("COALESCE(estado, '') !~* 'ANUL'")
            ->get(['id', 'pedido_lavandaria_id', 'tipo_documento', 'numero_documento', 'total_bruto', 'contabilizado', 'pos_lans_contabilizacao', 'numero_lan_contabilizacao'])->groupBy('pedido_lavandaria_id');
        $numerosFT = $docs->flatten(1)->where('tipo_documento', 'FT')->pluck('numero_documento')->filter()->unique()->values();
        $linhas = $numerosFT->isEmpty() ? collect() : DB::table('lancamentos_contabeis')->where('empresa_id', $e)->whereRaw("TRIM(codigo_conta) LIKE '3%'")->whereIn('numero_documento', $numerosFT)
            ->get(['numero_documento', 'codigo_conta', 'tipo_dc', 'valor', 'terceiro_id', 'reconciliacao_codigo'])->groupBy('numero_documento');
        $tesPend = $numerosFT->isEmpty() ? collect() : DB::table('itens_documento_tesouraria as i')->join('documentos_tesouraria as d', 'd.id', '=', 'i.documento_tesouraria_id')
            ->where('d.empresa_id', $e)->where('d.tipo', 'RECEBIMENTO')->whereNotIn('d.estado', ['ANULADO', 'INTEGRADO'])->where('i.tipo_dc', 'C')->whereIn('i.numero_documento', $numerosFT)
            ->get(['i.numero_documento', 'i.valor', 'd.id', 'd.referencia'])->groupBy('numero_documento');
        $processos = [];
        foreach ($ordens as $o) {
            $processos[] = $this->ordem($o, $clientes[$o->cliente_id] ?? null, $pagos[$o->id] ?? collect(), $danos[$o->id] ?? collect(), $docs[$o->id] ?? collect(), $linhas, $tesPend, $def);
        }
        $porReceber = array_reduce($processos, fn ($s, $p) => bcadd($s, $p['valor_pendente'], 2), '0.00');

        return ['processos' => $processos, 'kpis' => [
            self::kpi('em_curso', 'Ordens em curso', count(array_filter($processos, fn ($p) => $p['_concluidas'] < 6))),
            self::kpi('atrasadas', 'Atrasadas', $n = count(array_filter($processos, fn ($p) => $p['atrasada'])), 'num', $n > 0),
            self::kpi('por_levantar', 'Prontas por levantar', count(array_filter($processos, fn ($p) => $p['por_levantar']))),
            self::kpi('por_receber', 'Valor por receber', $porReceber, 'kz', bccomp($porReceber, '0.01', 2) > 0),
        ]];
    }

    private function ordem(PedidoLavandaria $o, ?string $cliente, $pagos, $danos, $docs, $linhasPorDoc, $tesPend, array $def): array
    {
        $E = [];
        $itensTodos = $o->itens ?? [];
        $itens = array_values(array_filter($itensTodos, fn ($i) => ($i['estado'] ?? null) !== 'ANULADA'));
        $hoje = self::hoje();
        $agora = now();
        $nPecas = array_sum(array_map(fn ($i) => (int) ($i['numero_pecas'] ?? 1) ?: 1, $itens));
        $abrir = self::accao('Abrir a ordem', 'pos_lavandaria');
        $comDanos = array_filter($itens, fn ($i) => ! empty($i['estado_entrada']) && $i['estado_entrada'] !== RegrasLavandaria::BOM_ESTADO);
        $E['recep'] = self::feito(self::data($o->recebido_em), array_merge([self::f('Ordem', $o->numero_encomenda), self::f('Cliente', $cliente ?: '—'), self::f('Linhas / etiquetas', count($itens).' / '.$nPecas),
            self::f('Prazo prometido', $o->data_prometida?->toDateString(), 'data'), self::f('Recebida por', $o->recebido_por ?: '—')], $o->urgente ? [self::f('Urgência', 'Sim')] : []), [$abrir],
            $comDanos ? [self::aviso(count($comDanos).' peça(s) com danos registados à entrada: '.self::lista(array_map(fn ($i) => trim(($i['nome_peca'] ?? '').' '.($i['nome'] ?? '').' ('.$i['estado_entrada'].')'), $comDanos)).'.')] : []);
        $comOrc = array_values(array_filter($itensTodos, fn ($i) => ! empty($i['requer_orcamento'])));
        if (! $comOrc) {
            $E['orc'] = self::naoAplica('Sem orçamento');
        } else {
            $pend = array_filter($comOrc, fn ($i) => ($i['estado_orcamento'] ?? null) === 'PENDENTE');
            $aprov = array_filter($comOrc, fn ($i) => ($i['estado_orcamento'] ?? null) === 'APROVADO');
            $recus = array_filter($comOrc, fn ($i) => ($i['estado_orcamento'] ?? null) === 'RECUSADO');
            $E['orc'] = $pend
                ? self::etapa('curso', count($pend).' por aprovar', [self::f('Orçamentos', count($comOrc), 'num'), self::f('Aprovados', count($aprov), 'num'), self::f('Recusados', count($recus), 'num')],
                    [self::aviso('Aguarda aprovação do cliente: '.self::lista(array_map(fn ($i) => trim(($i['nome_peca'] ?? '').' '.($i['nome'] ?? '').' ('.self::kz($i['valor_orcamento'] ?? 0).')'), $pend)).'.')],
                    [self::accao('Aprovar ou recusar na ordem', 'pos_lavandaria', true)])
                : self::feito(count($aprov).' aprovado(s)'.($recus ? ' · '.count($recus).' recusado(s)' : ''), [self::f('Aprovados', count($aprov), 'num'), self::f('Recusados', count($recus), 'num'),
                    self::f('Valor aprovado', self::somar($aprov, fn ($i) => $i['valor_orcamento'] ?? 0), 'kz')]);
        }
        $aExec = array_values(array_filter($itens, fn ($i) => ($i['estado'] ?? null) !== 'ORCAMENTO'));
        $prontasOuEnt = array_filter($aExec, fn ($i) => in_array($i['estado'] ?? null, ['PRONTA', 'ENTREGUE'], true));
        $prometida = $o->data_prometida?->toDateString();
        $atrasada = $prometida && $prometida < $hoje && count($prontasOuEnt) < count($aExec);
        if (! $aExec) {
            $E['exec'] = self::porFazer('Depois do orçamento');
        } elseif (count($prontasOuEnt) === count($aExec)) {
            $E['exec'] = self::feito('Tudo pronto', [self::f('Peças prontas', count($prontasOuEnt).' de '.count($aExec)), self::f('Responsável', $o->nome_atribuido ?: '—')]);
        } else {
            $problemas = [];
            if ($atrasada) {
                $problemas[] = self::erro('Atrasada: o prazo prometido era '.self::data($prometida).' ('.RegrasLavandaria::diasDesde($o->data_prometida->toIso8601String(), $agora).' dia(s)).');
            }
            if (! $o->colaborador_atribuido_id && ! $o->nome_atribuido) {
                $problemas[] = self::aviso('A ordem não tem responsável atribuído.');
            }
            $E['exec'] = self::etapa('curso', count($prontasOuEnt).' de '.count($aExec).' prontas', [self::f('Em execução', count(array_filter($aExec, fn ($i) => $i['estado'] === 'EM_EXECUCAO')), 'num'),
                self::f('Por iniciar', count(array_filter($aExec, fn ($i) => $i['estado'] === 'RECEBIDA')), 'num'), self::f('Prontas', count($prontasOuEnt), 'num'),
                self::f('Responsável', $o->nome_atribuido ?: 'Sem responsável'), self::f('Prazo prometido', $prometida, 'data')], $problemas, [self::accao('Actualizar estados na ordem', 'pos_lavandaria', true)]);
        }
        $entregues = array_filter($aExec, fn ($i) => ($i['estado'] ?? null) === 'ENTREGUE');
        $prontas = array_filter($aExec, fn ($i) => ($i['estado'] ?? null) === 'PRONTA');
        $livres = (int) $def['dias_armazenagem_gratis'];
        $foraPrazo = array_filter($prontas, fn ($i) => RegrasLavandaria::diasDesde($i['pronta_em'] ?? null, $agora) > $livres);
        $probDanos = $danos->map(fn ($d) => self::aviso("Dano/reclamação por tratar ({$d->nome_item}): ".match ($d->estado) {
            'APROVADA' => 'indemnização aprovada por pagar', 'COMPROVADO' => 'comprovativo apresentado, falta decidir', default => 'aguarda comprovativo de custo'
        }.'.'))->values()->all();
        if ($aExec && count($entregues) === count($aExec)) {
            $E['ent'] = self::etapa($probDanos ? 'curso' : 'concluida', 'Entregue '.self::data($o->entregue_em), [self::f('Peças entregues', count($entregues), 'num'), self::f('Data', $o->entregue_em, 'data')], $probDanos,
                $probDanos ? [self::accao('Tratar em Danos e reclamações', 'pos_lavandaria', true)] : []);
        } elseif ($prontas || $entregues) {
            $prob = $foraPrazo ? [self::aviso(count($foraPrazo)." peça(s) prontas há mais de {$livres} dias".($def['taxa_armazenagem_ativa'] ? ': na entrega é cobrada taxa de armazenagem' : '').'.')] : [];
            $E['ent'] = self::etapa('curso', $entregues ? 'Entrega parcial' : 'Pronta, por levantar', [self::f('Entregues', count($entregues).' de '.count($aExec)), self::f('Prontas por levantar', count($prontas), 'num'),
                self::f('Pronta há', $prontas ? max(array_map(fn ($i) => RegrasLavandaria::diasDesde($i['pronta_em'] ?? null, $agora), $prontas)).' dia(s)' : '—')],
                array_merge($prob, $probDanos), [self::accao('Entregar na Frente de Caixa', 'pos', true), $abrir]);
        } else {
            $E['ent'] = self::etapa('fazer', 'Depois de pronta', [], $probDanos);
        }
        $facturado = self::somar($docs->whereIn('tipo_documento', ['FT', 'FR']), fn ($v) => $v->total_bruto);
        $valorOrdem = bcadd(self::somar($aExec, fn ($i) => RegrasLavandaria::valorItem($i)), self::somar(array_filter($o->extras ?? [], fn ($x) => empty($x['cancelado'])), fn ($x) => $x['valor'] ?? 0), 2);
        $porFacturar = self::max0(bcsub($valorOrdem, $facturado, 2));
        $porContab = $docs->filter(fn ($v) => ! $v->contabilizado);
        $nums = self::lista($docs->pluck('numero_documento'));
        $irIntegracao = self::accao('Contabilizar na Integração do POS', 'pos_integracao', true);
        if ($docs->isEmpty()) {
            $E['fact'] = $entregues || $o->modo_faturacao === 'RECEPCAO'
                ? self::etapa('curso', 'Por facturar', [self::f('Valor da ordem', $valorOrdem, 'kz')], [], [self::accao('Facturar na ordem', 'pos_lavandaria', true)]) : self::porFazer('Na entrega');
        } elseif ($porContab->isNotEmpty()) {
            $E['fact'] = self::etapa('curso', $porContab->count().' por contabilizar', array_merge([self::f('Documento(s)', $nums), self::f('Facturado', $facturado, 'kz')],
                bccomp($porFacturar, '0.01', 2) > 0 ? [self::f('Ainda por facturar', $porFacturar, 'kz')] : []),
                [self::aviso('Contabilizados com a integração da sessão POS: '.self::lista($porContab->pluck('numero_documento')).'.')], [$irIntegracao]);
        } elseif (bccomp($porFacturar, '0.01', 2) > 0 && ($entregues || $prontas)) {
            $E['fact'] = self::etapa('curso', 'Falta facturar '.self::kz($porFacturar), [self::f('Documento(s)', $nums), self::f('Facturado', $facturado, 'kz'), self::f('Valor da ordem', $valorOrdem, 'kz')], [],
                [self::accao('Facturar o restante', 'pos_lavandaria', true)]);
        } else {
            $lans = $docs->flatMap(fn ($v) => json_decode((string) $v->pos_lans_contabilizacao, true) ?: array_filter([$v->numero_lan_contabilizacao]))->unique()->values();
            $E['fact'] = self::feito($nums, [self::f('Documento(s)', $nums), self::f('Tipo', self::lista($docs->pluck('tipo_documento'))), self::f('Facturado', $facturado, 'kz'),
                self::f('Lançamento(s)', $lans->implode(', ') ?: '—')]);
        }
        $porReceber = '0.00';
        $pago = self::somar($pagos, fn ($p) => $p->montante);
        $porContabPag = $pagos->filter(fn ($p) => $p->natureza_registo !== 'FR' && ! (json_decode((string) $p->lans_contabilizacao, true) ?: []));
        if (bccomp($facturado, '0', 2) <= 0 && bccomp($pago, '0', 2) <= 0) {
            $E['rec'] = self::porFazer('Depois de facturar');
        } else {
            [$comp, $recConta, $pendTes] = ['0.00', '0.00', '0.00'];
            $contas = [];
            $refs = [];
            foreach ($docs->where('tipo_documento', 'FT')->where('contabilizado', true) as $v) {
                $ls = ($linhasPorDoc[$v->numero_documento] ?? collect())->filter(fn ($l) => ! $o->cliente_id || ! $l->terceiro_id || (int) $l->terceiro_id === (int) $o->cliente_id);
                foreach ($ls->where('tipo_dc', 'D') as $l) {
                    $contas[trim((string) $l->codigo_conta)] = true;
                    if ($l->reconciliacao_codigo) {
                        $comp = bcadd($comp, self::dinheiro($l->valor), 2);
                    }
                }
                $recConta = bcadd($recConta, self::somar($ls->filter(fn ($l) => $l->tipo_dc === 'C' && ! $l->reconciliacao_codigo), fn ($l) => $l->valor), 2);
                foreach ($tesPend[$v->numero_documento] ?? [] as $t) {
                    $pendTes = bcadd($pendTes, self::dinheiro($t->valor), 2);
                    $refs[] = $t->referencia ?: "TES-{$t->id}";
                }
            }
            $liquidado = self::min(self::max0($facturado), self::soma($pago, $comp, $recConta));
            $falta = self::max0(bcsub($facturado, $liquidado, 2));
            $porReceber = $falta;
            $adiant = self::max0(bcsub($pago, $facturado, 2));
            $vias = array_values(array_filter([bccomp($pago, '0', 2) > 0 ? 'pagamentos na caixa' : null, bccomp($comp, '0', 2) > 0 ? 'compensação' : null, bccomp($recConta, '0', 2) > 0 ? 'recebimento na Tesouraria' : null]));
            $factos = array_merge([self::f('Facturado', $facturado, 'kz'), self::f('Recebido', $liquidado, 'kz'), self::f('Vias', $vias ? implode(', ', $vias) : '—')],
                bccomp($falta, '0.01', 2) > 0 ? [self::f('Em falta', $falta, 'kz')] : [], bccomp($adiant, '0.01', 2) > 0 ? [self::f('Adiantamento por facturar', $adiant, 'kz')] : [],
                [self::f('Recibo(s)', self::lista($pagos->pluck('numero_recibo')) ?: '—')]);
            $prob = $porContabPag->isNotEmpty() ? [self::aviso($porContabPag->count().' recebimento(s) na caixa ainda por contabilizar (integração da sessão POS): '.self::lista($porContabPag->pluck('numero_recibo')).'.')] : [];
            if (bccomp($facturado, '0', 2) > 0 && bccomp($falta, '0.01', 2) <= 0) {
                $E['rec'] = self::etapa($prob ? 'curso' : 'concluida', $vias ? 'Liquidado por '.implode(' e ', $vias) : 'Liquidado', $factos, $prob, $prob ? [$irIntegracao] : []);
            } elseif (bccomp($facturado, '0', 2) <= 0) {
                $E['rec'] = self::etapa('curso', 'Adiantamento recebido', $factos, $prob);
            } elseif (bccomp($pendTes, '0', 2) > 0) {
                $E['rec'] = self::etapa('curso', 'Recebimento por integrar', $factos, array_merge($prob, [self::aviso('Recebimento registado na Tesouraria ainda por integrar ('.self::lista($refs).').')]),
                    [self::accao('Integrar na Tesouraria', 'teso_contab_integracao', true)]);
            } else {
                $naContab = $docs->where('tipo_documento', 'FT')->where('contabilizado', true)->isNotEmpty();
                $E['rec'] = self::etapa('curso', bccomp($liquidado, '0', 2) > 0 ? 'Recebido parcialmente' : 'Aguarda pagamento', $factos, array_merge($prob, [self::aviso($naContab
                    ? 'Faltam '.self::kz($falta).'. Receba na caixa da lavandaria («Receber pagamento»), na Tesouraria, ou compense no extracto da conta '.(implode(', ', array_keys($contas)) ?: 'do cliente').'.'
                    : 'Faltam '.self::kz($falta).'. Receba na caixa da lavandaria («Receber pagamento»); depois de contabilizada, a factura também pode ser recebida na Tesouraria ou compensada.')]),
                    [self::accao('Receber na Frente de Caixa', 'pos', true)]);
            }
        }

        return [
            'chave' => 'ordem-'.$o->id, 'titulo' => $o->numero_encomenda ?: "Ordem #{$o->id}", 'subtitulo' => implode(' · ', array_filter([$cliente, $o->codigo_terminal])),
            'data' => $o->recebido_em?->format('Y-m-d H:i:s'), 'valor' => $valorOrdem, 'valor_pendente' => $porReceber, 'etapas' => $E,
            'documentos' => array_merge([['tipo' => 'pedido_lavandaria', 'id' => $o->id, 'numero' => $o->numero_encomenda]],
                $docs->map(fn ($v) => ['tipo' => 'venda', 'id' => $v->id, 'tipo_documento' => $v->tipo_documento, 'numero' => $v->numero_documento])->values()->all()),
            'atrasada' => (bool) $atrasada, 'por_levantar' => (bool) $prontas, 'urgente' => (bool) $o->urgente, 'prazo' => $prometida,
        ] + self::contagem($E);
    }
}
