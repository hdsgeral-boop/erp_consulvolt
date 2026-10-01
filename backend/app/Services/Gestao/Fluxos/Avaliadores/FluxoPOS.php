<?php

namespace App\Services\Gestao\Fluxos\Avaliadores;

use Illuminate\Support\Facades\DB;

/**
 * Fluxo do POS (carregarPOS/avaliarSessao, js/fluxo_vendas.js:290-488). Um processo é uma sessão de caixa (as 80 mais
 * recentes, como o legado). Etapas: abertura → vendas e fecho (Z) → desvio de caixa → integração contabilística → prestação
 * de contas → compensação das transitórias dos meios de pagamento.
 * Compensação (igual ao legado): para cada conta transitória da sessão (totais por método), os movimentos são as linhas
 * com a sessão e as linhas sem sessão com o número do Z ou o documento de uma transferência; a conta fica compensada com
 * saldo zero, todos os movimentos compensados (reconciliacao_codigo) e pelo menos um movimento. Liquidações (ADR-048) com
 * o documento da tesouraria pendente ou o movimento de caixa por contabilizar aparecem como regularizações por integrar.
 */
final class FluxoPOS extends AvaliadorFluxo
{
    public function temActividade(): bool
    {
        return DB::table('sessoes_pos')->where('empresa_id', $this->empresa())->exists();
    }

    public function avaliar(): array
    {
        $e = $this->empresa();
        $S = DB::table('sessoes_pos')->where('empresa_id', $e)->orderByDesc('aberto_em')->orderByDesc('id')->limit(80)->get();
        $ids = $S->pluck('id');
        $liqs = DB::table('liquidacoes_pos as q')->leftJoin('documentos_tesouraria as d', 'd.id', '=', 'q.documento_tesouraria_id')
            ->leftJoin('movimentos_caixa as m', 'm.id', '=', 'q.movimento_caixa_id')->where('q.empresa_id', $e)->whereIn('q.sessao_pos_id', $ids)->where('q.estado', '<>', 'ANULADO')
            ->get(['q.*', 'd.estado as estado_documento', 'm.contabilizado as movimento_contabilizado'])->groupBy('sessao_pos_id');
        $abertas = DB::table('vendas')->where('empresa_id', $e)->whereIn('sessao_pos_id', $S->where('estado', 'ABERTA')->pluck('id'))->whereRaw("COALESCE(estado, '') !~* '(ANUL|CANCEL)'")
            ->groupBy('sessao_pos_id')->selectRaw('sessao_pos_id, COUNT(*) AS n, SUM(total_bruto) AS total')->get()->keyBy('sessao_pos_id');
        $transitorias = $S->flatMap(fn ($s) => collect(json_decode((string) $s->totais_por_metodo, true) ?: [])->pluck('conta_transitoria'))->filter()->map(fn ($c) => trim((string) $c))->unique()->values();
        $linhas = $transitorias->isEmpty() ? collect() : DB::table('lancamentos_contabeis')->where('empresa_id', $e)->whereIn(DB::raw('TRIM(codigo_conta)'), $transitorias)
            ->get(['codigo_conta', 'tipo_dc', 'valor', 'sessao_pos_id', 'numero_documento', 'reconciliacao_codigo'])->groupBy(fn ($l) => trim((string) $l->codigo_conta));
        $processos = $S->map(fn ($s) => $this->sessao($s, $liqs[$s->id] ?? collect(), $abertas[$s->id] ?? null, $linhas))->values()->all();
        $saldo = array_reduce($processos, fn ($t, $p) => bcadd($t, $p['valor_pendente'], 2), '0.00');

        return ['processos' => $processos, 'kpis' => [
            self::kpi('abertas', 'Caixas abertas', count(array_filter($processos, fn ($p) => $p['etapas']['abert']['estado'] === 'curso'))),
            self::kpi('por_contabilizar', 'Por contabilizar', $n = count(array_filter($processos, fn ($p) => $p['etapas']['integ']['estado'] === 'curso')), 'num', $n > 0),
            self::kpi('por_prestar', 'Por prestar contas', count(array_filter($processos, fn ($p) => $p['etapas']['prest']['estado'] === 'curso'))),
            self::kpi('por_compensar', 'Transitórias por compensar', $n = count(array_filter($processos, fn ($p) => $p['etapas']['comp']['estado'] === 'curso')), 'num', $n > 0),
            self::kpi('saldo_transitorio', 'Saldo transitório', $saldo, 'kz'),
        ]];
    }

    private function sessao(object $s, $liqs, ?object $vendasAbertas, $linhasPorConta): array
    {
        $E = [];
        $aberta = $s->estado === 'ABERTA';
        $semMov = $s->estado_contabilizacao === 'SEM_MOVIMENTO';
        $metodos = json_decode((string) $s->totais_por_metodo, true) ?: [];
        $base = [self::f('Sessão', $s->codigo_sessao ?: "#{$s->id}"), self::f('Terminal', $s->nome_terminal ?: ($s->codigo_terminal ?: '—')), self::f('Operador', $s->nome_operador ?: '—')];
        $E['abert'] = $aberta
            ? self::etapa('curso', 'Aberta desde '.self::data($s->aberto_em), array_merge($base, [self::f('Fundo de caixa', self::dinheiro($s->fundo_maneio_abertura), 'kz')]), [],
                [self::accao('Abrir a Frente de Caixa', 'pos', true)])
            : self::feito(self::data($s->aberto_em), $base);
        if ($aberta) {
            $n = (int) ($vendasAbertas->n ?? 0);
            $E['fecho'] = self::etapa('curso', "{$n} venda(s) · por fechar", [self::f('Vendas', $n, 'num'), self::f('Total vendido', self::dinheiro($vendasAbertas->total ?? 0), 'kz')], [],
                [self::accao('Fechar a caixa na Frente de Caixa', 'pos', true)]);
        } else {
            $E['fecho'] = self::feito($semMov ? 'Fechada sem movimento' : ($s->numero_z ?: 'Fechada'), array_merge([self::f('Relatório Z', $s->numero_z ?: '—'), self::f('Fecho', $s->fechado_em, 'data_hora'),
                self::f('Vendas', (int) $s->numero_vendas, 'num'), self::f('Total', self::dinheiro($s->total_vendas), 'kz')], array_map(fn ($m) => self::f((string) ($m['nome'] ?? $m['tipo'] ?? '—'), self::dinheiro($m['valor'] ?? 0), 'kz'), $metodos)));
        }
        $d = json_decode((string) $s->deliberacao, true) ?: [];
        if ($aberta) {
            $E['desvio'] = self::porFazer('Depois do fecho');
        } elseif ($s->estado_desvio === 'PENDENTE') {
            $E['desvio'] = self::etapa('curso', 'Por deliberar ('.self::kz($s->desvio).')', [self::f('Esperado', self::dinheiro($s->numerario_esperado), 'kz'), self::f('Contado', self::dinheiro($s->numerario_contado), 'kz'),
                self::f('Desvio', self::dinheiro($s->desvio), 'kz'), self::f('Justificação', $s->justificacao ?: '—')], [], [self::accao('Deliberar em Desvios de caixa', 'pos_desvios', true)]);
        } elseif ($s->estado_desvio === 'DELIBERADO') {
            $E['desvio'] = self::feito(($d['decisao'] ?? null) === 'SEM_EFEITO' ? 'Deliberado sem efeito' : 'Deliberado e lançado', array_merge([self::f('Desvio', self::dinheiro($s->desvio), 'kz'),
                self::f('Decisão', $d['decisao'] ?? '—'), self::f('Por', $d['por'] ?? '—')], ! empty($d['numero_lan']) ? [self::f('Lançamento', $d['numero_lan'])] : []));
        } else {
            $E['desvio'] = self::naoAplica('Sem desvio');
        }
        $lans = json_decode((string) $s->lans_contabilizacao, true) ?: [];
        if ($aberta) {
            $E['integ'] = self::porFazer('Depois do fecho');
        } elseif ($semMov) {
            $E['integ'] = self::naoAplica('Sem movimento');
        } elseif ($s->estado_contabilizacao === 'CONTABILIZADA') {
            $E['integ'] = self::feito($lans[0] ?? 'Contabilizada', [self::f('Lançamento(s)', implode(', ', $lans) ?: '—'), self::f('Em', $s->contabilizado_em, 'data_hora'), self::f('Por', $s->contabilizado_por ?: '—')]);
        } else {
            $E['integ'] = self::etapa('curso', 'Por contabilizar', [], [], [self::accao('Contabilizar em Integração contabilística', 'pos_integracao', true)]);
        }
        $tipoTxt = ['NUMERARIO' => 'Numerário → Folha de Caixa', 'TPA' => 'TPA → banco', 'TRANSFERENCIA' => 'Transferência → banco'];
        if ($aberta || $semMov) {
            $E['prest'] = $aberta ? self::porFazer('Depois do fecho') : self::naoAplica('Sem movimento');
        } elseif ($s->estado_contabilizacao !== 'CONTABILIZADA') {
            $E['prest'] = self::porFazer('Depois de contabilizar');
        } else {
            $factos = $liqs->map(fn ($l) => self::f($tipoTxt[$l->natureza_registo] ?? (string) $l->natureza_registo, self::dinheiro($l->montante_bruto), 'kz'))->values()->all();
            $E['prest'] = $s->estado_liquidacao === 'LIQUIDADA'
                ? self::feito('Prestação concluída', $factos)
                : self::etapa('curso', $s->estado_liquidacao === 'PARCIAL' ? 'Prestação parcial' : 'Por prestar contas', $factos,
                    $s->estado_desvio === 'PENDENTE' ? [self::aviso('Delibere o desvio de caixa antes de transferir o numerário para a Folha de Caixa.')] : [],
                    [self::accao('Regularizar em Prestação de contas', 'pos_prestacao', true)]);
        }
        $saldoPorCompensar = '0.00';
        if ($aberta || $semMov) {
            $E['comp'] = $aberta ? self::porFazer('Depois do fecho') : self::naoAplica('Sem movimento');
        } elseif ($s->estado_contabilizacao !== 'CONTABILIZADA') {
            $E['comp'] = self::porFazer('Depois de contabilizar');
        } else {
            $docs = collect(json_decode((string) $s->transferencias, true) ?: [])->pluck('numero_documento')->push($s->numero_z)->filter()->map(fn ($x) => (string) $x)->flip();
            $contas = collect($metodos)->pluck('conta_transitoria')->filter()->map(fn ($c) => trim((string) $c))->unique()->values();
            $tesPend = $liqs->filter(fn ($l) => $l->documento_tesouraria_id && $l->estado_documento === 'PENDENTE');
            $caixaPend = $liqs->filter(fn ($l) => $l->movimento_caixa_id && $l->movimento_contabilizado === false);
            $factos = [];
            $problemas = [];
            $tudo = true;
            foreach ($contas as $conta) {
                $ls = ($linhasPorConta[$conta] ?? collect())->filter(fn ($l) => (int) $l->sessao_pos_id === (int) $s->id || (! $l->sessao_pos_id && isset($docs[(string) $l->numero_documento])));
                $saldo = self::somar($ls, fn ($l) => $l->tipo_dc === 'D' ? $l->valor : -$l->valor);
                $nao = $ls->filter(fn ($l) => ! $l->reconciliacao_codigo)->count();
                $ok = bccomp($saldo, '0', 2) === 0 && ! $nao && $ls->isNotEmpty();
                $factos[] = self::f("Conta {$conta}", $ok ? 'Compensada' : 'Saldo '.self::kz($saldo).($nao ? " · {$nao} movimento(s) por compensar" : ''));
                if ($ok) {
                    continue;
                }
                $tudo = false;
                $saldoPorCompensar = bcadd($saldoPorCompensar, ltrim($saldo, '-'), 2);
                $pendConta = $tesPend->merge($caixaPend)->filter(fn ($l) => ! $l->conta_transitoria || trim((string) $l->conta_transitoria) === $conta)->count();
                $problemas[] = self::aviso(bccomp($saldo, '0', 2) !== 0
                    ? "Conta {$conta}: saldo de ".self::kz($saldo).' por regularizar e compensar'.($pendConta ? ' (há regularizações por integrar)' : '').'.'
                    : "Conta {$conta}: saldo nulo, mas {$nao} movimento(s) ainda por compensar no extracto.");
            }
            if ($tesPend->isNotEmpty()) {
                $problemas[] = self::aviso($tesPend->count().' recebimento(s) da prestação de contas por integrar na Tesouraria ('.self::lista($tesPend->map(fn ($l) => '#'.$l->documento_tesouraria_id)).').');
            }
            if ($caixaPend->isNotEmpty()) {
                $problemas[] = self::aviso('Numerário transferido para a Folha de Caixa ainda por contabilizar ('.self::lista($caixaPend->map(fn ($l) => (string) $l->conta_destino)).').');
            }
            $E['comp'] = $contas->isNotEmpty() && $tudo ? self::feito('Transitórias compensadas', $factos)
                : self::etapa('curso', $contas->isNotEmpty() ? 'Por compensar' : 'Sem contas transitórias', $factos, $problemas,
                    [self::accao('Ver extracto por compensar', 'relatorios_contabeis', true), self::accao('Integrar na Tesouraria', 'teso_contab_integracao')]);
        }

        return [
            'chave' => 'sessao-'.$s->id, 'titulo' => $s->numero_z ?: ($s->codigo_sessao ?: "Sessão #{$s->id}"),
            'subtitulo' => implode(' · ', array_filter([$s->nome_terminal ?: $s->codigo_terminal, $s->nome_operador])),
            'data' => substr((string) ($s->fechado_em ?: $s->aberto_em), 0, 19), 'valor' => self::dinheiro($s->total_vendas), 'valor_pendente' => $saldoPorCompensar, 'etapas' => $E,
            'documentos' => [['tipo' => 'sessao_pos', 'id' => $s->id, 'numero' => $s->numero_z ?: $s->codigo_sessao]],
        ] + self::contagem($E);
    }
}
