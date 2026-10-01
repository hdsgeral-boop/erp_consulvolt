<?php

namespace App\Services\Gestao\Fluxos\Avaliadores;

use App\Models\AvaliacaoDesempenhoRH;
use App\Models\CicloAvaliacao360;
use App\Services\RH\ServicoAvaliacao360;
use Illuminate\Support\Facades\DB;

/**
 * Fluxo da avaliação de desempenho (avaliarAvaliacoes, js/fluxo_rh.js:130-235). Um processo é a avaliação de um
 * colaborador num ano/período: os participantes dos ciclos 360º não-rascunho e as avaliações/autoavaliações do ano
 * corrente e do anterior (sem nada, os activos no ano corrente, período anual). Etapas: critérios e objectivos →
 * autoavaliação e 360º → avaliação da chefia → conhecimento e comentário → contestação e decisão → plano e bonificação.
 * A fase da avaliação é a do módulo (ServicoAvaliacao360::fase, ADR-040/041): POR_AVALIAR, EM_AVALIACAO,
 * AGUARDA_CONHECIMENTO, PRAZO_CONTESTACAO, CONTESTADA, FINAL. Classificação Suficiente/Insuficiente sem plano de
 * desenvolvimento e contestação por decidir bloqueiam.
 */
final class FluxoAvaliacao extends AvaliadorFluxo
{
    private const PERIODOS = ['ANUAL' => 'Anual', 'S1' => '1.º semestre', 'S2' => '2.º semestre', 'T1' => '1.º trimestre', 'T2' => '2.º trimestre', 'T3' => '3.º trimestre', 'T4' => '4.º trimestre'];

    private const FASES = ['POR_AVALIAR' => 'Por avaliar', 'EM_AVALIACAO' => 'Em avaliação', 'AGUARDA_CONHECIMENTO' => 'Aguarda conhecimento', 'PRAZO_CONTESTACAO' => 'Em prazo de contestação',
        'CONTESTADA' => 'Contestada', 'FINAL' => 'Final'];

    public function temActividade(): bool
    {
        $e = $this->empresa();

        return DB::table('avaliacoes_desempenho_rh')->where('empresa_id', $e)->exists() || DB::table('autoavaliacoes_colaborador')->where('empresa_id', $e)->exists()
            || DB::table('colaboradores')->where('empresa_id', $e)->whereNull('eliminado_em')->exists();
    }

    public function avaliar(): array
    {
        $e = $this->empresa();
        $anoAct = (int) now()->format('Y');
        $emps = DB::table('colaboradores')->where('empresa_id', $e)->whereNull('eliminado_em')->orderBy('nome_completo')->get(['id', 'nome_completo', 'estado', 'reformado'])->keyBy('id');
        $avals = AvaliacaoDesempenhoRH::query()->get();
        $autos = DB::table('autoavaliacoes_colaborador')->where('empresa_id', $e)->get();
        $itens = DB::table('criterios_avaliacao_rh')->where('empresa_id', $e)->where(fn ($q) => $q->whereNull('ativo')->orWhere('ativo', true))->get(['tipo', 'ambito', 'colaborador_id', 'natureza']);
        $ciclos = CicloAvaliacao360::query()->where('estado', '<>', 'RASCUNHO')->get();
        $respostas = DB::table('respostas_avaliacao_360')->where('empresa_id', $e)->get(['ciclo_avaliacao_id', 'colaborador_avaliado_id', 'grupo'])
            ->groupBy(fn ($r) => "{$r->ciclo_avaliacao_id}|{$r->colaborador_avaliado_id}");
        $bonus = DB::table('bonificacoes_avaliacao_rh')->where('empresa_id', $e)->get(['ciclo_avaliacao_id', 'colaborador_id', 'valor', 'estado'])->keyBy(fn ($b) => "{$b->ciclo_avaliacao_id}|{$b->colaborador_id}");
        $chaves = [];
        foreach ($ciclos as $c) {
            foreach ($c->participantes ?? [] as $p) {
                if (isset($emps[$p['employee_id'] ?? 0])) {
                    $chaves["{$p['employee_id']}|{$c->ano}|{$c->periodo}"] = [(int) $p['employee_id'], (int) $c->ano, (string) $c->periodo];
                }
            }
        }
        foreach ($avals->concat($autos) as $a) {
            if (isset($emps[$a->colaborador_id]) && (int) $a->ano >= $anoAct - 1) {
                $per = $a->periodo ?: 'ANUAL';
                $chaves["{$a->colaborador_id}|{$a->ano}|{$per}"] = [(int) $a->colaborador_id, (int) $a->ano, $per];
            }
        }
        if (! $chaves) {
            foreach ($emps as $c) {
                if (! $c->reformado && $c->estado !== 'INACTIVO') {
                    $chaves["{$c->id}|{$anoAct}|ANUAL"] = [$c->id, $anoAct, 'ANUAL'];
                }
            }
        }
        $processos = [];
        foreach ($chaves as [$colab, $ano, $periodo]) {
            $c = $ciclos->first(fn ($x) => (int) $x->ano === $ano && $x->periodo === $periodo);
            $p = $c ? collect($c->participantes ?? [])->first(fn ($x) => (int) ($x['employee_id'] ?? 0) === $colab) : null;
            $a = $avals->first(fn ($x) => (int) $x->colaborador_id === $colab && (int) $x->ano === $ano && ($x->periodo ?: 'ANUAL') === $periodo);
            $auto = $autos->first(fn ($x) => (int) $x->colaborador_id === $colab && (int) $x->ano === $ano && ($x->periodo ?: 'ANUAL') === $periodo);
            $processos[] = $this->processo($emps[$colab], $ano, $periodo, $c, $p, $a, $auto, $itens, $c ? ($respostas["{$c->id}|{$colab}"] ?? collect()) : collect(), $c ? ($bonus["{$c->id}|{$colab}"] ?? null) : null);
        }
        usort($processos, fn ($x, $y) => $y['ano'] <=> $x['ano'] ?: $x['_concluidas'] <=> $y['_concluidas'] ?: strcmp($x['titulo'], $y['titulo']));
        $doAno = array_filter($processos, fn ($x) => $x['ano'] === $anoAct);

        return ['processos' => $processos, 'kpis' => [
            self::kpi('finais', "Finais {$anoAct}", count(array_filter($doAno, fn ($x) => $x['fase'] === 'FINAL')).' de '.count($doAno), 'texto'),
            self::kpi('por_conhecimento', 'Por tomar conhecimento', $n = count(array_filter($processos, fn ($x) => $x['fase'] === 'AGUARDA_CONHECIMENTO')), 'num', $n > 0),
            self::kpi('contestacoes', 'Contestações por decidir', $n = count(array_filter($processos, fn ($x) => $x['fase'] === 'CONTESTADA')), 'num', $n > 0),
            self::kpi('bloqueios', 'Bloqueios (plano / contestação)', $n = count(array_filter($processos, fn ($x) => $x['_bloqueado'])), 'num', $n > 0),
        ]];
    }

    private function processo(object $emp, int $ano, string $periodo, ?CicloAvaliacao360 $c, ?array $p, ?AvaliacaoDesempenhoRH $a, ?object $auto, $itens, $recebidas, ?object $bx): array
    {
        $E = [];
        $concluida = $a && $a->estado === 'CONCLUIDA';
        $fase = ServicoAvaliacao360::fase($a, $c);
        $meus = fn ($tipo) => $itens->filter(fn ($i) => $i->tipo === $tipo && ($i->ambito === 'COMUM' || (int) $i->colaborador_id === (int) $emp->id));
        $crit = $meus('CRITERIO');
        $obj = $meus('OBJECTIVO');
        $E['itens'] = $crit->isNotEmpty() || $concluida
            ? self::feito(($concluida ? count($a->criterios ?? []) : $crit->count()).' critérios · '.($concluida ? count($a->objetivos ?? []) : $obj->count()).' objectivos',
                [self::f('Objectivos quantitativos', $obj->where('natureza', '<>', 'QUALITATIVO')->count(), 'num'), self::f('Objectivos qualitativos', $obj->where('natureza', 'QUALITATIVO')->count(), 'num'),
                    self::f('Específicos do colaborador', $crit->concat($obj)->where('ambito', 'ESPECIFICO')->count(), 'num')], [self::accao('Configuração da avaliação', 'rh_avaliacao_config')],
                $obj->isEmpty() ? [self::aviso('Sem objectivos definidos: a nota da chefia é só a dos critérios.')] : [])
            : self::etapa('curso', 'Critérios padrão', [], [self::aviso('Ainda não há itens de avaliação da empresa: serão usados os 8 critérios padrão.')], [self::accao('Configuração da avaliação', 'rh_avaliacao_config', true)]);
        $autoOk = $auto && $auto->estado === 'SUBMETIDA';
        $esperadas = $p ? count($p['pares'] ?? []) + count($p['subordinados'] ?? []) : 0;
        $min = $c ? ((int) $c->minimo_anonimato ?: 3) : 3;
        $prob360 = [];
        if (! $autoOk) {
            $prob360[] = self::aviso($auto ? 'Autoavaliação em rascunho no portal.' : 'Autoavaliação por fazer (Portal do Colaborador).');
        }
        if ($c && $esperadas && $recebidas->count() < $esperadas) {
            $prob360[] = self::aviso('Avaliações 360º recebidas: '.$recebidas->count()." de {$esperadas}".($recebidas->count() < $min ? " (mínimo anónimo {$min})" : '').'.');
        }
        if (! $c) {
            $E['r360'] = $autoOk ? self::feito('Autoavaliação submetida (sem ciclo 360º)')
                : self::etapa($concluida ? 'concluida' : 'fazer', $concluida ? 'Sem ciclo 360º' : 'Sem ciclo 360º aberto', [], [], [self::accao('Abrir um ciclo', 'rh_avaliacao_config')]);
        } else {
            $ok = ($autoOk && ($recebidas->count() >= min($esperadas, $min) || $c->estado === 'FECHADO')) || $c->estado === 'FECHADO' || $concluida;
            $E['r360'] = self::etapa($ok ? 'concluida' : 'curso', 'Auto '.($autoOk ? '✓' : '—').' · 360º '.$recebidas->count()."/{$esperadas}",
                [self::f('Ciclo', $c->nome), self::f('Pares', $recebidas->where('grupo', 'PARES')->count().'/'.count($p['pares'] ?? [])),
                    self::f('Subordinados', $recebidas->where('grupo', 'SUBORDINADOS')->count().'/'.count($p['subordinados'] ?? [])), self::f('Prazo', $c->prazos['respostas_ate'] ?? null, 'data')],
                $prob360, [self::accao('Acompanhamento do ciclo', 'rh_avaliacao_ciclo')]);
        }
        $chefiaNome = $p && ! empty($p['chefia_id']) ? (collect($c->participantes ?? [])->first(fn ($x) => (int) ($x['employee_id'] ?? 0) === (int) $p['chefia_id'])['nome'] ?? null) : null;
        if ($concluida) {
            $E['aval'] = self::feito(number_format((float) $a->pontuacao, 2, ',', '').' · '.$a->classificacao, [self::f('Avaliador', $a->avaliador ?: '—'), self::f('Data', $a->data_avaliacao, 'data'),
                self::f('Nota 360º', $a->nota_360 !== null ? str_replace('.', ',', (string) $a->nota_360).' ('.($a->classificacao_360 ?? '').')' : '—')], [self::accao('Abrir avaliação', 'rh_avaliacao')]);
        } elseif ($a) {
            $E['aval'] = self::etapa('curso', 'Rascunho', [self::f('Chefia', $chefiaNome ?: '—')], [self::aviso('Avaliação da chefia em rascunho'.(! empty($c?->prazos['chefia_ate']) ? '; prazo '.self::data($c->prazos['chefia_ate']) : '').'.')],
                [self::accao('Avaliação de desempenho', 'rh_avaliacao', true)]);
        } else {
            $E['aval'] = self::etapa($autoOk ? 'curso' : 'fazer', $chefiaNome ? "Por avaliar ({$chefiaNome})" : 'Por avaliar', [], $c && ! $chefiaNome ? [self::aviso('Sem chefia directa: a avaliação tem de ser feita pelo RH.')] : [],
                [self::accao('Avaliação de desempenho', 'rh_avaliacao', $autoOk)]);
        }
        $conh = $a?->conhecimento;
        if (! $concluida) {
            $E['conh'] = self::porFazer('Depois da avaliação da chefia');
        } elseif ($conh) {
            $E['conh'] = self::feito('Em '.self::data($conh['em'] ?? null), [self::f('Forma', ($conh['forma'] ?? null) === 'RH' ? 'Registado pelo RH' : 'Portal do Colaborador'), self::f('Comentário', ($conh['comentario'] ?? '') ?: '—')]);
        } else {
            $E['conh'] = self::etapa('curso', 'Por tomar conhecimento', [], [self::aviso('O colaborador ainda não tomou conhecimento no portal'.(! empty($c?->prazos['dias_conhecimento']) ? " (prazo de {$c->prazos['dias_conhecimento']} dias)" : '')
                .'. Se foi dado conhecimento em papel, registe-o no acompanhamento do ciclo.')], [self::accao('Acompanhamento do ciclo', 'rh_avaliacao_ciclo', true)]);
        }
        $ct = $a?->contestacao;
        if (! $concluida || ! $conh) {
            $E['cont'] = self::porFazer('Depois da tomada de conhecimento');
        } elseif ($ct && ! empty($ct['decisao'])) {
            $E['cont'] = self::feito(($ct['decisao']['resultado'] ?? null) === 'ALTERADA' ? 'Alterada → '.str_replace('.', ',', (string) ($ct['decisao']['nota'] ?? '')) : 'Contestação: mantida',
                [self::f('Decisão', $ct['decisao']['justificacao'] ?? '—'), self::f('Decidido por', $ct['decisao']['por'] ?? '—')]);
        } elseif ($ct) {
            $E['cont'] = self::etapa('bloqueada', ! empty($ct['parecer_rh']) ? 'Aguarda decisão' : 'Aguarda parecer do RH', [self::f('Contestada em', $ct['em'] ?? null, 'data'), self::f('Decisor', $ct['decisor_nome'] ?? 'RH')],
                [self::erro('Contestação por decidir: '.mb_substr((string) ($ct['fundamentacao'] ?? ''), 0, 160))], [self::accao('Acompanhamento do ciclo', 'rh_avaliacao_ciclo', true)]);
        } elseif ($fase === 'PRAZO_CONTESTACAO') {
            $prazo = date('Y-m-d', strtotime(substr((string) ($conh['em'] ?? ''), 0, 10).' +'.((int) ($c?->prazos['dias_contestacao'] ?? 10)).' days'));
            $E['cont'] = self::etapa('curso', 'Em prazo até '.self::data($prazo));
        } else {
            $E['cont'] = self::feito('Sem contestação (final)');
        }
        $classif = ($ct['decisao']['classificacao'] ?? null) ?: ($a?->classificacao_360 ?: (string) $a?->classificacao);
        $fraca = $concluida && preg_match('/Insuficiente|Suficiente/', (string) $classif);
        $factosBonus = $bx ? [self::f('Bonificação', self::kz($bx->valor).' · '.mb_strtolower((string) $bx->estado))] : [];
        $metodo = $c?->bonificacao['metodo'] ?? 'NENHUM';
        if ($fase !== 'FINAL') {
            $E['plano'] = self::porFazer('Depois da avaliação final');
        } elseif (! $a->plano_desenvolvimento && $fraca) {
            $E['plano'] = self::etapa('bloqueada', 'Plano obrigatório em falta', $factosBonus, [self::erro('Classificação Suficiente/Insuficiente sem plano de desenvolvimento.')], [self::accao('Avaliação de desempenho', 'rh_avaliacao', true)]);
        } elseif ($c && $metodo !== 'NENHUM' && (! $bx || $bx->estado !== 'LANCADA')) {
            $E['plano'] = self::etapa('curso', $bx ? 'Bonificação '.mb_strtolower((string) $bx->estado) : 'Bonificação por calcular', $factosBonus,
                $a->plano_desenvolvimento ? [] : [self::aviso('Recomenda-se registar o plano de desenvolvimento.')], [self::accao('Bonificações', 'rh_avaliacao_ciclo', true)]);
        } else {
            $E['plano'] = self::feito($a->plano_desenvolvimento ? 'Plano definido' : 'Concluída', $factosBonus, [], $a->plano_desenvolvimento ? [] : [self::aviso('Sem plano de desenvolvimento registado.')]);
        }

        return ['chave' => "avaliacao-{$emp->id}-{$ano}-{$periodo}", 'titulo' => (string) $emp->nome_completo, 'subtitulo' => (self::PERIODOS[$periodo] ?? $periodo)." {$ano}", 'data' => (string) $ano,
            'valor' => null, 'valor_pendente' => null, 'etapas' => $E, 'ano' => $ano, 'fase' => $fase, 'fase_nome' => self::FASES[$fase] ?? $fase, 'colaborador_id' => $emp->id,
            'documentos' => $a ? [['tipo' => 'avaliacao_desempenho', 'id' => $a->id, 'numero' => "{$ano} {$periodo}"]] : []] + self::contagem($E);
    }
}
