<?php

namespace App\Services\Projetos;

use App\Exceptions\ErroNegocio;
use App\Models\Colaborador;
use App\Models\FolhaHorasProjeto;
use App\Models\LinhaOrcamentoProjeto;
use App\Models\MarcoProjeto;
use App\Models\NoOrganigramaProjeto;
use App\Models\Projeto;
use App\Models\TarefaProjeto;
use App\Services\RH\ServicoProdutividadeProjetos;
use Illuminate\Support\Collection;

/**
 * Leituras da execução do projecto (Versão Actual, 2026-10-11), calculadas no servidor para os números baterem certo
 * entre ecrãs (as mesmas regras de RegrasMetasProjetos):
 *   - separador «Execução»: horas, quantidades medidas e tarefas concluídas no intervalo, por colaborador, com a parte
 *     sem dono assinalada (renderProjectExecucao, ui_projects.js:5715-5880);
 *   - mapa de quantidades: previsto (meta repartida pelo calendário) e executado (medições) por coluna da escala, uma
 *     tabela por unidade, e as vistas Horas (previstas × lançadas) e Valor (orçamento mapeado), com fase › tarefa ›
 *     subtarefa nas linhas (mapaQuantidades, ui_projects.js:925-1477; projectos_metas.js:249-333);
 *   - carga de trabalho da equipa e base da ordem de trabalho: por responsável, com as tarefas de uma área repartidas
 *     pelos membros da área (horas alocadas ou partes iguais) e o grupo «Sem responsável»
 *     (renderCargaTrabalho / cargaOrdemTrabalho, ui_projects.js:2683-3063).
 * Regras do legado mantidas: no mapa só contam as tarefas com meta própria e sem filhos com meta; nas horas entram as
 * tarefas com prazo e sem filhos com prazo, e as que têm horas lançadas; na carga uma tarefa conta para um só
 * responsável e só contam as tarefas sem filhos com prazo ou meta.
 */
final class ServicoPaineisExecucaoProjetos
{
    public function __construct(
        private readonly ServicoEquipasProjetos $equipas,
        private readonly ServicoProdutividadeProjetos $ponte,
    ) {}

    // ───────────── Separador Execução ─────────────

    public function execucao(Projeto $p, string $de, string $ate): array
    {
        $colheita = $this->ponte->recolher($de, $ate, [$p->id]);
        $tarefas = TarefaProjeto::query()->where('projeto_id', $p->id)->get()->keyBy('id');
        $nomeT = fn ($id) => isset($tarefas[$id]) ? trim(($tarefas[$id]->codigo ? "{$tarefas[$id]->codigo} " : '').$tarefas[$id]->nome) : '—';
        $membros = collect($this->equipas->membros($p))->keyBy('id');
        $folhas = FolhaHorasProjeto::query()->where('projeto_id', $p->id)->whereBetween('data', [$de, $ate])->orderByDesc('data')->get();
        $nomesC = Colaborador::query()->withTrashed()->whereIn('id', $folhas->pluck('colaborador_id')->filter()->unique()->values()->all() ?: [0])->pluck('nome_completo', 'id');
        $medicoes = [];
        foreach ($tarefas as $t) {
            foreach ($t->medicoes() as $x) {
                $d = substr((string) ($x['data'] ?? ''), 0, 10);
                if ($d !== '' && $d >= $de && $d <= $ate) {
                    $medicoes[] = ['tarefa_id' => $t->id, 'tarefa' => $nomeT($t->id), 'unidade' => $t->unidadeMeta(), 'data' => $d, 'quantidade' => (float) ($x['q'] ?? 0),
                        'nota' => $x['nota'] ?? '', 'por' => $x['por'] ?? '', 'responsavel' => $t->atribuido_a_id ? ($membros[$t->atribuido_a_id]['nome'] ?? null) : null];
                }
            }
        }
        usort($medicoes, fn ($a, $b) => strcmp($b['data'], $a['data']));
        $concluidas = $tarefas->filter(fn ($t) => ($d = $t->concluida_em?->toDateString()) && $d >= $de && $d <= $ate)->values();
        $porUnidade = [];
        foreach ($medicoes as $m) {
            $porUnidade[$m['unidade']] = RegrasMetasProjetos::r4(($porUnidade[$m['unidade']] ?? 0) + $m['quantidade']);
        }
        $atribuivel = fn (string $tipo, ?string $un = null) => RegrasMetasProjetos::r4(collect($colheita['linhas'])->filter(fn ($l) => $l['tipo'] === $tipo && ($un === null || $l['unidade'] === $un))->sum('quantidade'));
        $porPessoa = [];
        foreach ($colheita['linhas'] as $l) {
            $k = $l['colaborador_id'];
            $porPessoa[$k] ??= ['colaborador_id' => $k, 'nome' => $l['nome'], 'horas' => 0.0, 'tarefas' => 0, 'quantidades' => []];
            if ($l['tipo'] === 'HORAS') {
                $porPessoa[$k]['horas'] = RegrasMetasProjetos::r4($porPessoa[$k]['horas'] + $l['quantidade']);
            } elseif ($l['tipo'] === 'TAREFAS') {
                $porPessoa[$k]['tarefas'] += (int) $l['quantidade'];
            } else {
                $porPessoa[$k]['quantidades'][$l['unidade']] = RegrasMetasProjetos::r4(($porPessoa[$k]['quantidades'][$l['unidade']] ?? 0) + $l['quantidade']);
            }
        }

        return [
            'de' => $de, 'ate' => $ate,
            'cartoes' => [
                'horas' => ['total' => RegrasMetasProjetos::r4($folhas->sum(fn ($f) => (float) $f->horas)), 'atribuivel' => $atribuivel('HORAS'), 'registos' => $folhas->count()],
                'quantidades' => collect($porUnidade)->map(fn ($v, $u) => ['unidade' => $u, 'total' => $v, 'atribuivel' => $atribuivel('QUANTIDADE', $u)])->values()->all(),
                'tarefas' => ['total' => $concluidas->count(), 'atribuivel' => (int) $atribuivel('TAREFAS')],
            ],
            'por_colaborador' => collect($porPessoa)->sortBy(fn ($x) => mb_strtolower($x['nome']))->values()->all(),
            'sem_dono' => $colheita['orfas'],
            'folhas' => $folhas->map(fn ($f) => ['id' => $f->id, 'data' => $f->data?->toDateString(), 'tarefa_id' => $f->tarefa_projeto_id, 'tarefa' => $nomeT($f->tarefa_projeto_id),
                'colaborador' => $f->colaborador_id ? ($nomesC[$f->colaborador_id] ?? "#{$f->colaborador_id}") : ($f->membro_equipa_projeto_id ? ($membros[$f->membro_equipa_projeto_id]['nome'] ?? null) : null),
                'horas' => (float) $f->horas, 'estado' => $f->estado])->values()->all(),
            'medicoes' => $medicoes,
            'concluidas' => $concluidas->map(fn ($t) => ['tarefa_id' => $t->id, 'tarefa' => $nomeT($t->id), 'concluida_em' => $t->concluida_em?->toDateString(),
                'responsavel' => $t->atribuido_a_id ? ($membros[$t->atribuido_a_id]['nome'] ?? null) : null])->all(),
        ];
    }

    // ───────────── Mapa de quantidades ─────────────

    /**
     * Matriz do mapa de quantidades (mapaQuantidades, ui_projects.js:925-1477).
     *
     * @return array{escala: string, de: ?string, ate: ?string, colunas: list<array<string, mixed>>, quantidades: list<array<string, mixed>>, horas: array<string, mixed>, valor: array<string, mixed>}
     */
    public function mapa(Projeto $p, string $escala, ?string $de, ?string $ate): array
    {
        $tarefas = TarefaProjeto::query()->where('projeto_id', $p->id)->get()->keyBy('id');
        $marcos = MarcoProjeto::query()->where('projeto_id', $p->id)->orderByRaw('COALESCE(data_inicio, data)')->orderBy('id')->get();
        $cal = RegrasMetasProjetos::calendario($p->calendario);
        $de = $de ?: $tarefas->pluck('data_inicio')->filter()->min()?->toDateString();
        $ate = $ate ?: $tarefas->map(fn ($t) => $t->data_fim ?? $t->data_inicio)->filter()->max()?->toDateString();
        if ($de && $ate && $ate < $de) {
            throw new ErroNegocio('O fim da janela não pode ser anterior ao início.', 'DATAS_INVALIDAS', 422);
        }
        $colunas = $de && $ate ? RegrasMetasProjetos::periodosDaEscala($de, $ate, $escala) : [];
        if (count($colunas) > 400) {
            throw new ErroNegocio('A janela tem colunas a mais para a escala escolhida: escolha uma escala maior ou um período mais curto.', 'JANELA_EXCESSIVA', 422);
        }
        $n = count($colunas);
        $filhos = $tarefas->groupBy(fn ($t) => (int) $t->tarefa_pai_id);
        $medidas = RegrasMetasProjetos::medidas($tarefas->values())->keyBy('id');

        // valores-folha por tarefa → somados pelos ancestrais (fase › tarefa › subtarefa)
        $arvore = function (callable $folha, callable $contavel) use ($tarefas, $marcos, $filhos, $n): array {
            $zeros = array_fill(0, $n, 0.0);
            $somar = fn (array $a, array $b) => array_map(fn ($x, $y) => RegrasMetasProjetos::r2($x + $y), $a, $b);
            $memo = [];
            $calc = function (TarefaProjeto $t, array $pilha) use (&$calc, &$memo, $folha, $contavel, $filhos, $zeros, $somar) {
                if (isset($memo[$t->id])) {
                    return $memo[$t->id];
                }
                $r = ['previsto' => $zeros, 'realizado' => $zeros, 'tem' => false];
                if ($contavel($t)) {
                    $f = $folha($t);
                    $r = ['previsto' => $f['previsto'], 'realizado' => $f['realizado'], 'tem' => true];
                }
                foreach ($filhos[$t->id] ?? [] as $c) {
                    if (isset($pilha[$c->id])) {
                        continue;
                    }
                    $x = $calc($c, $pilha + [$c->id => true]);
                    if ($x['tem']) {
                        $r = ['previsto' => $somar($r['previsto'], $x['previsto']), 'realizado' => $somar($r['realizado'], $x['realizado']), 'tem' => true];
                    }
                }

                return $memo[$t->id] = $r;
            };
            $linhas = [];
            $emitir = function (TarefaProjeto $t, int $nivel) use (&$emitir, &$linhas, $calc, $filhos) {
                $r = $calc($t, [$t->id => true]);
                if (! $r['tem']) {
                    return;
                }
                $linhas[] = ['chave' => "t-{$t->id}", 'tipo' => 'tarefa', 'id' => $t->id, 'nivel' => $nivel, 'nome' => trim(($t->codigo ? "{$t->codigo} " : '').$t->nome),
                    'previsto' => $r['previsto'], 'realizado' => $r['realizado'], 'total_previsto' => RegrasMetasProjetos::r2(array_sum($r['previsto'])),
                    'total_realizado' => RegrasMetasProjetos::r2(array_sum($r['realizado']))];
                foreach (ServicoHierarquiaProjetos::ordenarIrmaos($filhos[$t->id] ?? collect()) as $c) {
                    $emitir($c, $nivel + 1);
                }
            };
            $raiz = $tarefas->whereNull('tarefa_pai_id');
            $grupos = $marcos->map(fn ($m) => ['marco' => $m, 'tarefas' => $raiz->where('marco_projeto_id', $m->id)])->push(['marco' => null,
                'tarefas' => $raiz->filter(fn ($t) => ! $t->marco_projeto_id || ! $marcos->contains('id', $t->marco_projeto_id))]);
            $totP = $zeros;
            $totR = $zeros;
            foreach ($grupos as $g) {
                $ini = count($linhas);
                $soma = ['previsto' => $zeros, 'realizado' => $zeros, 'tem' => false];
                foreach (ServicoHierarquiaProjetos::ordenarIrmaos($g['tarefas']) as $t) {
                    $x = $calc($t, [$t->id => true]);
                    if ($x['tem']) {
                        $soma = ['previsto' => $somar($soma['previsto'], $x['previsto']), 'realizado' => $somar($soma['realizado'], $x['realizado']), 'tem' => true];
                    }
                    $emitir($t, 1);
                }
                if (! $soma['tem']) {
                    continue;
                }
                array_splice($linhas, $ini, 0, [['chave' => 'm-'.($g['marco']?->id ?? 'sem'), 'tipo' => 'fase', 'id' => $g['marco']?->id, 'nivel' => 0,
                    'nome' => $g['marco']?->nome ?? 'Sem fase', 'previsto' => $soma['previsto'], 'realizado' => $soma['realizado'],
                    'total_previsto' => RegrasMetasProjetos::r2(array_sum($soma['previsto'])), 'total_realizado' => RegrasMetasProjetos::r2(array_sum($soma['realizado']))]]);
                $totP = $somar($totP, $soma['previsto']);
                $totR = $somar($totR, $soma['realizado']);
            }

            return ['linhas' => $linhas, 'totais' => ['previsto' => $totP, 'realizado' => $totR,
                'total_previsto' => RegrasMetasProjetos::r2(array_sum($totP)), 'total_realizado' => RegrasMetasProjetos::r2(array_sum($totR))]];
        };

        // Quantidades: uma tabela por unidade
        $quantidades = [];
        foreach ($medidas->map(fn ($t) => $t->unidadeMeta())->unique()->sort() as $un) {
            $tab = $arvore(fn ($t) => ['previsto' => RegrasMetasProjetos::repartirNoTempo((float) $t->meta_quantidade, $t->data_inicio?->toDateString(), $t->data_fim?->toDateString(), $colunas, $cal)['por_coluna'],
                'realizado' => RegrasMetasProjetos::realizadoNoTempo($t, $colunas)], fn ($t) => isset($medidas[$t->id]) && $t->unidadeMeta() === $un);
            $quantidades[] = ['unidade' => $un, 'meta' => RegrasMetasProjetos::r2($medidas->filter(fn ($t) => $t->unidadeMeta() === $un)->sum(fn ($t) => (float) $t->meta_quantidade))] + $tab;
        }

        // Horas: previstas pelo calendário × lançadas nas folhas
        $folhas = FolhaHorasProjeto::query()->where('projeto_id', $p->id)->when($de && $ate, fn ($q) => $q->whereBetween('data', [$de, $ate]))->get()->groupBy('tarefa_projeto_id');
        $comPrazo = fn ($t) => $t->data_inicio && ($t->data_fim ?? $t->data_inicio);
        $temFilhoComPrazo = $tarefas->filter(fn ($t) => $t->tarefa_pai_id && $comPrazo($t))->pluck('tarefa_pai_id')->map(fn ($v) => (int) $v)->flip();
        $horas = $arvore(function ($t) use ($colunas, $cal, $folhas, $comPrazo, $temFilhoComPrazo) {
            $prev = $comPrazo($t) && ! isset($temFilhoComPrazo[$t->id]) ? RegrasMetasProjetos::horasPrevistasNoTempo($t->data_inicio?->toDateString(), ($t->data_fim ?? $t->data_inicio)?->toDateString(), $colunas, $cal)['por_coluna']
                : array_fill(0, count($colunas), 0.0);
            $lanc = array_map(fn ($c) => RegrasMetasProjetos::r2(($folhas[$t->id] ?? collect())->filter(fn ($f) => ($d = $f->data?->toDateString()) >= $c['de'] && $d <= $c['ate'])->sum(fn ($f) => (float) $f->horas)), $colunas);

            return ['previsto' => $prev, 'realizado' => $lanc];
        }, fn ($t) => ($comPrazo($t) && ! isset($temFilhoComPrazo[$t->id])) || isset($folhas[$t->id]));
        $horas['totais']['rendimento_pct'] = $horas['totais']['total_previsto'] > 0 ? (int) round($horas['totais']['total_realizado'] / $horas['totais']['total_previsto'] * 100) : null;

        // Valor: orçamento mapeado pelas quantidades, período a período
        $valorTarefa = ServicoOrcamentoQuantidadesProjetos::valorPorTarefa(LinhaOrcamentoProjeto::query()->where('projeto_id', $p->id)->get(), $tarefas->values());
        $valor = $arvore(function ($t) use ($valorTarefa, $colunas, $cal) {
            $v = (float) ($valorTarefa[$t->id] ?? 0);
            $preco = (float) $t->meta_quantidade > 0 ? $v / (float) $t->meta_quantidade : 0;

            return ['previsto' => RegrasMetasProjetos::repartirNoTempo($v, $t->data_inicio?->toDateString(), $t->data_fim?->toDateString(), $colunas, $cal)['por_coluna'],
                'realizado' => array_map(fn ($q) => RegrasMetasProjetos::r2($q * $preco), RegrasMetasProjetos::realizadoNoTempo($t, $colunas))];
        }, fn ($t) => isset($medidas[$t->id]) && ($valorTarefa[$t->id] ?? 0) > 0);

        return ['escala' => in_array($escala, RegrasMetasProjetos::ESCALAS, true) ? $escala : 'mes', 'de' => $de, 'ate' => $ate, 'colunas' => $colunas,
            'calendario' => $cal, 'quantidades' => $quantidades, 'horas' => $horas, 'valor' => $valor + ['total_mapeado' => RegrasMetasProjetos::r2(array_sum($valorTarefa))]];
    }

    // ───────────── Carga de trabalho / ordem de trabalho ─────────────

    /** Carga da equipa por responsável (renderCargaTrabalho, ui_projects.js:2683-2934). */
    public function carga(Projeto $p): array
    {
        $tarefas = TarefaProjeto::query()->where('projeto_id', $p->id)->get();
        $porId = $tarefas->keyBy('id');
        $filhos = $tarefas->groupBy(fn ($t) => (int) $t->tarefa_pai_id);
        $marcos = MarcoProjeto::query()->where('projeto_id', $p->id)->pluck('nome', 'id');
        $nos = NoOrganigramaProjeto::query()->where('projeto_id', $p->id)->orderBy('id')->get();
        $membros = collect($this->equipas->membros($p));
        $cal = RegrasMetasProjetos::calendario($p->calendario);
        $folhas = FolhaHorasProjeto::query()->where('projeto_id', $p->id)->get();
        $porColab = $membros->filter(fn ($m) => $m['colaborador_id'])->keyBy('colaborador_id');
        $horasLanc = [];   // tarefa|membro → horas
        foreach ($folhas as $f) {
            $m = $f->membro_equipa_projeto_id ?: ($f->colaborador_id ? ($porColab[$f->colaborador_id]['id'] ?? null) : null);
            $horasLanc["{$f->tarefa_projeto_id}|".($m ?: 0)] = ($horasLanc["{$f->tarefa_projeto_id}|".($m ?: 0)] ?? 0) + (float) $f->horas;
        }
        $contavel = fn (TarefaProjeto $t) => ! ($filhos[$t->id] ?? collect())->contains(fn ($x) => ($x->data_inicio && $x->data_fim) || $x->temMeta());
        $nomeNo = fn ($n) => $n->titulo ?: ($n->area ?: "Posição {$n->id}");
        // tarefas de uma área sem responsável directo → membros da área (horas alocadas ou partes iguais)
        $areasDaTarefa = [];
        $semGente = [];
        $quota = [];
        foreach ($nos as $n) {
            $gente = $membros->where('no_organigrama_projeto_id', $n->id)->values();
            foreach (array_map('intval', $n->tarefas ?? []) as $tid) {
                if (! isset($porId[$tid])) {
                    continue;
                }
                $areasDaTarefa[$tid][] = $nomeNo($n);
                if ($gente->isEmpty()) {
                    $semGente[$tid][] = $nomeNo($n);
                }
            }
        }
        $partilhada = [];
        foreach ($nos as $n) {
            foreach ($membros->where('no_organigrama_projeto_id', $n->id) as $m) {
                foreach (array_map('intval', $n->tarefas ?? []) as $tid) {
                    if (isset($porId[$tid]) && ! $porId[$tid]->atribuido_a_id) {
                        $partilhada[$tid][$m['id']] = $m;
                    }
                }
            }
        }
        foreach ($partilhada as $tid => $ms) {
            $pesos = array_map(fn ($m) => max(0.0, (float) $m['horas_alocadas']), $ms);
            $soma = array_sum($pesos);
            foreach ($ms as $mid => $m) {
                $q = $soma > 0 ? $pesos[$mid] / $soma : 1 / count($ms);
                if ($q > 0) {
                    $quota["{$mid}|{$tid}"] = round($q, 4);
                }
            }
        }
        $ordenadas = $this->ordenarArvore($tarefas, $filhos);
        $caminho = function (TarefaProjeto $t) use ($porId) {
            $out = [];
            $x = $t->tarefa_pai_id ? ($porId[$t->tarefa_pai_id] ?? null) : null;
            $g = 0;
            while ($x && $g++ < 20) {
                array_unshift($out, trim(($x->codigo ? "{$x->codigo} " : '').$x->nome));
                $x = $x->tarefa_pai_id ? ($porId[$x->tarefa_pai_id] ?? null) : null;
            }

            return $out;
        };
        $linha = function (TarefaProjeto $t, ?array $m, float $q) use ($contavel, $cal, $horasLanc, $marcos, $caminho, $areasDaTarefa, $semGente) {
            $hp = $contavel($t) ? RegrasMetasProjetos::horasPrevistas($t->data_inicio?->toDateString(), $t->data_fim?->toDateString(), $cal) * $q : 0;
            $ritmo = RegrasMetasProjetos::ritmo($t->temMeta() ? (float) $t->meta_quantidade * $q : null, $t->meta_unidade, $t->data_inicio?->toDateString(), $t->data_fim?->toDateString(), $cal);

            return ['tarefa_id' => $t->id, 'codigo' => $t->codigo, 'nome' => $t->nome, 'fase' => $marcos[$t->marco_projeto_id] ?? null, 'caminho' => $caminho($t),
                'nivel' => count($caminho($t)), 'inicio' => $t->data_inicio?->toDateString(), 'fim' => $t->data_fim?->toDateString(), 'estado' => $t->estadoEfetivo(),
                'execucao' => $t->pctDaMeta() ?? (int) round((float) $t->percentagem_execucao), 'quota' => $q, 'da_area' => ! $t->atribuido_a_id,
                'areas' => $areasDaTarefa[$t->id] ?? [], 'areas_sem_gente' => $semGente[$t->id] ?? [],
                'meta' => $t->temMeta() ? ['unidade' => $t->unidadeMeta(), 'quantidade' => RegrasMetasProjetos::r2((float) $t->meta_quantidade * $q),
                    'realizado' => RegrasMetasProjetos::r2((float) $t->meta_realizado * $q)] : null,
                'horas_previstas' => RegrasMetasProjetos::r2($hp), 'horas_lancadas' => RegrasMetasProjetos::r2($m ? ($horasLanc["{$t->id}|{$m['id']}"] ?? 0) : array_sum(array_map(
                    fn ($k) => $horasLanc[$k], array_filter(array_keys($horasLanc), fn ($k) => str_starts_with($k, "{$t->id}|"))))),
                'ritmo' => $ritmo];
        };
        $grupos = [];
        foreach ($membros as $m) {
            $lista = $ordenadas->filter(fn ($t) => (int) $t->atribuido_a_id === (int) $m['id'] || isset($quota["{$m['id']}|{$t->id}"]))
                ->map(fn ($t) => $linha($t, $m, (int) $t->atribuido_a_id === (int) $m['id'] ? 1.0 : $quota["{$m['id']}|{$t->id}"]))->values()->all();
            $grupos[] = ['chave' => "m{$m['id']}", 'membro_id' => $m['id'], 'nome' => $m['nome'], 'tipo' => $m['tipo'], 'papel' => $m['papel'], 'horas_alocadas' => (float) $m['horas_alocadas'],
                'posicao' => $m['posicao'], 'tarefas' => $lista] + self::totaisCarga($lista);
        }
        $semDono = $ordenadas->filter(fn ($t) => ! $t->atribuido_a_id && ! isset($partilhada[$t->id]) && (($t->data_inicio && $t->data_fim) || $t->temMeta()))
            ->map(fn ($t) => $linha($t, null, 1.0))->values()->all();
        if ($semDono) {
            $grupos[] = ['chave' => 'sem', 'membro_id' => null, 'nome' => 'Sem responsável atribuído', 'tipo' => null, 'papel' => null, 'horas_alocadas' => 0.0, 'posicao' => null,
                'tarefas' => $semDono] + self::totaisCarga($semDono);
        }

        return ['projeto' => ['id' => $p->id, 'codigo' => $p->codigo, 'nome' => $p->nome], 'calendario' => $cal + ['modelo' => RegrasMetasProjetos::modeloDe($cal)],
            'grupos' => $grupos, 'areas_sem_gente' => array_values(array_unique(array_merge([], ...array_values($semGente))))];
    }

    private static function totaisCarga(array $lista): array
    {
        $q = [];
        foreach ($lista as $l) {
            if ($l['meta']) {
                $q[$l['meta']['unidade']] = RegrasMetasProjetos::r2(($q[$l['meta']['unidade']] ?? 0) + $l['meta']['quantidade']);
            }
        }

        return ['n_tarefas' => count($lista), 'horas_previstas' => RegrasMetasProjetos::r2(array_sum(array_column($lista, 'horas_previstas'))),
            'horas_lancadas' => RegrasMetasProjetos::r2(array_sum(array_column($lista, 'horas_lancadas'))), 'quantidades' => $q];
    }

    /** Tarefas pela ordem da árvore (raiz por fase e data; filhos a seguir ao pai). */
    private function ordenarArvore(Collection $tarefas, Collection $filhos): Collection
    {
        $saida = [];
        $visitar = function (Collection $grupo) use (&$visitar, &$saida, $filhos) {
            foreach (ServicoHierarquiaProjetos::ordenarIrmaos($grupo) as $t) {
                if (isset($saida[$t->id])) {
                    continue;
                }
                $saida[$t->id] = $t;
                $visitar($filhos[$t->id] ?? collect());
            }
        };
        foreach ($tarefas->whereNull('tarefa_pai_id')->groupBy(fn ($t) => (int) $t->marco_projeto_id) as $g) {
            $visitar($g);
        }

        return collect(array_values($saida));
    }
}
