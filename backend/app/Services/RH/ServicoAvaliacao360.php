<?php

namespace App\Services\RH;

use App\Exceptions\ErroNegocio;
use App\Models\AutoavaliacaoColaborador;
use App\Models\AvaliacaoDesempenhoRH;
use App\Models\BonificacaoAvaliacaoRH;
use App\Models\CicloAvaliacao360;
use App\Models\Colaborador;
use App\Models\ConfirmacaoAvaliacaoRH;
use App\Models\CriterioAvaliacaoRH;
use App\Models\FeedbackAvaliacao360;
use App\Models\InfotipoSalarial;
use App\Models\LinhaFolhaSalarial;
use App\Models\ParticipacaoAscendenteRH;
use App\Models\ParticipanteAvaliacao360;
use App\Models\PeriodoProcessamentoSalarial;
use App\Models\RespostaAscendenteRH;
use App\Models\RespostaAvaliacao360;
use App\Services\Sistema\ServicoAuditoria;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Avaliação 360º, acompanhamento, contestação, bonificação e avaliação ascendente (js/modules/rh/aval360_dados.js,
 * portal_dados.js:436-472). Paridade: pesos CHEFIA 50 · AUTO 10 · PARES 20 · SUBORDINADOS 20 (soma 100, chefia > 0),
 * anonimato mínimo 3 (≥ 2), até 5 pares; um ciclo por ano/período e um só ABERTO; ao abrir fotografa participantes
 * (chefia, pares da unidade por ordem alfabética, subordinados) e critérios comuns; respostas anónimas (quem
 * respondeu e o quê em tabelas separadas, sem ligação); grupos abaixo do mínimo juntam-se (PARES_SUB) ou ficam de
 * fora; nota 360 = Σ média × peso / Σ pesos disponíveis (exige a chefia); fases POR_AVALIAR → … → FINAL;
 * contestação (≥ 30 caracteres, decisor = chefia da chefia ou RH), parecer do RH e decisão (MANTIDA/ALTERADA);
 * bonificação PERCENTAGEM / FIXO / BOLSA (diferença de arredondamento no maior valor), quem calcula não aprova,
 * ninguém aprova a sua; lançamento no processamento do mês configurado (ABERTO).
 * Correcções (ADR-041):
 *   - resultados anónimos (pares, subordinados, ascendente) só depois do prazo de respostas ou com o ciclo fechado:
 *     acompanhar ao vivo permitia deduzir a resposta de alguém comparando antes/depois;
 *   - o mínimo de anonimato da avaliação ascendente é o do ciclo do período (no legado 3 fixo) e o RH vê-a por
 *     período (o agregado anual juntava grupos pequenos);
 *   - bonificação só de participantes do ciclo; configuração da bonificação bloqueada com bónus aprovados/lançados
 *     ou ciclo fechado; lançar recusa uma rubrica já lançada para o colaborador no período (o legado duplicava);
 *     anular fica auditado;
 *   - a nota 360 é recalculada sempre que se lê o resultado (o legado só ao concluir ou com o botão).
 */
final class ServicoAvaliacao360
{
    public const GRUPOS = ['CHEFIA', 'AUTO', 'PARES', 'SUBORDINADOS'];

    public const METODOS = ['NENHUM', 'PERCENTAGEM', 'FIXO', 'BOLSA'];

    public const CLASSES = ['Excelente', 'Muito Bom', 'Bom', 'Suficiente', 'Insuficiente'];

    public const LIDERANCA = ['clareza' => 'Comunica objectivos e expectativas com clareza', 'feedback' => 'Dá feedback regular e construtivo',
        'reconhecimento' => 'Reconhece o bom trabalho', 'desenvolvimento' => 'Apoia o desenvolvimento e a formação da equipa',
        'justica' => 'Trata a equipa com justiça e respeito', 'organizacao' => 'Organiza e distribui o trabalho de forma equilibrada',
        'disponibilidade' => 'Está disponível e acessível', 'decisao' => 'Toma decisões atempadas'];

    public function __construct(
        private readonly ServicoEstruturaOrg $estrutura,
        private readonly ServicoPortalColaborador $portal,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    // ───────────── Ciclos ─────────────

    public static function datasPeriodo(int $ano, string $periodo): array
    {
        [$a, $b] = ['ANUAL' => [1, 12], 'S1' => [1, 6], 'S2' => [7, 12], 'T1' => [1, 3], 'T2' => [4, 6], 'T3' => [7, 9], 'T4' => [10, 12]][$periodo] ?? [1, 12];

        return [sprintf('%04d-%02d-01', $ano, $a), date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $ano, $b)))];
    }

    /** @param  array<string, mixed>  $d */
    public function gravarCiclo(array $d, ?CicloAvaliacao360 $c = null): CicloAvaliacao360
    {
        $pesos = array_merge(['CHEFIA' => 50, 'AUTO' => 10, 'PARES' => 20, 'SUBORDINADOS' => 20], (array) ($d['pesos'] ?? $c?->pesos ?? []));
        if (abs(array_sum(array_map('floatval', $pesos)) - 100) > 0.001 || (float) $pesos['CHEFIA'] <= 0) {
            throw new ErroNegocio('Os pesos dos grupos têm de somar 100 e a chefia tem de ter peso.', 'PESOS_INVALIDOS', 422);
        }
        $bon = array_merge(['metodo' => 'NENHUM', 'tabela' => ['Excelente' => 15, 'Muito Bom' => 10, 'Bom' => 5, 'Suficiente' => 0, 'Insuficiente' => 0], 'meses_base' => 1,
            'bolsa' => ['montante' => 0, 'nota_minima' => 3.5], 'infotipo_salarial_id' => null, 'mes_lancamento' => ''], (array) ($c?->bonificacao ?? []), (array) ($d['bonificacao'] ?? []));
        if ($bon['mes_lancamento'] !== '' && ! preg_match('/^(0[1-9]|1[0-2])\/\d{4}$/', (string) $bon['mes_lancamento'])) {
            throw new ErroNegocio('O mês de lançamento da bonificação é MM/AAAA.', 'MES_INVALIDO', 422);
        }
        if ($bon['metodo'] === 'BOLSA' && ! ((float) ($bon['bolsa']['montante'] ?? 0) > 0)) {
            throw new ErroNegocio('Indique o montante da bolsa.', 'BOLSA_SEM_MONTANTE', 422);
        }
        if (! empty($bon['infotipo_salarial_id']) && InfotipoSalarial::query()->findOrFail($bon['infotipo_salarial_id'])->tipo !== 'VENCIMENTO') {
            throw new ErroNegocio('A rubrica da bonificação tem de ser um vencimento.', 'RUBRICA_NAO_VENCIMENTO', 422);
        }
        $ano = (int) ($d['ano'] ?? $c?->ano);
        $periodo = (string) ($d['periodo'] ?? $c?->periodo);
        [$ini, $fim] = self::datasPeriodo($ano, $periodo);
        $prazos = array_merge(['respostas_ate' => date('Y-m-d', strtotime("{$fim} +20 days")), 'chefia_ate' => date('Y-m-d', strtotime("{$fim} +30 days")),
            'dias_conhecimento' => 10, 'dias_contestacao' => 10], (array) ($c?->prazos ?? []), (array) ($d['prazos'] ?? []));
        if ((int) $prazos['dias_contestacao'] < 1) {
            throw new ErroNegocio('O prazo de contestação tem de ser de pelo menos 1 dia.', 'PRAZO_INVALIDO', 422);
        }
        $dados = ['nome' => $d['nome'] ?? $c?->nome ?? "Avaliação {$periodo} {$ano}", 'prazos' => $prazos, 'comunicado' => $d['comunicado'] ?? $c?->comunicado,
            'feedback' => $d['feedback'] ?? $c?->feedback ?? ['periodicidade' => 'NENHUM'], 'atualizado_por' => Auth::user()?->nome_utilizador];
        if ($c && $c->estado !== 'RASCUNHO') {
            // aberto/fechado: só prazos, comunicado, acompanhamento e nome; a bonificação só enquanto não houver bónus aprovados/lançados
            if (isset($d['bonificacao'])) {
                if ($c->estado === 'FECHADO' || BonificacaoAvaliacaoRH::query()->where('ciclo_avaliacao_id', $c->id)->where('estado', '<>', 'PROPOSTA')->exists()) {
                    throw new ErroNegocio('A bonificação já não se altera (ciclo fechado ou bónus aprovados/lançados).', 'BONIFICACAO_BLOQUEADA', 422);
                }
                $dados['bonificacao'] = $bon;
            }
            $c->update($dados);

            return $c->refresh();
        }
        $min = (int) ($d['minimo_anonimato'] ?? $c?->minimo_anonimato ?? 3);
        if ($min < 2) {
            throw new ErroNegocio('O mínimo de anonimato é pelo menos 2.', 'ANONIMATO_INVALIDO', 422);
        }
        if (CicloAvaliacao360::query()->where('ano', $ano)->where('periodo', $periodo)->when($c, fn ($q) => $q->whereKeyNot($c->id))->exists()) {
            throw new ErroNegocio("Já existe um ciclo de avaliação para {$periodo} {$ano}.", 'CICLO_EXISTENTE', 422);
        }
        $dados += ['ano' => $ano, 'periodo' => $periodo, 'data_inicio' => $ini, 'data_fim' => $fim, 'pesos' => $pesos, 'bonificacao' => $bon,
            'minimo_anonimato' => $min, 'max_pares' => (int) ($d['max_pares'] ?? $c?->max_pares ?? 5)];
        $c ? $c->update($dados) : $c = CicloAvaliacao360::create($dados + ['estado' => 'RASCUNHO', 'criado_por' => Auth::user()?->nome_utilizador]);

        return $c->refresh();
    }

    /** Abre o ciclo: fotografa a composição (a partir da estrutura) e os critérios comuns. */
    public function abrirCiclo(CicloAvaliacao360 $c): CicloAvaliacao360
    {
        if ($c->estado !== 'RASCUNHO') {
            throw new ErroNegocio('Só se abre um ciclo em preparação.', 'ESTADO_INVALIDO', 422);
        }
        if (CicloAvaliacao360::query()->where('estado', 'ABERTO')->exists()) {
            throw new ErroNegocio('Já há um ciclo de avaliação aberto: feche-o primeiro.', 'CICLO_ABERTO_EXISTENTE', 422);
        }
        app(ServicoAvaliacao::class)->itens();
        $criterios = CriterioAvaliacaoRH::query()->where('ambito', 'COMUM')->where('tipo', 'CRITERIO')->where('ativo', true)->orderBy('ordem')->get()
            ->map(fn ($i) => ['chave' => $i->chave, 'nome' => $i->nome, 'peso' => (float) $i->peso ?: 1])->all();
        $c->update(['estado' => 'ABERTO', 'participantes' => $this->compor($c), 'criterios' => $criterios, 'aberto_em' => now(), 'aberto_por' => Auth::user()?->nome_utilizador]);
        $this->auditoria->registar('Avaliação 360º', 'Abrir ciclo', "{$c->periodo} {$c->ano}", 'ciclos_avaliacao_360', $c->id);

        return $c->refresh();
    }

    public function fecharCiclo(CicloAvaliacao360 $c): CicloAvaliacao360
    {
        if ($c->estado !== 'ABERTO') {
            throw new ErroNegocio('O ciclo não está aberto.', 'ESTADO_INVALIDO', 422);
        }
        $c->update(['estado' => 'FECHADO', 'fechado_em' => now(), 'fechado_por' => Auth::user()?->nome_utilizador]);
        $this->auditoria->registar('Avaliação 360º', 'Fechar ciclo', "{$c->periodo} {$c->ano}", 'ciclos_avaliacao_360', $c->id);

        return $c->refresh();
    }

    /** Participantes: chefia directa, pares (mesma unidade, sem a chefia nem os próprios subordinados, por nome) e subordinados. */
    public function compor(CicloAvaliacao360 $c): array
    {
        $emps = Colaborador::query()->where('estado', 'ACTIVO')->orderBy('nome_completo')->get(['id', 'nome_completo', 'unidade_organica_id']);
        $chefias = $emps->mapWithKeys(fn ($e) => [$e->id => $this->estrutura->chefiaDe($e->id)]);

        return $emps->map(fn ($e) => [
            'colaborador_id' => $e->id, 'nome' => $e->nome_completo, 'chefia_id' => $chefias[$e->id],
            'pares' => $e->unidade_organica_id ? $emps->filter(fn ($o) => $o->id !== $e->id && $o->unidade_organica_id === $e->unidade_organica_id && $o->id !== $chefias[$e->id]
                && $chefias[$o->id] !== $e->id)->take(max(0, (int) $c->max_pares ?: 5))->pluck('id')->values()->all() : [],
            'subordinados' => $emps->filter(fn ($o) => $chefias[$o->id] === $e->id)->pluck('id')->values()->all(),
        ])->values()->all();
    }

    public static function participante(CicloAvaliacao360 $c, int $colaborador): ?array
    {
        return collect((array) $c->participantes)->first(fn ($x) => (int) ($x['colaborador_id'] ?? $x['employee_id'] ?? 0) === $colaborador);
    }

    // ───────────── Portal: a minha avaliação (ADR-064) ─────────────

    /**
     * O que o colaborador vê da sua avaliação: as avaliações concluídas (com a fase, o que pode fazer e o prazo de
     * contestação), as reuniões de acompanhamento, o ciclo aberto (critérios, comunicado), as tarefas 360º e a
     * avaliação ascendente da chefia directa. Só leitura: as acções usam os endpoints de /api/rh/avaliacao.
     */
    public function minhaAvaliacao(): array
    {
        $eu = $this->portal->exigirColaborador()->id;
        $ciclos = CicloAvaliacao360::query()->get()->keyBy('id');
        $porPeriodo = $ciclos->keyBy(fn ($c) => $c->ano.'|'.$c->periodo);
        $avaliacoes = AvaliacaoDesempenhoRH::query()->where('colaborador_id', $eu)->where('estado', 'CONCLUIDA')->orderByDesc('ano')->orderByDesc('id')->get()
            ->map(function (AvaliacaoDesempenhoRH $a) use ($ciclos, $porPeriodo) {
                $c = $ciclos[$a->ciclo_avaliacao_id] ?? $porPeriodo[$a->ano.'|'.$a->periodo] ?? null;
                $fase = self::fase($a, $c);
                $prazo = $a->conhecimento ? date('Y-m-d', strtotime(substr((string) $a->conhecimento['em'], 0, 10).' +'.((int) ($c?->prazos['dias_contestacao'] ?? 10)).' days')) : null;

                return collect($a->toArray())->except(['avisos_360'])->all() + ['fase' => $fase, 'nota_final' => self::notaFinal($a), 'classificacao_final' => self::classificacaoFinal($a),
                    'prazo_contestacao' => $prazo, 'pode_tomar_conhecimento' => $fase === 'AGUARDA_CONHECIMENTO', 'pode_contestar' => $fase === 'PRAZO_CONTESTACAO',
                    'tem_resultado_360' => $c !== null];
            })->values()->all();
        $feedbacks = FeedbackAvaliacao360::query()->where('colaborador_id', $eu)->orderByDesc('data')->orderByDesc('id')->limit(50)
            ->get(['id', 'ciclo_avaliacao_id', 'periodo_referencia', 'data', 'objetivos', 'positivos', 'melhorar', 'acordos', 'registado_por', 'confirmacao'])->toArray();

        $aberto = CicloAvaliacao360::query()->where('estado', 'ABERTO')->first();
        $ciclo = null;
        $ascendente = null;
        if ($aberto) {
            $ciclo = ['id' => $aberto->id, 'nome' => $aberto->nome, 'ano' => (int) $aberto->ano, 'periodo' => $aberto->periodo, 'prazos' => $aberto->prazos,
                'criterios' => array_values((array) $aberto->criterios), 'comunicado' => $aberto->comunicado,
                'comunicado_confirmado' => ConfirmacaoAvaliacaoRH::query()->where('ciclo_avaliacao_id', $aberto->id)->where('colaborador_id', $eu)->exists()];
            $chefe = $this->estrutura->chefiaDe($eu);
            $ascendente = ['chefia_colaborador_id' => $chefe, 'chefia_nome' => $chefe ? Colaborador::query()->find($chefe)?->nome_completo : null,
                'questoes' => collect(self::LIDERANCA)->map(fn ($nome, $chave) => ['chave' => $chave, 'nome' => $nome])->values()->all(),
                'respondida' => $chefe !== null && ParticipacaoAscendenteRH::query()->where('colaborador_id', $eu)->where('colaborador_alvo_id', $chefe)
                    ->where('ano', $aberto->ano)->where('periodo', $aberto->periodo)->exists()];
        }

        return ['colaborador_id' => $eu, 'avaliacoes' => $avaliacoes, 'feedbacks' => $feedbacks, 'ciclo_aberto' => $ciclo,
            'tarefas_360' => $this->minhasTarefas(), 'ascendente' => $ascendente];
    }

    // ───────────── Respostas 360 ─────────────

    /** Avaliações 360 que o utilizador ainda tem por fazer no ciclo aberto. */
    public function minhasTarefas(): array
    {
        $eu = $this->portal->exigirColaborador()->id;
        $c = CicloAvaliacao360::query()->where('estado', 'ABERTO')->first();
        if (! $c) {
            return [];
        }
        $feitas = ParticipanteAvaliacao360::query()->where('ciclo_avaliacao_id', $c->id)->where('colaborador_avaliador_id', $eu)->pluck('colaborador_avaliado_id')->all();
        $tarefas = [];
        foreach ((array) $c->participantes as $p) {
            $alvo = (int) ($p['colaborador_id'] ?? $p['employee_id']);
            $grupo = in_array($eu, array_map('intval', $p['pares'] ?? []), true) ? 'PARES' : (in_array($eu, array_map('intval', $p['subordinados'] ?? []), true) ? 'SUBORDINADOS' : null);
            if ($grupo && ! in_array($alvo, $feitas, true)) {
                $tarefas[] = ['ciclo_avaliacao_id' => $c->id, 'colaborador_avaliado_id' => $alvo, 'nome' => $p['nome'] ?? null, 'grupo' => $grupo];
            }
        }

        return $tarefas;
    }

    /** Resposta anónima: «quem respondeu» e «o quê» em tabelas separadas, sem ligação entre elas. */
    public function responder(int $avaliado, array $notas, ?string $comentario): void
    {
        $eu = $this->portal->exigirColaborador()->id;
        DB::transaction(function () use ($eu, $avaliado, $notas, $comentario) {
            $c = CicloAvaliacao360::query()->where('estado', 'ABERTO')->lockForUpdate()->first() ?? throw new ErroNegocio('Não há ciclo de avaliação aberto.', 'CICLO_NAO_ABERTO', 422);
            if (now()->toDateString() > ($c->prazos['respostas_ate'] ?? '9999-12-31')) {
                throw new ErroNegocio('Terminou o prazo das respostas.', 'PRAZO_TERMINADO', 422);
            }
            $tarefa = collect($this->minhasTarefas())->firstWhere('colaborador_avaliado_id', $avaliado)
                ?? throw new ErroNegocio('Esta avaliação não lhe está atribuída ou já foi feita.', 'TAREFA_INVALIDA', 422);
            $dadas = collect($notas)->keyBy('chave');
            $lista = [];
            foreach ((array) $c->criterios as $cr) {
                $n = (int) ($dadas[$cr['chave']]['nota'] ?? 0);
                if ($n < 1 || $n > 5) {
                    throw new ErroNegocio("Dê uma nota de 1 a 5 a «{$cr['nome']}».", 'NOTA_EM_FALTA', 422);
                }
                $lista[] = ['chave' => $cr['chave'], 'nota' => $n];
            }
            ParticipanteAvaliacao360::create(['uid' => (string) Str::uuid(), 'ciclo_avaliacao_id' => $c->id, 'colaborador_avaliador_id' => $eu, 'colaborador_avaliado_id' => $avaliado,
                'grupo' => $tarefa['grupo']]);
            RespostaAvaliacao360::create(['uid' => (string) Str::uuid(), 'ciclo_avaliacao_id' => $c->id, 'colaborador_avaliado_id' => $avaliado, 'grupo' => $tarefa['grupo'],
                'notas' => $lista, 'comentario' => $comentario]);
        });
    }

    private static function mediaPonderada(array $notas, array $criterios): ?float
    {
        $pesos = collect($criterios)->mapWithKeys(fn ($c) => [$c['chave'] => (float) ($c['peso'] ?? 1) ?: 1.0]);
        [$s, $p] = [0.0, 0.0];
        foreach ($notas as $n) {
            if ((float) ($n['nota'] ?? 0) >= 1) {
                $w = $pesos[$n['chave']] ?? 1.0;
                $s += (float) $n['nota'] * $w;
                $p += $w;
            }
        }

        return $p ? $s / $p : null;
    }

    /** Resultados anónimos visíveis? Só depois do prazo de respostas ou com o ciclo fechado. */
    public static function resultadosLiberados(CicloAvaliacao360 $c): bool
    {
        return $c->estado === 'FECHADO' || now()->toDateString() > ($c->prazos['respostas_ate'] ?? '9999-12-31');
    }

    /** Componentes 360 de um avaliado, com a regra do anonimato. */
    public function componentes(CicloAvaliacao360 $c, int $colaborador): array
    {
        $min = (int) $c->minimo_anonimato ?: 3;
        $pesos = (array) $c->pesos;
        $resp = RespostaAvaliacao360::query()->where('ciclo_avaliacao_id', $c->id)->where('colaborador_avaliado_id', $colaborador)->get();
        $grupo = function (string $g) use ($resp, $c) {
            $rs = $resp->where('grupo', $g);

            return ['n' => $rs->count(), 'medias' => $rs->map(fn ($r) => self::mediaPonderada((array) $r->notas, (array) $c->criterios))->filter(fn ($v) => $v !== null)->values()->all(), 'rs' => $rs];
        };
        [$P, $S] = [$grupo('PARES'), $grupo('SUBORDINADOS')];
        $comps = [];
        $aval = AvaliacaoDesempenhoRH::query()->where('colaborador_id', $colaborador)->where('ano', $c->ano)->where('periodo', $c->periodo)->first();
        if ($aval && $aval->estado === 'CONCLUIDA' && $aval->pontuacao !== null) {
            $comps['CHEFIA'] = ['media' => (float) $aval->pontuacao, 'n' => 1, 'peso' => (float) $pesos['CHEFIA']];
        }
        $auto = AutoavaliacaoColaborador::query()->where('colaborador_id', $colaborador)->where('ano', $c->ano)->where('periodo', $c->periodo)->where('estado', 'SUBMETIDA')->first();
        if ($auto && ($m = self::mediaPonderada((array) $auto->criterios, (array) $c->criterios)) !== null) {
            $comps['AUTO'] = ['media' => $m, 'n' => 1, 'peso' => (float) $pesos['AUTO']];
        }
        $media = fn (array $a) => $a ? array_sum($a) / count($a) : null;
        $avisos = [];
        if ($P['n'] >= $min) {
            $comps['PARES'] = ['media' => $media($P['medias']), 'n' => $P['n'], 'peso' => (float) $pesos['PARES']];
        }
        if ($S['n'] >= $min) {
            $comps['SUBORDINADOS'] = ['media' => $media($S['medias']), 'n' => $S['n'], 'peso' => (float) $pesos['SUBORDINADOS']];
        }
        $faltaP = $P['n'] > 0 && $P['n'] < $min;
        $faltaS = $S['n'] > 0 && $S['n'] < $min;
        if ($faltaP || $faltaS) {
            $juntos = [...($faltaP ? $P['medias'] : []), ...($faltaS ? $S['medias'] : [])];
            $outro = $faltaP && ! $faltaS ? ($S['n'] >= $min ? 'SUBORDINADOS' : null) : ($faltaS && ! $faltaP ? ($P['n'] >= $min ? 'PARES' : null) : null);
            if ($faltaP && $faltaS && count($juntos) >= $min) {
                $comps['PARES_SUB'] = ['media' => $media($juntos), 'n' => count($juntos), 'peso' => (float) $pesos['PARES'] + (float) $pesos['SUBORDINADOS']];
            } elseif ($outro) {
                $todas = [...($outro === 'PARES' ? $P['medias'] : $S['medias']), ...($faltaP ? $P['medias'] : $S['medias'])];
                $comps['PARES_SUB'] = ['media' => $media($todas), 'n' => count($todas), 'peso' => $comps[$outro]['peso'] + (float) $pesos[$faltaP ? 'PARES' : 'SUBORDINADOS']];
                unset($comps[$outro]);
            } else {
                $avisos[] = trim(($faltaP ? "Pares: {$P['n']}" : '').($faltaP && $faltaS ? ' · ' : '').($faltaS ? "Subordinados: {$S['n']}" : ''))
                    ." resposta(s) — abaixo do mínimo de {$min}; não contam para proteger o anonimato.";
            }
        }
        $disp = array_filter($comps, fn ($v) => $v['media'] !== null && $v['peso'] > 0);
        $somaPesos = array_sum(array_column($disp, 'peso'));
        $nota = isset($comps['CHEFIA']) && $somaPesos ? round(array_sum(array_map(fn ($v) => $v['media'] * $v['peso'], $disp)) / $somaPesos, 2) : null;
        foreach ($comps as $k => $v) {
            $comps[$k]['media'] = $v['media'] === null ? null : round($v['media'], 2);
            $comps[$k]['peso_efectivo'] = $somaPesos ? round($v['peso'] / $somaPesos * 100, 2) : 0;
        }
        $comentarios = $min <= $P['n'] + $S['n'] ? $P['rs']->merge($S['rs'])->pluck('comentario')->filter()->sort(fn ($a, $b) => strcoll($a, $b))->values()->all() : [];
        $p = self::participante($c, $colaborador) ?? ['pares' => [], 'subordinados' => []];

        return ['componentes' => $comps, 'nota' => $nota, 'classificacao' => ServicoAvaliacao::classificar($nota), 'avisos' => $avisos, 'comentarios' => $comentarios,
            'respostas' => ['PARES' => $P['n'], 'SUBORDINADOS' => $S['n']], 'esperadas' => ['PARES' => count($p['pares'] ?? []), 'SUBORDINADOS' => count($p['subordinados'] ?? [])]];
    }

    /** Resultado para consulta: sem as componentes anónimas enquanto não forem liberadas. */
    public function resultado(CicloAvaliacao360 $c, int $colaborador): array
    {
        $r = $this->componentes($c, $colaborador);
        if (! self::resultadosLiberados($c)) {
            $r = ['respostas' => $r['respostas'], 'esperadas' => $r['esperadas'], 'liberado' => false,
                'aviso' => 'Os resultados de pares e subordinados só são mostrados depois do prazo das respostas ('.($c->prazos['respostas_ate'] ?? '').').'];
        } else {
            $r['liberado'] = true;
            $a = AvaliacaoDesempenhoRH::query()->where('colaborador_id', $colaborador)->where('ano', $c->ano)->where('periodo', $c->periodo)->first();
            $a && $this->gravar360($a, $r);
        }

        return $r;
    }

    public function actualizar360(AvaliacaoDesempenhoRH $a): ?array
    {
        $c = CicloAvaliacao360::query()->where('ano', $a->ano)->where('periodo', $a->periodo)->first();
        if (! $c) {
            return null;
        }
        $r = $this->componentes($c, $a->colaborador_id);
        $this->gravar360($a, $r, $c);

        return $r;
    }

    private function gravar360(AvaliacaoDesempenhoRH $a, array $r, ?CicloAvaliacao360 $c = null): void
    {
        $a->update(['ciclo_avaliacao_id' => $c?->id ?? $a->ciclo_avaliacao_id, 'nota_360' => $r['nota'], 'classificacao_360' => $r['classificacao'],
            'componentes_360' => $r['componentes'], 'avisos_360' => $r['avisos'], 'atualizado_360_em' => now()]);
    }

    // ───────────── Fases, conhecimento e contestação ─────────────

    public static function fase(?AvaliacaoDesempenhoRH $a, ?CicloAvaliacao360 $c): string
    {
        if (! $a) {
            return 'POR_AVALIAR';
        }
        if ($a->estado !== 'CONCLUIDA') {
            return 'EM_AVALIACAO';
        }
        if (! $a->conhecimento) {
            return 'AGUARDA_CONHECIMENTO';
        }
        if ($a->contestacao) {
            return empty($a->contestacao['decisao']) ? 'CONTESTADA' : 'FINAL';
        }
        $prazo = date('Y-m-d', strtotime(substr((string) $a->conhecimento['em'], 0, 10).' +'.((int) ($c?->prazos['dias_contestacao'] ?? 10)).' days'));

        return now()->toDateString() <= $prazo ? 'PRAZO_CONTESTACAO' : 'FINAL';
    }

    public static function notaFinal(AvaliacaoDesempenhoRH $a): ?float
    {
        if (($a->contestacao['decisao']['resultado'] ?? null) === 'ALTERADA') {
            return (float) $a->contestacao['decisao']['nota'];
        }

        return $a->nota_360 !== null ? (float) $a->nota_360 : ($a->pontuacao !== null ? (float) $a->pontuacao : null);
    }

    public static function classificacaoFinal(AvaliacaoDesempenhoRH $a): string
    {
        return ($a->contestacao['decisao']['classificacao'] ?? null) ?: ($a->classificacao_360 ?: (string) $a->classificacao);
    }

    /** Tomada de conhecimento: pelo colaborador (portal) ou registada pelo RH (com observação). */
    public function tomarConhecimento(AvaliacaoDesempenhoRH $a, ?string $comentario, bool $peloRh = false, ?string $observacao = null): AvaliacaoDesempenhoRH
    {
        if ($a->estado !== 'CONCLUIDA' || $a->conhecimento) {
            throw new ErroNegocio('A avaliação não aguarda tomada de conhecimento.', 'ESTADO_INVALIDO', 422);
        }
        if (! $peloRh && $this->portal->colaboradorDe() !== $a->colaborador_id) {
            throw new ErroNegocio('Só o próprio toma conhecimento da sua avaliação.', 'SEM_PERMISSAO', 403);
        }
        if ($peloRh && mb_strlen(trim((string) $observacao)) < 5) {
            throw new ErroNegocio('Indique como foi dado conhecimento ao colaborador.', 'OBSERVACAO_EM_FALTA', 422);
        }
        $a->update(['conhecimento' => ['em' => now()->toIso8601String(), 'por' => Auth::user()?->nome_utilizador, 'comentario' => $comentario, 'forma' => $peloRh ? 'RH' : 'PORTAL',
            'observacao' => $observacao]]);
        $this->auditoria->registar('Avaliação 360º', 'Tomada de conhecimento', null, 'avaliacoes_desempenho_rh', $a->id);

        return $a->refresh();
    }

    public function contestar(AvaliacaoDesempenhoRH $a, string $fundamentacao, array $pontos = []): AvaliacaoDesempenhoRH
    {
        $c = CicloAvaliacao360::query()->find($a->ciclo_avaliacao_id);
        if ($this->portal->colaboradorDe() !== $a->colaborador_id) {
            throw new ErroNegocio('Só o próprio contesta a sua avaliação.', 'SEM_PERMISSAO', 403);
        }
        if (self::fase($a, $c) !== 'PRAZO_CONTESTACAO') {
            throw new ErroNegocio('A avaliação não está em prazo de contestação.', 'FORA_DE_PRAZO', 422);
        }
        if (mb_strlen(trim($fundamentacao)) < 30) {
            throw new ErroNegocio('Fundamente a contestação (mínimo 30 caracteres).', 'FUNDAMENTACAO_CURTA', 422);
        }
        $chefe = app(ServicoAvaliacao::class)->chefiaNoPeriodo($a->colaborador_id, $c);
        $decisor = $chefe ? $this->estrutura->chefiaDe($chefe) : null;
        $decisor = $decisor === $a->colaborador_id ? null : $decisor;
        $a->update(['contestacao' => ['em' => now()->toIso8601String(), 'fundamentacao' => trim($fundamentacao), 'pontos' => $pontos, 'chefia_colaborador_id' => $chefe,
            'decisor_colaborador_id' => $decisor, 'decisor_nome' => $decisor ? Colaborador::query()->find($decisor)?->nome_completo : 'Recursos Humanos']]);
        $this->auditoria->registar('Avaliação 360º', 'Contestar avaliação', null, 'avaliacoes_desempenho_rh', $a->id);

        return $a->refresh();
    }

    public function parecerRh(AvaliacaoDesempenhoRH $a, string $texto): AvaliacaoDesempenhoRH
    {
        if (! $a->contestacao || ! empty($a->contestacao['decisao'])) {
            throw new ErroNegocio('Não há contestação pendente.', 'ESTADO_INVALIDO', 422);
        }
        if ($this->portal->colaboradorDe() === $a->colaborador_id) {
            throw new ErroNegocio('Não pode dar parecer sobre a sua própria contestação.', 'CONFLITO_INTERESSES', 403);
        }
        if (mb_strlen(trim($texto)) < 10) {
            throw new ErroNegocio('O parecer tem de ter pelo menos 10 caracteres.', 'PARECER_CURTO', 422);
        }
        $a->update(['contestacao' => array_merge($a->contestacao, ['parecer_rh' => ['por' => Auth::user()?->nome_utilizador, 'em' => now()->toIso8601String(), 'texto' => trim($texto)]])]);
        $this->auditoria->registar('Avaliação 360º', 'Parecer do RH', null, 'avaliacoes_desempenho_rh', $a->id);

        return $a->refresh();
    }

    public function decidirContestacao(AvaliacaoDesempenhoRH $a, string $resultado, string $justificacao, ?float $nota = null): AvaliacaoDesempenhoRH
    {
        $ct = $a->contestacao;
        if (! $ct || ! empty($ct['decisao'])) {
            throw new ErroNegocio('Não há contestação pendente.', 'ESTADO_INVALIDO', 422);
        }
        if (empty($ct['parecer_rh'])) {
            throw new ErroNegocio('Falta o parecer do RH.', 'PARECER_EM_FALTA', 422);
        }
        $eu = $this->portal->colaboradorDe();
        if ($eu && in_array($eu, [$a->colaborador_id, (int) ($ct['chefia_colaborador_id'] ?? 0)], true)) {
            throw new ErroNegocio('O avaliado e a chefia avaliadora não decidem a contestação.', 'CONFLITO_INTERESSES', 403);
        }
        $decisor = $ct['decisor_colaborador_id'] ?? null;
        if ($decisor ? $eu !== (int) $decisor : ! Gate::any(['rh_aval_parecer'])) {
            throw new ErroNegocio('A decisão cabe a '.($ct['decisor_nome'] ?? 'Recursos Humanos').'.', 'SEM_PERMISSAO', 403);
        }
        if (mb_strlen(trim($justificacao)) < 20) {
            throw new ErroNegocio('Justifique a decisão (mínimo 20 caracteres).', 'JUSTIFICACAO_CURTA', 422);
        }
        if ($resultado === 'ALTERADA' && ($nota === null || $nota < 1 || $nota > 5)) {
            throw new ErroNegocio('Indique a nova nota (1 a 5).', 'NOTA_INVALIDA', 422);
        }
        $ct['decisao'] = ['por' => Auth::user()?->nome_utilizador, 'em' => now()->toIso8601String(), 'resultado' => $resultado, 'justificacao' => trim($justificacao),
            'nota' => $resultado === 'ALTERADA' ? round($nota, 2) : null, 'classificacao' => $resultado === 'ALTERADA' ? ServicoAvaliacao::classificar($nota) : null];
        $a->update(['contestacao' => $ct]);
        $this->auditoria->registar('Avaliação 360º', 'Decidir contestação', $resultado, 'avaliacoes_desempenho_rh', $a->id);

        return $a->refresh();
    }

    // ───────────── Acompanhamento e comunicado ─────────────

    public function registarFeedback(array $d): FeedbackAvaliacao360
    {
        $c = CicloAvaliacao360::query()->findOrFail($d['ciclo_avaliacao_id']);
        $eu = $this->portal->colaboradorDe();
        $colab = (int) $d['colaborador_id'];
        $chefe = app(ServicoAvaliacao::class)->chefiaNoPeriodo($colab, $c);
        if ($eu === $colab) {
            throw new ErroNegocio('Não pode registar o seu próprio acompanhamento.', 'AUTO_AVALIACAO', 403);
        }
        if ($eu !== $chefe && ! Gate::any(['rh_avaliacao_edit'])) {
            throw new ErroNegocio('Só a chefia directa (ou o RH) regista o acompanhamento.', 'SEM_PERMISSAO', 403);
        }
        $f = FeedbackAvaliacao360::query()->where('ciclo_avaliacao_id', $c->id)->where('colaborador_id', $colab)->where('periodo_referencia', $d['periodo_referencia'])->first();
        if ($f?->confirmacao) {
            throw new ErroNegocio('O colaborador já confirmou esta reunião: não se altera.', 'FEEDBACK_CONFIRMADO', 422);
        }
        $dados = ['ciclo_avaliacao_id' => $c->id, 'colaborador_id' => $colab, 'colaborador_chefia_id' => $chefe, 'periodo_referencia' => $d['periodo_referencia'], 'data' => $d['data'],
            'objetivos' => $d['objetivos'] ?? [], 'positivos' => $d['positivos'] ?? null, 'melhorar' => $d['melhorar'] ?? null, 'acordos' => $d['acordos'] ?? null,
            'registado_por' => Auth::user()?->nome_utilizador, 'registado_em' => now()];
        $f ? $f->update($dados) : $f = FeedbackAvaliacao360::create($dados);

        return $f->refresh();
    }

    public function confirmarFeedback(FeedbackAvaliacao360 $f, ?string $comentario): FeedbackAvaliacao360
    {
        if ($this->portal->colaboradorDe() !== $f->colaborador_id) {
            throw new ErroNegocio('Só o próprio confirma a reunião de acompanhamento.', 'SEM_PERMISSAO', 403);
        }
        if ($f->confirmacao) {
            throw new ErroNegocio('A reunião já foi confirmada.', 'FEEDBACK_CONFIRMADO', 422);
        }
        $f->update(['confirmacao' => ['em' => now()->toIso8601String(), 'comentario' => $comentario]]);

        return $f->refresh();
    }

    public function confirmarComunicado(CicloAvaliacao360 $c): ConfirmacaoAvaliacaoRH
    {
        $eu = $this->portal->exigirColaborador()->id;

        return ConfirmacaoAvaliacaoRH::query()->firstOrCreate(['ciclo_avaliacao_id' => $c->id, 'colaborador_id' => $eu], ['em' => now()]);
    }

    // ───────────── Bonificação ─────────────

    public function calcularBonificacoes(CicloAvaliacao360 $c): array
    {
        $bm = (array) $c->bonificacao;
        if (($bm['metodo'] ?? 'NENHUM') === 'NENHUM') {
            throw new ErroNegocio('O ciclo não tem método de bonificação configurado.', 'SEM_METODO', 422);
        }

        return DB::transaction(function () use ($c, $bm) {
            if (BonificacaoAvaliacaoRH::query()->where('ciclo_avaliacao_id', $c->id)->where('estado', '<>', 'PROPOSTA')->lockForUpdate()->exists()) {
                throw new ErroNegocio('Há bonificações já aprovadas ou lançadas: anule-as antes de recalcular.', 'BONIFICACOES_BLOQUEADAS', 422);
            }
            $participantes = collect((array) $c->participantes)->map(fn ($p) => (int) ($p['colaborador_id'] ?? $p['employee_id']))->all();
            $linhas = [];
            $pendentes = 0;
            foreach (AvaliacaoDesempenhoRH::query()->where('ano', $c->ano)->where('periodo', $c->periodo)->whereIn('colaborador_id', $participantes)->get() as $a) {
                if (self::fase($a, $c) !== 'FINAL') {
                    $pendentes += $a->estado === 'CONCLUIDA' ? 1 : 0;

                    continue;
                }
                $classe = self::classificacaoFinal($a);
                $base = $this->remuneracaoMensal($a->colaborador_id);
                $valor = match ($bm['metodo']) {
                    'PERCENTAGEM' => (float) $base * (float) ($bm['tabela'][$classe] ?? 0) / 100 * ((float) ($bm['meses_base'] ?? 1) ?: 1),
                    'FIXO' => (float) ($bm['tabela'][$classe] ?? 0),
                    default => 0.0,
                };
                $linhas[] = ['a' => $a, 'nota' => self::notaFinal($a), 'classe' => $classe, 'base' => $base, 'valor' => round($valor, 2)];
            }
            if ($bm['metodo'] === 'BOLSA') {
                $montante = (float) $bm['bolsa']['montante'];
                $eleg = array_keys(array_filter($linhas, fn ($l) => (float) $l['nota'] >= (float) $bm['bolsa']['nota_minima']));
                $pontos = array_sum(array_map(fn ($k) => (float) $linhas[$k]['nota'], $eleg));
                foreach ($eleg as $k) {
                    $linhas[$k]['valor'] = $pontos ? round($montante * (float) $linhas[$k]['nota'] / $pontos, 2) : 0.0;
                }
                $dif = round($montante - array_sum(array_map(fn ($k) => $linhas[$k]['valor'], $eleg)), 2);
                if ($eleg && abs($dif) >= 0.01) {   // diferença de arredondamento no maior valor
                    $maior = collect($eleg)->sortByDesc(fn ($k) => $linhas[$k]['valor'])->first();
                    $linhas[$maior]['valor'] = round($linhas[$maior]['valor'] + $dif, 2);
                }
            }
            BonificacaoAvaliacaoRH::query()->where('ciclo_avaliacao_id', $c->id)->delete();
            $n = 0;
            foreach ($linhas as $l) {
                if ($l['valor'] > 0) {
                    BonificacaoAvaliacaoRH::create(['ciclo_avaliacao_id' => $c->id, 'colaborador_id' => $l['a']->colaborador_id, 'avaliacao_desempenho_id' => $l['a']->id,
                        'metodo' => $bm['metodo'], 'classificacao' => $l['classe'], 'nota' => $l['nota'], 'base' => $l['base'], 'valor' => $l['valor'], 'estado' => 'PROPOSTA',
                        'calculado_por' => Auth::user()?->nome_utilizador, 'calculado_em' => now()]);
                    $n++;
                }
            }
            $this->auditoria->registar('Avaliação 360º', 'Calcular bonificações', "{$n} proposta(s)", 'ciclos_avaliacao_360', $c->id);

            return ['propostas' => $n, 'total' => round(array_sum(array_column($linhas, 'valor')), 2), 'pendentes' => $pendentes,
                'sem_valor' => count(array_filter($linhas, fn ($l) => ! $l['valor']))];
        });
    }

    public function aprovarBonificacoes(CicloAvaliacao360 $c): int
    {
        return DB::transaction(function () use ($c) {
            $lista = BonificacaoAvaliacaoRH::query()->where('ciclo_avaliacao_id', $c->id)->where('estado', 'PROPOSTA')->lockForUpdate()->get();
            if ($lista->isEmpty()) {
                throw new ErroNegocio('Não há bonificações propostas.', 'SEM_PROPOSTAS', 422);
            }
            if ($lista->contains(fn ($x) => $x->calculado_por === Auth::user()?->nome_utilizador)) {
                throw new ErroNegocio('Quem calculou as bonificações não as pode aprovar.', 'SEGREGACAO_FUNCOES', 403);
            }
            $eu = $this->portal->colaboradorDe();
            if ($eu && $lista->contains('colaborador_id', $eu)) {
                throw new ErroNegocio('A lista inclui a sua própria bonificação: tem de ser aprovada por outra pessoa.', 'CONFLITO_INTERESSES', 403);
            }
            BonificacaoAvaliacaoRH::query()->whereKey($lista->pluck('id'))->update(['estado' => 'APROVADA', 'aprovado_por' => Auth::user()?->nome_utilizador, 'aprovado_em' => now()]);
            $this->auditoria->registar('Avaliação 360º', 'Aprovar bonificações', (string) $lista->count(), 'ciclos_avaliacao_360', $c->id);

            return $lista->count();
        });
    }

    public function lancarBonificacoes(CicloAvaliacao360 $c): int
    {
        $bm = (array) $c->bonificacao;
        $infotipo = $bm['infotipo_salarial_id'] ?? $bm['infotype_id'] ?? null;
        if (! $infotipo) {
            throw new ErroNegocio('Configure a rubrica de vencimento da bonificação.', 'RUBRICA_EM_FALTA', 422);
        }
        if (! preg_match('/^\d{2}\/\d{4}$/', (string) ($bm['mes_lancamento'] ?? ''))) {
            throw new ErroNegocio('Configure o mês de lançamento (MM/AAAA).', 'MES_EM_FALTA', 422);
        }

        return DB::transaction(function () use ($c, $bm, $infotipo) {
            $p = PeriodoProcessamentoSalarial::query()->where('mes_ano', $bm['mes_lancamento'])->lockForUpdate()->first()
                ?? throw new ErroNegocio("O processamento de {$bm['mes_lancamento']} ainda não existe.", 'PERIODO_INEXISTENTE', 422);
            if ($p->estado !== 'ABERTO') {
                throw new ErroNegocio("O processamento de {$bm['mes_lancamento']} não está aberto.", 'PERIODO_ESTADO_INVALIDO', 422);
            }
            $lista = BonificacaoAvaliacaoRH::query()->where('ciclo_avaliacao_id', $c->id)->where('estado', 'APROVADA')->lockForUpdate()->get();
            if ($lista->isEmpty()) {
                throw new ErroNegocio('Não há bonificações aprovadas por lançar.', 'SEM_APROVADAS', 422);
            }
            $ja = LinhaFolhaSalarial::query()->where('periodo_processamento_salarial_id', $p->id)->where('infotipo_salarial_id', $infotipo)->whereIn('colaborador_id', $lista->pluck('colaborador_id'))->pluck('colaborador_id');
            if ($ja->isNotEmpty()) {
                throw new ErroNegocio('Já há lançamentos desta rubrica no período para '.$ja->count().' colaborador(es): reveja-os antes de lançar a bonificação.', 'LANCAMENTO_EXISTENTE', 422,
                    ['colaboradores' => $ja->all()]);
            }
            foreach ($lista as $x) {
                $l = LinhaFolhaSalarial::create(['periodo_processamento_salarial_id' => $p->id, 'colaborador_id' => $x->colaborador_id, 'infotipo_salarial_id' => $infotipo,
                    'valor' => $x->valor, 'origem' => 'BONIFICACAO']);
                $x->update(['estado' => 'LANCADA', 'linha_folha_salarial_id' => $l->id, 'periodo_processamento_salarial_id' => $p->id, 'lancado_por' => Auth::user()?->nome_utilizador, 'lancado_em' => now()]);
            }
            $this->auditoria->registar('Avaliação 360º', 'Lançar bonificações', "{$lista->count()} em {$p->mes_ano}", 'ciclos_avaliacao_360', $c->id);

            return $lista->count();
        });
    }

    public function anularBonificacao(BonificacaoAvaliacaoRH $x): BonificacaoAvaliacaoRH
    {
        return DB::transaction(function () use ($x) {
            $x = BonificacaoAvaliacaoRH::query()->lockForUpdate()->findOrFail($x->id);
            $linha = $x->linha_folha_salarial_id;
            if ($x->estado === 'LANCADA') {
                $p = PeriodoProcessamentoSalarial::query()->find($x->periodo_processamento_salarial_id);
                if ($p && $p->estado !== 'ABERTO') {
                    throw new ErroNegocio('O processamento já foi encerrado: a bonificação não se anula por aqui.', 'PERIODO_ESTADO_INVALIDO', 422);
                }
            }
            $x->update(['estado' => 'PROPOSTA', 'linha_folha_salarial_id' => null, 'periodo_processamento_salarial_id' => null, 'aprovado_por' => null, 'aprovado_em' => null,
                'lancado_por' => null, 'lancado_em' => null]);
            $linha && LinhaFolhaSalarial::query()->whereKey($linha)->delete();
            $this->auditoria->registar('Avaliação 360º', 'Anular bonificação', null, 'bonificacoes_avaliacao_rh', $x->id);

            return $x->refresh();
        });
    }

    private function remuneracaoMensal(int $colaborador): string
    {
        return app(ServicoDocumentosRH::class)->remuneracaoMensal($colaborador);
    }

    // ───────────── Avaliação ascendente (portal) ─────────────

    public function responderAscendente(int $ano, string $periodo, array $respostas, ?string $comentario): void
    {
        $eu = $this->portal->exigirColaborador()->id;
        $alvo = $this->estrutura->chefiaDe($eu) ?? throw new ErroNegocio('Não tem chefia directa definida.', 'SEM_CHEFIA', 422);
        DB::transaction(function () use ($eu, $alvo, $ano, $periodo, $respostas, $comentario) {
            if (ParticipacaoAscendenteRH::query()->where('colaborador_id', $eu)->where('colaborador_alvo_id', $alvo)->where('ano', $ano)->where('periodo', $periodo)->lockForUpdate()->exists()) {
                throw new ErroNegocio('Já avaliou a sua chefia neste período.', 'JA_RESPONDIDO', 422);
            }
            $dadas = collect($respostas)->keyBy('chave');
            $lista = [];
            foreach (self::LIDERANCA as $chave => $nome) {
                $n = (int) ($dadas[$chave]['nota'] ?? 0);
                if ($n < 1 || $n > 5) {
                    throw new ErroNegocio("Dê uma nota de 1 a 5 a «{$nome}».", 'NOTA_EM_FALTA', 422);
                }
                $lista[] = ['chave' => $chave, 'nota' => $n];
            }
            ParticipacaoAscendenteRH::create(['uid' => (string) Str::uuid(), 'colaborador_id' => $eu, 'colaborador_alvo_id' => $alvo, 'ano' => $ano, 'periodo' => $periodo]);
            RespostaAscendenteRH::create(['uid' => (string) Str::uuid(), 'colaborador_alvo_id' => $alvo, 'ano' => $ano, 'periodo' => $periodo, 'respostas' => $lista, 'comentario' => $comentario]);
        });
    }

    /** Resultados da avaliação ascendente de uma chefia num período (só a própria chefia ou o RH; mínimo de anonimato). */
    public function resultadosAscendente(int $alvo, int $ano, string $periodo): array
    {
        if ($this->portal->colaboradorDe() !== $alvo && ! Gate::any(['rh_portal_aprovar'])) {
            throw new ErroNegocio('Só a própria chefia ou o RH vêem estes resultados.', 'SEM_PERMISSAO', 403);
        }
        $ciclo = CicloAvaliacao360::query()->where('ano', $ano)->where('periodo', $periodo)->first();
        $min = $ciclo ? ((int) $ciclo->minimo_anonimato ?: 3) : 3;
        $rs = RespostaAscendenteRH::query()->where('colaborador_alvo_id', $alvo)->where('ano', $ano)->where('periodo', $periodo)->get();
        if ($rs->count() < $min || ($ciclo && ! self::resultadosLiberados($ciclo))) {
            return ['respostas' => $rs->count(), 'minimo' => $min, 'liberado' => false];
        }
        $medias = [];
        foreach (self::LIDERANCA as $chave => $nome) {
            $v = $rs->map(fn ($r) => collect((array) $r->respostas)->firstWhere('chave', $chave)['nota'] ?? null)->filter();
            $medias[$chave] = ['nome' => $nome, 'media' => $v->isEmpty() ? null : round($v->avg(), 2)];
        }
        $validas = array_filter(array_column($medias, 'media'), fn ($m) => $m !== null);

        return ['respostas' => $rs->count(), 'minimo' => $min, 'liberado' => true, 'questoes' => $medias,
            'media' => $validas ? round(array_sum($validas) / count($validas), 2) : null, 'comentarios' => $rs->pluck('comentario')->filter()->sort(fn ($a, $b) => strcoll($a, $b))->values()->all()];
    }
}
