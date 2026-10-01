<?php

namespace App\Services\Gestao\Fluxos\Avaliadores;

use App\Services\RH\ServicoFerias;
use Illuminate\Support\Facades\DB;

/**
 * Fluxo do plano de férias (avaliarFerias, js/fluxo_rh.js:33-120). Um processo é o plano de um colaborador num ano: o ano
 * corrente para todos os activos e os anos anteriores (até ao ano passado) de quem tem períodos. Etapas: direito anual →
 * marcação → aprovação da chefia (pedidos do portal) → aprovação do RH → gozo → saldo do ano. Regras do legado: o ano
 * fechado sem férias marcadas e o saldo negativo bloqueiam; períodos aprovados já terminados são para marcar como gozados.
 * O direito do ano segue a regra do módulo (ServicoFerias::direito, ADR-039: o último direito gravado; 22 por omissão),
 * lido aqui de uma só vez para todos os planos. O legado lia o direito do último período por data de início.
 */
final class FluxoFerias extends AvaliadorFluxo
{
    public function temActividade(): bool
    {
        $e = $this->empresa();

        return DB::table('plano_ferias_colaboradores')->where('empresa_id', $e)->exists() || DB::table('colaboradores')->where('empresa_id', $e)->whereNull('eliminado_em')->exists();
    }

    public function avaliar(): array
    {
        $e = $this->empresa();
        $anoAct = (int) now()->format('Y');
        $hoje = self::hoje();
        $emps = DB::table('colaboradores')->where('empresa_id', $e)->whereNull('eliminado_em')->orderBy('nome_completo')->get(['id', 'nome_completo', 'estado', 'reformado'])->keyBy('id');
        $per = DB::table('plano_ferias_colaboradores')->where('empresa_id', $e)->orderBy('data_inicio')->get();
        $pedidos = DB::table('pedidos_portal_colaborador')->where('empresa_id', $e)->where('tipo', 'FERIAS')->get();
        $direitos = DB::table('plano_ferias_colaboradores')->where('empresa_id', $e)->whereNotNull('direito')->orderByDesc('atualizado_em')->orderByDesc('id')
            ->get(['colaborador_id', 'ano', 'direito'])->unique(fn ($r) => "{$r->colaborador_id}|{$r->ano}")->keyBy(fn ($r) => "{$r->colaborador_id}|{$r->ano}");
        $activo = fn ($c) => ! $c->reformado && $c->estado !== 'INACTIVO';
        $chaves = [];
        foreach ($emps as $c) {
            if ($activo($c)) {
                $chaves["{$c->id}|{$anoAct}"] = [$c, $anoAct];
            }
        }
        foreach ($per as $p) {
            if (($c = $emps[$p->colaborador_id] ?? null) && (int) $p->ano >= $anoAct - 1) {
                $chaves["{$c->id}|{$p->ano}"] = [$c, (int) $p->ano];
            }
        }
        $porColabAno = $per->groupBy(fn ($p) => "{$p->colaborador_id}|{$p->ano}");
        $pedPorColabAno = $pedidos->groupBy(fn ($r) => $r->colaborador_id.'|'.((json_decode((string) $r->dados, true) ?: [])['ano'] ?? ''));
        $processos = [];
        foreach ($chaves as $k => [$c, $ano]) {
            $ps = $porColabAno[$k] ?? collect();
            $validos = $ps->where('estado', '<>', 'CANCELADO');
            $direito = (int) ($direitos[$k]->direito ?? ServicoFerias::DIREITO_PADRAO);
            $dias = fn ($f) => (int) $validos->filter($f)->sum('dias');
            $marcados = $dias(fn () => true);
            $gozados = $dias(fn ($p) => $p->estado === 'GOZADO');
            $aprovados = $dias(fn ($p) => in_array($p->estado, ['APROVADO', 'GOZADO'], true));
            $saldo = $direito - $marcados;
            $fechado = $ano < $anoAct;
            $reqs = $pedPorColabAno[$k] ?? collect();
            $listaP = $ps->map(fn ($p) => self::data($p->data_inicio).' a '.self::data($p->data_fim)." ({$p->dias} d, ".mb_strtolower((string) $p->estado).')')->implode('; ');
            $E = [];
            $E['direito'] = self::feito("{$direito} dias úteis", [self::f('Direito', "{$direito} dias úteis"), self::f('Origem', isset($direitos[$k]) ? 'Programa de férias' : 'Valor padrão')], [self::accao('Programa de férias', 'rh_ferias')]);
            if (! $marcados) {
                $E['marcacao'] = $fechado
                    ? self::etapa('bloqueada', 'Sem férias marcadas', [], [self::erro("O ano {$ano} terminou sem férias marcadas ({$direito} dias de direito).")], [self::accao('Programa de férias', 'rh_ferias', true)])
                    : self::etapa('curso', 'Por marcar', [self::f('Direito', $direito, 'num')], [self::aviso('Ainda não há férias marcadas. O colaborador pode pedi-las no Portal do Colaborador ou o RH marcá-las no Programa de Férias.')],
                        [self::accao('Programa de férias', 'rh_ferias', true)]);
            } else {
                $E['marcacao'] = self::etapa($marcados >= $direito ? 'concluida' : 'curso', "{$marcados} de {$direito} dias", [self::f('Dias marcados', $marcados, 'num'), self::f('Períodos', $listaP ?: '—')],
                    array_merge($marcados > $direito ? [self::aviso("Marcados {$marcados} dias, acima do direito de {$direito}.")] : [], $marcados < $direito ? [self::aviso('Faltam marcar '.($direito - $marcados).' dia(s).')] : []),
                    [self::accao('Programa de férias', 'rh_ferias', $marcados < $direito)]);
            }
            $etapasDe = fn ($r) => json_decode((string) $r->etapas, true) ?: [];
            $dadosDe = fn ($r) => json_decode((string) $r->dados, true) ?: [];
            $pendChefia = $reqs->where('estado', 'PENDENTE_CHEFIA');
            $comChefia = $reqs->filter(fn ($r) => collect($etapasDe($r))->contains(fn ($x) => ($x['nivel'] ?? null) === 'CHEFIA' && ($x['estado'] ?? null) !== 'DISPENSADA'));
            if ($pendChefia->isNotEmpty()) {
                $E['chefia'] = self::etapa('curso', $pendChefia->count().' por decidir', [self::f('Pedidos do portal', $reqs->count(), 'num')], $pendChefia->map(function ($r) use ($etapasDe, $dadosDe) {
                    $et = collect($etapasDe($r))->firstWhere('nivel', 'CHEFIA');
                    $d = $dadosDe($r);

                    return self::aviso(self::data($d['data_inicio'] ?? null).' a '.self::data($d['data_fim'] ?? null).' ('.($d['dias'] ?? '?').' d) aguarda '.($et['aprovador_nome'] ?? 'a chefia').' no Portal do Colaborador.');
                })->values()->all(), [self::accao('Portal (aprovações)', 'rh_portal', true)]);
            } elseif (! $marcados) {
                $E['chefia'] = self::porFazer('Depois da marcação');
            } else {
                $E['chefia'] = self::feito($comChefia->isNotEmpty() ? $comChefia->count().' pedido(s) decidido(s)' : ($reqs->isNotEmpty() ? 'Dispensada (sem chefia no sistema)' : 'Não aplicável (marcadas pelo RH)'),
                    [self::f('Pedidos do portal', $reqs->count(), 'num')], [], $reqs->where('estado', 'RECUSADO')->map(function ($r) use ($etapasDe, $dadosDe) {
                        $d = $dadosDe($r);

                        return self::aviso('Recusado '.self::data($d['data_inicio'] ?? null).' a '.self::data($d['data_fim'] ?? null).': '.(collect($etapasDe($r))->firstWhere('estado', 'RECUSADO')['nota'] ?? ''));
                    })->values()->all());
            }
            $pedPend = $validos->where('estado', 'PEDIDO');
            $plan = $validos->where('estado', 'PLANEADO');
            if (! $marcados) {
                $E['rh'] = self::porFazer('Depois da marcação');
            } elseif ($pedPend->isNotEmpty() || $plan->isNotEmpty()) {
                $ligados = $pendChefia->pluck('plano_ferias_colaborador_id')->filter()->flip();
                $E['rh'] = self::etapa($pendChefia->isNotEmpty() && $plan->isEmpty() ? 'fazer' : 'curso', ($pedPend->count() + $plan->count()).' período(s) por aprovar',
                    [self::f('Aprovados', "{$aprovados} dias"), self::f('Por aprovar', ($marcados - $aprovados).' dias')],
                    array_merge($pedPend->reject(fn ($p) => isset($ligados[$p->id]))->map(fn ($p) => self::aviso('Pedido do portal '.self::data($p->data_inicio).' a '.self::data($p->data_fim).' aguarda a decisão do RH em Pedidos do Portal.'))->values()->all(),
                        $plan->map(fn ($p) => self::aviso('Período planeado '.self::data($p->data_inicio).' a '.self::data($p->data_fim).' ainda não foi aprovado.'))->values()->all()),
                    array_merge($pedPend->isNotEmpty() ? [self::accao('Pedidos do Portal', 'rh_portal_gestao', true)] : [], $plan->isNotEmpty() ? [self::accao('Programa de férias', 'rh_ferias', $pedPend->isEmpty())] : []));
            } else {
                $E['rh'] = self::feito("{$aprovados} dias aprovados", [self::f('Aprovados', "{$aprovados} dias")]);
            }
            $vencidos = $validos->filter(fn ($p) => $p->estado === 'APROVADO' && (string) $p->data_fim < $hoje);
            $proximos = $validos->filter(fn ($p) => $p->estado === 'APROVADO' && (string) $p->data_inicio >= $hoje)->values();
            if (! $aprovados) {
                $E['gozo'] = self::porFazer('Depois da aprovação');
            } elseif ($vencidos->isNotEmpty()) {
                $E['gozo'] = self::etapa('curso', "{$gozados} de {$aprovados} dias gozados", [self::f('Gozados', $gozados, 'num')],
                    $vencidos->map(fn ($p) => self::aviso('O período '.self::data($p->data_inicio).' a '.self::data($p->data_fim).' já terminou: marque-o como Gozado no Programa de Férias.'))->values()->all(),
                    [self::accao('Programa de férias', 'rh_ferias', true)]);
            } elseif ($gozados >= $marcados) {
                $E['gozo'] = self::feito("{$gozados} dias gozados", [self::f('Gozados', $gozados, 'num')]);
            } else {
                $E['gozo'] = self::etapa($gozados ? 'curso' : 'fazer', $proximos->isNotEmpty() ? 'Próximo: '.self::data($proximos[0]->data_inicio) : "{$gozados} de {$aprovados} gozados",
                    [self::f('Gozados', $gozados, 'num'), self::f('Por gozar', $aprovados - $gozados, 'num')]);
            }
            if ($saldo < 0) {
                $E['saldo'] = self::etapa('bloqueada', (-$saldo).' dia(s) acima do direito', [self::f('Saldo', $saldo, 'num')], [self::erro("Foram marcados {$marcados} dias para um direito de {$direito}. Corrija o direito ou os períodos.")],
                    [self::accao('Programa de férias', 'rh_ferias', true)]);
            } elseif (! $saldo && $gozados >= $direito) {
                $E['saldo'] = self::feito('Direito totalmente gozado', [self::f('Saldo', 0, 'num')]);
            } elseif ($fechado) {
                $E['saldo'] = self::etapa('curso', ($direito - $gozados).' dia(s) não gozados', [self::f('Direito', $direito, 'num'), self::f('Gozados', $gozados, 'num')],
                    [self::aviso("O ano {$ano} terminou com ".($direito - $gozados).' dia(s) de férias por gozar. Decida se transitam para o ano seguinte.')], [self::accao('Programa de férias', 'rh_ferias', true)]);
            } else {
                $E['saldo'] = self::porFazer("Saldo {$saldo} · por gozar ".($direito - $gozados));
            }
            $processos[] = ['chave' => "ferias-{$c->id}-{$ano}", 'titulo' => (string) $c->nome_completo, 'subtitulo' => (string) $ano, 'data' => (string) $ano,
                'valor' => null, 'valor_pendente' => null, 'etapas' => $E, 'ano' => $ano, 'colaborador_id' => $c->id, 'saldo' => $saldo, 'marcados' => $marcados,
                'vencidos' => $vencidos->count(), 'por_aprovar' => $pedPend->count() + $plan->count(),
                'documentos' => $ps->map(fn ($p) => ['tipo' => 'plano_ferias', 'id' => $p->id, 'numero' => self::data($p->data_inicio)])->values()->all()] + self::contagem($E);
        }
        usort($processos, fn ($a, $b) => $b['ano'] <=> $a['ano'] ?: $a['_concluidas'] <=> $b['_concluidas'] ?: strcmp($a['titulo'], $b['titulo']));
        $doAno = array_filter($processos, fn ($p) => $p['ano'] === $anoAct);

        return ['processos' => $processos, 'kpis' => [
            self::kpi('planos_ano', "Planos {$anoAct}", count($doAno)),
            self::kpi('sem_marcacao', 'Sem férias marcadas', $n = count(array_filter($doAno, fn ($p) => ! $p['marcados'])), 'num', $n > 0),
            self::kpi('por_aprovar', 'Períodos por aprovar', $n = array_sum(array_column($processos, 'por_aprovar')), 'num', $n > 0),
            self::kpi('por_marcar_gozado', 'Por marcar como gozado', $n = array_sum(array_column($processos, 'vencidos')), 'num', $n > 0),
        ]];
    }
}
