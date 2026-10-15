<?php

namespace App\Services\Projetos;

use App\Exceptions\ErroNegocio;
use App\Models\ConfiguracaoProjeto;
use App\Models\EquipaProjeto;
use App\Models\FolhaHorasProjeto;
use App\Models\ItemCompra;
use App\Models\LinhaOrcamentoProjeto;
use App\Models\LinhaRequisicaoProjeto;
use App\Models\LinhaRevisaoProjeto;
use App\Models\MarcoProjeto;
use App\Models\MembroEquipaProjeto;
use App\Models\NoOrganigramaProjeto;
use App\Models\Projeto;
use App\Models\RazaoAnaliticoProjeto;
use App\Models\TarefaProjeto;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Planeamento: milestones (marcos), tarefas e subtarefas (WBS), Kanban e cálculo da execução
 * (renderProjectPlaneamento, saveTask, saveMilestone, projMoverTarefa, projEliminarTarefa, projEliminarMilestone,
 * manageKanbanColumns, js/ui_projects.js:675-2054, 2997-3101).
 * Mantém do legado:
 *   - execução de uma tarefa com subtarefas = média (arredondada) das subtarefas, recursivamente; milestone = média das
 *     suas tarefas principais; global = média das tarefas principais;
 *   - CONCLUIDA força 100 %; a percentagem fica entre 0 e 100;
 *   - mover: antes/depois/dentro de outra tarefa ou para um milestone; as subtarefas acompanham o milestone;
 *     os irmãos são renumerados 10, 20, 30…; não se move uma tarefa para dentro dela própria;
 *   - eliminar uma tarefa elimina as subtarefas, e é recusado se houver horas, movimentos analíticos, autos, orçamento,
 *     requisições ou compras imputadas; confirmação «ELIMINAR»; as posições do organigrama perdem a tarefa;
 *   - eliminar um milestone deixa as suas tarefas «sem milestone»;
 *   - Kanban com colunas configuráveis por projecto (4 base + personalizadas).
 * Correcções:
 *   - uma só regra de execução em todos os ecrãs (o painel de resumo e o gráfico por milestone usavam médias
 *     diferentes das do WBS, projectos_dashboard.js:134-141 e 578);
 *   - data de fim anterior ao início é recusada (o legado gravava; há 2 casos nos dados);
 *   - tarefa-pai, milestone e responsável têm de pertencer ao mesmo projecto;
 *   - BLOQUEADA é um estado válido (o esquema migrado só aceita 3 códigos: enquanto não for corrigido, grava-se
 *     estado NULL e «BLOQUEADA» em estado_original — ServicoProjetos::codigoAceite);
 *   - coluna do Kanban em estado_original, com o estado-base da coluna em `estado` (o legado gravava o id da coluna
 *     personalizada como estado, ex.: FAZENDO, que o resto do sistema não reconhecia).
 * Fase E — Versão Actual (2026-10-11: projectos_metas.js, projectos_hierarquia.js, ui_projects.js:2380-2475):
 *   - meta por quantidade na tarefa (unidade obrigatória com meta; quantidades ≥ 0); com meta a % deixa de se escrever
 *     à mão: pct = realizado ÷ meta; ao chegar a 100 % a tarefa fica CONCLUÍDA; com subtarefas com meta o realizado
 *     sobe delas e não se escreve no pai;
 *   - progresso ponderado pela quantidade quando os filhos têm todos meta na mesma unidade (progresso, :51-63) e
 *     execução de um conjunto (fase, global) ponderada quando possível (progressoConjunto, :105-110);
 *   - fase com data de início e meta (unidade + quantidade); a data alvo não pode ser anterior à de início;
 *   - datas dentro do nível acima e dependência FS/SS/FF validadas ao gravar e ao mover (ServicoHierarquiaProjetos);
 *   - `concluida_em` posta quando a tarefa passa a CONCLUÍDA (modal, Kanban ou meta atingida) e retirada quando sai;
 *     reeditar uma tarefa concluída mantém a data original;
 *   - posição do organigrama escolhida no modal da tarefa (a ligação vive no nó, project_org_nodes.tarefas);
 *   - WBS ordenada por data e precedência entre irmãos (sem datas vale a ordem manual).
 *   Correcções: eliminar uma tarefa retira a dependência das tarefas que dependiam dela (a FK é RESTRICT; o legado
 *   deixava-as a apontar para um id inexistente); a % manual é recusada numa tarefa com meta (só se mede).
 */
final class ServicoPlaneamentoProjetos
{
    public const KANBAN_PADRAO = [
        ['id' => 'PENDENTE', 'titulo' => 'A Fazer', 'cor' => '#64748b', 'estado' => 'PENDENTE'],
        ['id' => 'EM_CURSO', 'titulo' => 'Em Curso', 'cor' => '#3b82f6', 'estado' => 'EM_CURSO'],
        ['id' => 'CONCLUIDA', 'titulo' => 'Concluida', 'cor' => '#10b981', 'estado' => 'CONCLUIDA'],
        ['id' => 'BLOQUEADA', 'titulo' => 'Bloqueada', 'cor' => '#ef4444', 'estado' => 'BLOQUEADA'],
    ];

    public function __construct(private readonly ServicoProjetos $projetos) {}

    // ───────────── Execução (%) ─────────────

    /**
     * Execução por tarefa (calcProgress, ui_projects.js:716-731; Versão Actual: ProjMetas.progresso,
     * projectos_metas.js:51-63): folha = % da meta (com meta) ou a percentagem própria; com subtarefas que têm todas
     * meta na mesma unidade = Σ min(feito, meta) ÷ Σ meta; senão a média arredondada das subtarefas.
     *
     * @param  Collection<int, TarefaProjeto>  $tarefas
     * @return array<int, int>
     */
    public static function execucoes(Collection $tarefas): array
    {
        $filhos = $tarefas->groupBy(fn ($t) => (int) $t->tarefa_pai_id);
        $memo = [];
        $calc = function (TarefaProjeto $t, array $pilha = []) use (&$calc, &$memo, $filhos) {
            if (isset($memo[$t->id])) {
                return $memo[$t->id];
            }
            $f = ($filhos[$t->id] ?? collect())->reject(fn ($x) => isset($pilha[$x->id]));
            if ($f->isEmpty()) {
                return $memo[$t->id] = $t->pctDaMeta() ?? (int) round((float) $t->percentagem_execucao);
            }
            $unidades = $f->map(fn ($x) => trim((string) $x->meta_unidade))->filter()->unique();
            if ($f->every(fn ($x) => $x->temMeta()) && $unidades->count() === 1) {
                $meta = $f->sum(fn ($x) => (float) $x->meta_quantidade);
                $feito = $f->sum(fn ($x) => min((float) $x->meta_realizado, (float) $x->meta_quantidade));

                return $memo[$t->id] = $meta > 0 ? (int) round($feito / $meta * 100) : 0;
            }
            $soma = $f->sum(fn ($x) => $calc($x, $pilha + [$t->id => true]));

            return $memo[$t->id] = (int) round($soma / $f->count());
        };
        $saida = [];
        foreach ($tarefas as $t) {
            $saida[$t->id] = $calc($t);
        }

        return $saida;
    }

    /**
     * Execução global (tarefas principais) e por milestone (calcMilestoneProgress; Versão Actual: progressoConjunto,
     * projectos_metas.js:105-110): ponderada pela quantidade quando todas as tarefas do conjunto têm meta numa só
     * unidade, média simples nos outros casos.
     */
    public static function progresso(Collection $tarefas): array
    {
        $exec = self::execucoes($tarefas);
        $filhos = $tarefas->groupBy(fn ($t) => (int) $t->tarefa_pai_id);
        $raiz = $tarefas->whereNull('tarefa_pai_id');
        $conjunto = function (Collection $c) use ($exec, $filhos) {
            if ($c->isEmpty()) {
                return 0;
            }
            $s = RegrasMetasProjetos::somaRamo($filhos, $c);
            if ($s['houve'] && $s['unidade'] !== '' && $s['meta'] > 0 && $c->every(fn ($t) => RegrasMetasProjetos::somaRamo($filhos, [$t])['houve'])) {
                return (int) round($s['feito'] / $s['meta'] * 100);
            }

            return (int) round($c->sum(fn ($t) => $exec[$t->id]) / $c->count());
        };
        $porMarco = [];
        foreach ($raiz->groupBy(fn ($t) => (int) $t->marco_projeto_id) as $marco => $ts) {
            $porMarco[$marco] = $conjunto($ts);
        }

        return ['global' => $conjunto($raiz), 'por_tarefa' => $exec, 'por_marco' => $porMarco];
    }

    /**
     * WBS: milestones com as tarefas em árvore, execução e horas (renderProjectPlaneamento, vista «Lista / WBS»).
     * Versão Actual: meta (feito / meta, das subtarefas quando as há), precedência, estrutura orgânica, horas previstas
     * pelo calendário e aviso da meta da fase («faltam repartir X», ui_projects.js:690-706). Os irmãos vêm por data e
     * precedência (`$ordenacao = 'auto'`, ProjHierarquia.ordenar) ou pela ordem manual (`'manual'`).
     */
    public function wbs(Projeto $p, string $ordenacao = 'auto'): array
    {
        $tarefas = $this->tarefas($p);
        $porId = $tarefas->keyBy('id');
        $marcos = MarcoProjeto::query()->where('projeto_id', $p->id)->orderByRaw('COALESCE(data_inicio, data)')->orderBy('data')->orderBy('id')->get();
        $prog = self::progresso($tarefas);
        $cal = RegrasMetasProjetos::calendario($p->calendario);
        $horas = FolhaHorasProjeto::query()->where('projeto_id', $p->id)->groupBy('tarefa_projeto_id')->selectRaw('tarefa_projeto_id, SUM(horas) AS h')->pluck('h', 'tarefa_projeto_id');
        $posicoes = [];
        $posicoesIds = [];
        foreach (NoOrganigramaProjeto::query()->where('projeto_id', $p->id)->orderBy('id')->get() as $n) {
            foreach ($n->tarefas ?? [] as $tid) {
                $posicoes[(int) $tid][] = $n->titulo.($n->area ? " ({$n->area})" : '');
                $posicoesIds[(int) $tid][] = $n->id;
            }
        }
        $filhos = $tarefas->groupBy(fn ($t) => (int) $t->tarefa_pai_id);
        $porPred = $tarefas->filter(fn ($t) => $t->tarefa_predecessora_id)->groupBy(fn ($t) => (int) $t->tarefa_predecessora_id);
        $ordenar = fn (Collection $c) => $ordenacao === 'manual' ? $c->values() : ServicoHierarquiaProjetos::ordenarIrmaos($c);
        $no = function (TarefaProjeto $t, int $nivel = 0) use (&$no, $filhos, $prog, $horas, $posicoes, $posicoesIds, $porId, $porPred, $cal, $ordenar) {
            $meta = RegrasMetasProjetos::metaDoRamo($filhos, $t);
            $temFilhoComMeta = ($filhos[$t->id] ?? collect())->contains(fn ($f) => $f->temMeta());

            return $t->only(['id', 'codigo', 'nome', 'tarefa_pai_id', 'marco_projeto_id', 'atribuido_a_id', 'valor_contrato', 'ordem', 'estado_original',
                'tarefa_predecessora_id', 'desfasamento_dias', 'meta_unidade', 'meta_quantidade', 'meta_realizado'])
                + ['estado' => $t->estadoEfetivo(), 'data_inicio' => $t->data_inicio?->toDateString(), 'data_fim' => $t->data_fim?->toDateString(),
                    'tipo_dependencia' => $t->tarefa_predecessora_id ? ($t->tipo_dependencia ?: 'FS') : $t->tipo_dependencia,
                    'concluida_em' => $t->concluida_em?->toDateString(),
                    'percentagem_execucao' => (int) round((float) $t->percentagem_execucao), 'execucao' => $prog['por_tarefa'][$t->id], 'nivel' => $nivel,
                    'horas' => (float) ($horas[$t->id] ?? 0), 'horas_previstas' => RegrasMetasProjetos::horasPrevistas($t->data_inicio?->toDateString(), $t->data_fim?->toDateString(), $cal),
                    'posicoes' => $posicoes[$t->id] ?? [], 'posicoes_ids' => $posicoesIds[$t->id] ?? [],
                    'meta' => $meta, 'mede_se' => $t->temMeta() && ! $temFilhoComMeta, 'n_medicoes' => count($t->medicoes()),
                    'precedencia' => ServicoHierarquiaProjetos::simbolos($t, $porId, $porPred),
                    'subtarefas' => $ordenar($filhos[$t->id] ?? collect())->map(fn ($f) => $no($f, $nivel + 1))->values()->all()];
        };
        $raiz = $tarefas->whereNull('tarefa_pai_id');
        $idsMarcos = $marcos->pluck('id')->all();
        $grupos = $marcos->map(function ($m) use ($raiz, $filhos, $prog, $no, $ordenar) {
            $ts = $raiz->where('marco_projeto_id', $m->id);

            return ['marco' => $m->only(['id', 'nome', 'estado', 'meta_unidade', 'meta_quantidade']) + ['data' => $m->data?->toDateString(), 'data_inicio' => $m->data_inicio?->toDateString()],
                'execucao' => $prog['por_marco'][$m->id] ?? 0, 'meta' => self::metaDaFase($m, $filhos, $ts),
                'tarefas' => $ordenar($ts)->map(fn ($t) => $no($t))->values()->all()];
        })->all();
        $grupos[] = ['marco' => null, 'execucao' => $prog['por_marco'][0] ?? 0, 'meta' => null,
            'tarefas' => $ordenar($raiz->filter(fn ($t) => ! $t->marco_projeto_id || ! in_array($t->marco_projeto_id, $idsMarcos, true)))->map(fn ($t) => $no($t))->values()->all()];

        return ['execucao_global' => $prog['global'], 'calendario' => $cal + ['modelo' => RegrasMetasProjetos::modeloDe($cal)], 'grupos' => $grupos];
    }

    /**
     * Meta da fase face às tarefas (metaDaFase, ui_projects.js:690-706): realizado que sobe das tarefas e o aviso
     * «faltam repartir X» / «tarefas X acima da meta».
     *
     * @return array{meta: ?float, unidade: ?string, repartido: float, feito: float, unidade_tarefas: string, aviso: ?string}|null
     */
    private static function metaDaFase(MarcoProjeto $m, Collection $filhos, Collection $tarefas): ?array
    {
        $s = RegrasMetasProjetos::somaRamo($filhos, $tarefas);
        $meta = (float) $m->meta_quantidade > 0 ? (float) $m->meta_quantidade : null;
        if (! $meta && ! $s['houve']) {
            return null;
        }
        $aviso = null;
        if ($meta) {
            $dif = RegrasMetasProjetos::r2($meta - $s['meta']);
            $un = trim((string) $m->meta_unidade) ?: 'un';
            $fmt = fn ($v) => rtrim(rtrim(number_format($v, 2, ',', ' '), '0'), ',');
            if ($s['houve'] && $s['unidade'] !== '' && RegrasMetasProjetos::chaveUnidade($s['unidade']) !== RegrasMetasProjetos::chaveUnidade($un)) {
                $aviso = "As tarefas medem-se em {$s['unidade']} e a fase em {$un}.";
            } elseif ($dif > 0.004) {
                $aviso = "Faltam repartir {$fmt($dif)} {$un} pelas tarefas.";
            } elseif ($dif < -0.004) {
                $aviso = "As tarefas somam {$fmt(-$dif)} {$un} acima da meta da fase.";
            }
        }

        return ['meta' => $meta, 'unidade' => $m->meta_unidade, 'repartido' => $s['meta'], 'feito' => $s['feito'], 'unidade_tarefas' => $s['unidade'], 'aviso' => $aviso];
    }

    /** @return Collection<int, TarefaProjeto> tarefas pela ordem da árvore (projOrdemTarefa: sem ordem ficam no fim, pela criação) */
    public function tarefas(Projeto $p): Collection
    {
        return TarefaProjeto::query()->where('projeto_id', $p->id)->get()
            ->sortBy(fn ($t) => $t->ordem !== null ? (int) $t->ordem : 1_000_000 + $t->id)->values();
    }

    // ───────────── Milestones ─────────────

    /**
     * Fase / milestone (saveMilestone, ui_projects.js:2481-2560): designação, data de início (Versão Actual), data alvo
     * e meta (unidade + quantidade). A data alvo não pode ser anterior à de início; com meta a unidade é obrigatória.
     * Ao apertar o intervalo, as tarefas que ficam fora vêm em `avisos` (não se mexe nas datas delas sem o dizer).
     *
     * @return array{marco: MarcoProjeto, avisos: list<string>}
     */
    public function guardarMarcoComAvisos(Projeto $p, array $d, ?MarcoProjeto $m = null): array
    {
        $nome = trim((string) ($d['nome'] ?? $m?->nome ?? ''));
        if ($nome === '') {
            throw new ErroNegocio('Indique a designação do milestone.', 'NOME_OBRIGATORIO', 422);
        }
        $data = array_key_exists('data', $d) ? $d['data'] : $m?->data?->toDateString();
        $inicio = array_key_exists('data_inicio', $d) ? $d['data_inicio'] : $m?->data_inicio?->toDateString();
        if ($inicio && $data && substr((string) $data, 0, 10) < substr((string) $inicio, 0, 10)) {
            throw new ErroNegocio('A data alvo da fase não pode ser anterior à data de início.', 'DATAS_INVALIDAS', 422);
        }
        $metaQ = array_key_exists('meta_quantidade', $d) ? (float) ($d['meta_quantidade'] ?? 0) : (float) ($m?->meta_quantidade ?? 0);
        $metaUn = array_key_exists('meta_unidade', $d) ? trim((string) ($d['meta_unidade'] ?? '')) : trim((string) ($m?->meta_unidade ?? ''));
        if ($metaQ < 0) {
            throw new ErroNegocio('A meta da fase não pode ser negativa.', 'META_INVALIDA', 422);
        }
        if ($metaQ > 0 && $metaUn === '') {
            throw new ErroNegocio('Indique a unidade de medida da meta (m², metros, unidades…).', 'META_SEM_UNIDADE', 422);
        }
        $dados = ['projeto_id' => $p->id, 'nome' => $nome, 'data' => $data ?: null, 'data_inicio' => $inicio ?: null, 'estado' => $m?->estado ?? 'PENDENTE',
            'meta_quantidade' => $metaQ > 0 ? number_format($metaQ, 3, '.', '') : null, 'meta_unidade' => $metaQ > 0 ? mb_substr($metaUn, 0, 20) : null];
        $m ? $m->update($dados) : $m = MarcoProjeto::create($dados);
        $fora = ! $inicio && ! $data ? collect() : TarefaProjeto::query()->where('projeto_id', $p->id)->whereNull('tarefa_pai_id')->where('marco_projeto_id', $m->id)
            ->where(fn ($q) => $q->when($inicio, fn ($x) => $x->orWhere('data_inicio', '<', $inicio))->when($data, fn ($x) => $x->orWhere('data_fim', '>', $data)))->pluck('nome');
        $avisos = $fora->isEmpty() ? [] : ["{$fora->count()} tarefa(s) ficam com datas fora da fase: ".$fora->take(5)->implode(', ').($fora->count() > 5 ? '…' : '').'. Reveja as datas.'];

        return ['marco' => $m->refresh(), 'avisos' => $avisos];
    }

    public function guardarMarco(Projeto $p, array $d, ?MarcoProjeto $m = null): MarcoProjeto
    {
        return $this->guardarMarcoComAvisos($p, $d, $m)['marco'];
    }

    /** Elimina o milestone; as tarefas passam a «sem milestone» (projEliminarMilestone). */
    public function eliminarMarco(MarcoProjeto $m, ?string $confirmacao): int
    {
        self::exigirConfirmacao($confirmacao);

        return DB::transaction(function () use ($m) {
            $n = TarefaProjeto::query()->where('marco_projeto_id', $m->id)->update(['marco_projeto_id' => null]);
            $m->delete();
            $this->projetos->registar($m->projeto_id, 'Eliminar milestone', "{$m->nome}".($n ? " ({$n} tarefa(s) passaram a sem milestone)" : ''));

            return $n;
        });
    }

    // ───────────── Tarefas ─────────────

    /** @param  array<string, mixed>  $d */
    public function guardarTarefa(Projeto $p, array $d, ?TarefaProjeto $t = null): TarefaProjeto
    {
        return $this->guardarTarefaComAvisos($p, $d, $t)['tarefa'];
    }

    /**
     * Grava a tarefa (saveTask, ui_projects.js:2380-2475) e devolve os avisos que o legado mostrava em confirmação:
     * subtarefas que ficam fora das novas datas e tarefas dependentes que deixam de cumprir a precedência.
     * `encaixar_datas` = true encaixa as datas no nível acima em vez de recusar (ProjHierarquia.encaixar).
     *
     * @param  array<string, mixed>  $d
     * @return array{tarefa: TarefaProjeto, avisos: list<string>}
     */
    public function guardarTarefaComAvisos(Projeto $p, array $d, ?TarefaProjeto $t = null): array
    {
        $nome = trim((string) ($d['nome'] ?? $t?->nome ?? ''));
        if ($nome === '') {
            throw new ErroNegocio('A designação é obrigatória.', 'NOME_OBRIGATORIO', 422);
        }

        return DB::transaction(function () use ($p, $d, $t, $nome) {
            $todas = TarefaProjeto::query()->where('projeto_id', $p->id)->lockForUpdate()->get()->keyBy('id');
            $marcos = MarcoProjeto::query()->where('projeto_id', $p->id)->get()->keyBy('id');
            $t = $t ? ($todas[$t->id] ?? $t) : null;
            $pai = array_key_exists('tarefa_pai_id', $d) ? $d['tarefa_pai_id'] : $t?->tarefa_pai_id;
            if ($pai) {
                $tp = $todas[(int) $pai] ?? throw new ErroNegocio('A tarefa-pai não pertence ao projecto.', 'TAREFA_PAI_INVALIDA', 422);
                if ($t && ($tp->id === $t->id || in_array($tp->id, $this->descendentes($t, $todas), true))) {
                    throw new ErroNegocio('Uma tarefa não pode ser subtarefa dela própria nem de uma subtarefa sua.', 'CICLO_TAREFAS', 422);
                }
            }
            $marco = array_key_exists('marco_projeto_id', $d) ? $d['marco_projeto_id'] : $t?->marco_projeto_id;
            if ($marco && ! isset($marcos[(int) $marco])) {
                throw new ErroNegocio('O milestone não pertence ao projecto.', 'MARCO_INVALIDO', 422);
            }
            // uma subtarefa vive na fase da tarefa-mãe (o agrupamento da WBS é pela fase da raiz)
            if ($pai && ! array_key_exists('marco_projeto_id', $d)) {
                $marco = $todas[(int) $pai]->marco_projeto_id;
            }
            $atribuido = array_key_exists('atribuido_a_id', $d) ? $d['atribuido_a_id'] : $t?->atribuido_a_id;
            if ($atribuido && ! $this->membroDoProjeto($p, (int) $atribuido)) {
                throw new ErroNegocio('O responsável tem de ser membro da equipa do projecto.', 'RESPONSAVEL_INVALIDO', 422);
            }
            $inicio = array_key_exists('data_inicio', $d) ? $d['data_inicio'] : $t?->data_inicio?->toDateString();
            $fim = array_key_exists('data_fim', $d) ? $d['data_fim'] : $t?->data_fim?->toDateString();
            if ($inicio && $fim && $fim < $inicio) {
                throw new ErroNegocio('A data de fim não pode ser anterior à de início.', 'DATAS_INVALIDAS', 422);
            }
            // datas dentro do nível acima (validarDatas, projectos_hierarquia.js:80-90)
            if ($erro = ServicoHierarquiaProjetos::validarDatas($inicio, $fim, $pai ? (int) $pai : null, $pai ? null : ($marco ? (int) $marco : null), $todas, $marcos)) {
                if (empty($d['encaixar_datas'])) {
                    throw new ErroNegocio($erro, 'DATAS_FORA_DO_NIVEL', 422);
                }
                ['inicio' => $inicio, 'fim' => $fim] = ServicoHierarquiaProjetos::encaixar($inicio, $fim, $pai ? (int) $pai : null, $pai ? null : ($marco ? (int) $marco : null), $todas, $marcos);
            }
            // dependência (validarDependencia / criaCiclo, projectos_hierarquia.js:131-172)
            $pred = array_key_exists('tarefa_predecessora_id', $d) ? $d['tarefa_predecessora_id'] : $t?->tarefa_predecessora_id;
            $tipoDep = strtoupper((string) (array_key_exists('tipo_dependencia', $d) ? ($d['tipo_dependencia'] ?? 'FS') : ($t?->tipo_dependencia ?? 'FS'))) ?: 'FS';
            $desf = (int) (array_key_exists('desfasamento_dias', $d) ? ($d['desfasamento_dias'] ?? 0) : ($t?->desfasamento_dias ?? 0));
            if (! in_array($tipoDep, TarefaProjeto::TIPOS_DEPENDENCIA, true)) {
                throw new ErroNegocio('Tipo de dependência inválido (FS, SS ou FF).', 'DEPENDENCIA_INVALIDA', 422);
            }
            $predT = null;
            if ($pred) {
                $predT = $todas[(int) $pred] ?? throw new ErroNegocio('A tarefa predecessora não pertence ao projecto.', 'DEPENDENCIA_INVALIDA', 422);
                if ($t && ($predT->id === $t->id || in_array($predT->id, $this->descendentes($t, $todas), true))) {
                    throw new ErroNegocio('Uma tarefa não pode depender dela própria nem de uma subtarefa sua.', 'DEPENDENCIA_CIRCULAR', 422);
                }
                if ($t && ServicoHierarquiaProjetos::criaCiclo($t->id, $predT->id, $todas)) {
                    throw new ErroNegocio('Essa dependência é circular: a tarefa escolhida já depende desta.', 'DEPENDENCIA_CIRCULAR', 422);
                }
                if ($erro = ServicoHierarquiaProjetos::validarDependencia($inicio, $fim, $predT, $tipoDep, $desf)) {
                    throw new ErroNegocio($erro, 'DEPENDENCIA_DATA', 422, ['data_minima' => ServicoHierarquiaProjetos::dataMinima($predT, $tipoDep, $desf)]);
                }
            }
            $estado = $d['estado'] ?? $t?->estadoEfetivo() ?? 'PENDENTE';
            if (! in_array($estado, TarefaProjeto::ESTADOS, true)) {
                throw new ErroNegocio('Estado da tarefa inválido.', 'ESTADO_INVALIDO', 422);
            }
            // meta por quantidade (saveTask, ui_projects.js:2397-2411)
            $metaQ = round((float) (array_key_exists('meta_quantidade', $d) ? ($d['meta_quantidade'] ?? 0) : ($t?->meta_quantidade ?? 0)), 3);
            $metaUn = trim((string) (array_key_exists('meta_unidade', $d) ? ($d['meta_unidade'] ?? '') : ($t?->meta_unidade ?? '')));
            $filhosComMeta = $t ? $todas->filter(fn ($x) => (int) $x->tarefa_pai_id === $t->id && $x->temMeta()) : collect();
            $metaF = $filhosComMeta->isNotEmpty() ? (float) ($t?->meta_realizado ?? 0)   // com subtarefas com meta, o realizado sobe delas
                : round((float) (array_key_exists('meta_realizado', $d) ? ($d['meta_realizado'] ?? 0) : ($t?->meta_realizado ?? 0)), 3);
            if ($metaQ < 0 || $metaF < 0) {
                throw new ErroNegocio('As quantidades da meta não podem ser negativas.', 'META_INVALIDA', 422);
            }
            if ($metaQ > 0 && $metaUn === '') {
                throw new ErroNegocio('Indique a unidade de medida da meta (m², metros, unidades…).', 'META_SEM_UNIDADE', 422);
            }
            $pct = array_key_exists('percentagem_execucao', $d) ? (int) $d['percentagem_execucao'] : (int) round((float) ($t?->percentagem_execucao ?? 0));
            if ($metaQ > 0) {
                $pct = max(0, min(100, (int) round($metaF / $metaQ * 100)));
                if ($pct >= 100 && ! isset($d['estado'])) {
                    $estado = 'CONCLUIDA';
                }
            }
            $pct = $estado === 'CONCLUIDA' ? 100 : max(0, min(100, $pct));
            $valor = array_key_exists('valor_contrato', $d) ? $d['valor_contrato'] : $t?->valor_contrato;
            if ($valor !== null && (float) $valor < 0) {
                throw new ErroNegocio('O valor adjudicado não pode ser negativo.', 'VALOR_INVALIDO', 422);
            }
            $dados = ['projeto_id' => $p->id, 'tarefa_pai_id' => $pai ?: null, 'codigo' => array_key_exists('codigo', $d) ? ($d['codigo'] ?: null) : $t?->codigo,
                'nome' => $nome, 'data_inicio' => $inicio ?: null, 'data_fim' => $fim ?: null, 'percentagem_execucao' => $pct,
                'marco_projeto_id' => $marco ?: null, 'atribuido_a_id' => $atribuido ?: null,
                'valor_contrato' => $valor === null || $valor === '' ? null : number_format((float) $valor, 2, '.', ''),
                'tarefa_predecessora_id' => $predT?->id, 'tipo_dependencia' => $predT ? $tipoDep : null, 'desfasamento_dias' => $predT ? $desf : null,
                'meta_unidade' => $metaQ > 0 ? mb_substr($metaUn, 0, 20) : null, 'meta_quantidade' => $metaQ > 0 ? $metaQ : null, 'meta_realizado' => $metaQ > 0 ? $metaF : null,
                'concluida_em' => self::concluidaEm($estado, $t)] + $this->colunasEstado($estado, $t);
            $acerto = $t && $t->temMeta() && $metaQ > 0 && abs((float) $t->meta_realizado - $metaF) > 0.0005 && $filhosComMeta->isEmpty();
            if ($t) {
                $t->update($dados);
            } else {
                $t = TarefaProjeto::create($dados + ['meta_execucoes' => $metaQ > 0 ? [] : null]);
            }
            if ($acerto) {
                $this->projetos->registar($p->id, 'Acerto do realizado', trim("{$t->codigo} {$t->nome}").": realizado passa a {$metaF} {$metaUn} (sem medição)");
            }
            if (array_key_exists('no_organigrama_projeto_id', $d)) {
                $this->definirPosicao($p, $t->id, $d['no_organigrama_projeto_id'] ? (int) $d['no_organigrama_projeto_id'] : null);
            }
            $t->refresh();
            $todas[$t->id] = $t;
            $avisos = [];
            if ($fora = ServicoHierarquiaProjetos::conflitosAbaixo($t, $todas)) {
                $avisos[] = count($fora).' subtarefa(s) ficam com datas fora desta tarefa: '.implode(', ', array_slice(array_column($fora, 'nome'), 0, 5)).(count($fora) > 5 ? '…' : '').'.';
            }
            $dependentes = $todas->filter(fn ($x) => (int) $x->tarefa_predecessora_id === $t->id
                && ServicoHierarquiaProjetos::validarDependencia($x->data_inicio?->toDateString(), $x->data_fim?->toDateString(), $t, $x->tipo_dependencia ?: 'FS', (int) $x->desfasamento_dias));
            if ($dependentes->isNotEmpty()) {
                $avisos[] = "{$dependentes->count()} tarefa(s) que dependem desta deixam de cumprir a precedência: ".$dependentes->take(5)->pluck('nome')->implode(', ').'.';
            }

            return ['tarefa' => $t, 'avisos' => $avisos];
        });
    }

    /** Liga a tarefa a uma posição do organigrama (ou a nenhuma); a ligação vive no nó (saveTask, ui_projects.js:2460-2472). */
    private function definirPosicao(Projeto $p, int $tarefaId, ?int $noId): void
    {
        $nos = NoOrganigramaProjeto::query()->where('projeto_id', $p->id)->lockForUpdate()->get();
        if ($noId && ! $nos->contains('id', $noId)) {
            throw new ErroNegocio('A posição do organigrama não pertence ao projecto.', 'POSICAO_INVALIDA', 422);
        }
        foreach ($nos as $n) {
            $lista = array_map('intval', $n->tarefas ?? []);
            $tem = in_array($tarefaId, $lista, true);
            if ($n->id === $noId && ! $tem) {
                $n->update(['tarefas' => [...$lista, $tarefaId]]);
            } elseif ($n->id !== $noId && $tem) {
                $n->update(['tarefas' => array_values(array_filter($lista, fn ($x) => $x !== $tarefaId))]);
            }
        }
    }

    /**
     * Data de conclusão (ui_projects.js:2396, 2434): só se põe uma vez — reeditar uma tarefa concluída mantém a data; sai
     * quando a tarefa deixa de estar CONCLUÍDA. Grava-se ao meio-dia para a data não mudar com o fuso.
     */
    public static function concluidaEm(string $estado, ?TarefaProjeto $t, ?string $data = null): ?string
    {
        if ($estado !== 'CONCLUIDA') {
            return null;
        }
        if ($t?->concluida_em && $t->estadoEfetivo() === 'CONCLUIDA') {
            return $t->concluida_em->toDateTimeString();
        }

        return substr($data ?: now()->toDateString(), 0, 10).' 12:00:00';
    }

    /** Actualiza só a execução (updateTaskExecution, ui_projects.js:2047). Com meta, a % vem das medições. */
    public function atualizarExecucao(TarefaProjeto $t, int $pct): TarefaProjeto
    {
        if ($t->temMeta()) {
            throw new ErroNegocio('Esta tarefa tem meta por quantidade: registe a quantidade executada (a % é calculada).', 'TAREFA_COM_META', 422);
        }
        $t->update(['percentagem_execucao' => max(0, min(100, $pct))]);

        return $t->refresh();
    }

    /**
     * Move a tarefa (projMoverTarefa, ui_projects.js:1927-1965).
     *
     * @param  array{tipo: string, alvo_id?: ?int, marco_projeto_id?: ?int}  $destino  tipo: ANTES | DEPOIS | DENTRO | MARCO
     */
    public function mover(TarefaProjeto $t, array $destino, bool $encaixar = true): TarefaProjeto
    {
        return $this->moverComDatas($t, $destino, $encaixar)['tarefa'];
    }

    /**
     * Mover com as datas verificadas contra o novo nível acima (Versão Actual, projMoverTarefa com encaixarDatas,
     * projectos_hierarquia.js:63-125): se não couberem, encaixa-as (`$encaixar`) ou recusa; as subtarefas que ficam
     * fora vêm em `avisos`.
     *
     * @return array{tarefa: TarefaProjeto, avisos: list<string>}
     */
    public function moverComDatas(TarefaProjeto $t, array $destino, bool $encaixar = true): array
    {
        return DB::transaction(function () use ($t, $destino, $encaixar) {
            $todas = TarefaProjeto::query()->where('projeto_id', $t->projeto_id)->lockForUpdate()->get()->keyBy('id');
            $t = $todas[$t->id];
            $desc = $this->descendentes($t, $todas);
            $tipo = strtoupper((string) $destino['tipo']);
            $alvo = null;
            if ($tipo === 'MARCO') {
                $novoPai = null;
                $novoMarco = $destino['marco_projeto_id'] ?? null;
                if ($novoMarco && ! MarcoProjeto::query()->where('projeto_id', $t->projeto_id)->whereKey($novoMarco)->exists()) {
                    throw new ErroNegocio('O milestone não pertence ao projecto.', 'MARCO_INVALIDO', 422);
                }
            } elseif (in_array($tipo, ['ANTES', 'DEPOIS', 'DENTRO'], true)) {
                $alvo = $todas[$destino['alvo_id'] ?? 0] ?? throw new ErroNegocio('Tarefa de destino não encontrada.', 'TAREFA_DESTINO_INEXISTENTE', 422);
                if ($alvo->id === $t->id || in_array($alvo->id, $desc, true)) {
                    throw new ErroNegocio('Não é possível mover uma tarefa para dentro dela própria ou de uma subtarefa sua.', 'CICLO_TAREFAS', 422);
                }
                $novoPai = $tipo === 'DENTRO' ? $alvo->id : $alvo->tarefa_pai_id;
                $novoMarco = $alvo->marco_projeto_id;
            } else {
                throw new ErroNegocio('Destino inválido (ANTES, DEPOIS, DENTRO ou MARCO).', 'DESTINO_INVALIDO', 422);
            }
            $ordem = fn ($x) => $x->ordem !== null ? (int) $x->ordem : 1_000_000 + $x->id;
            $irmaos = $todas->filter(fn ($x) => $x->id !== $t->id && ($novoPai ? (int) $x->tarefa_pai_id === (int) $novoPai
                : (! $x->tarefa_pai_id && (int) $x->marco_projeto_id === (int) $novoMarco)))->sortBy($ordem)->values();
            $pos = $irmaos->count();
            if ($alvo && $tipo !== 'DENTRO') {
                $i = $irmaos->search(fn ($x) => $x->id === $alvo->id);
                $pos = $tipo === 'ANTES' ? $i : $i + 1;
            }
            $lista = $irmaos->all();
            array_splice($lista, $pos, 0, [$t]);
            $mudou = (int) $t->tarefa_pai_id !== (int) $novoPai || (int) $t->marco_projeto_id !== (int) $novoMarco;
            $datas = [];
            $avisos = [];
            if ($mudou) {
                $marcos = MarcoProjeto::query()->where('projeto_id', $t->projeto_id)->get()->keyBy('id');
                $ini = $t->data_inicio?->toDateString();
                $fim = $t->data_fim?->toDateString();
                $paiV = $novoPai ? (int) $novoPai : null;
                $marcoV = $novoPai ? null : ($novoMarco ? (int) $novoMarco : null);
                if ($erro = ServicoHierarquiaProjetos::validarDatas($ini, $fim, $paiV, $marcoV, $todas, $marcos)) {
                    if (! $encaixar) {
                        throw new ErroNegocio($erro, 'DATAS_FORA_DO_NIVEL', 422);
                    }
                    $novas = ServicoHierarquiaProjetos::encaixar($ini, $fim, $paiV, $marcoV, $todas, $marcos);
                    $datas = ['data_inicio' => $novas['inicio'], 'data_fim' => $novas['fim']];
                    $avisos[] = 'As datas foram encaixadas no novo nível acima: '.($novas['inicio'] ?? '—').' a '.($novas['fim'] ?? '—').'.';
                    if ($fora = ServicoHierarquiaProjetos::conflitosAbaixo($t, $todas, ['inicio' => $novas['inicio'], 'fim' => $novas['fim']])) {
                        $avisos[] = count($fora).' subtarefa(s) ficaram com datas fora do novo intervalo: '.implode(', ', array_slice(array_column($fora, 'nome'), 0, 3)).(count($fora) > 3 ? '…' : '').'. Reveja as datas.';
                    }
                }
            }
            $t->update(['tarefa_pai_id' => $novoPai ?: null, 'marco_projeto_id' => $novoMarco ?: null] + $datas);
            if ($desc) {
                TarefaProjeto::query()->whereIn('id', $desc)->update(['marco_projeto_id' => $novoMarco ?: null]);
            }
            foreach (array_values($lista) as $i => $x) {
                if ((int) $x->ordem !== ($i + 1) * 10) {
                    TarefaProjeto::query()->whereKey($x->id)->update(['ordem' => ($i + 1) * 10]);
                }
            }
            if ($mudou) {
                $this->projetos->registar($t->projeto_id, 'Mover tarefa', trim("{$t->codigo} {$t->nome}").' → '.($novoPai ? 'subtarefa de '.$todas[$novoPai]->nome : 'tarefa principal'));
            }

            return ['tarefa' => $t->refresh(), 'avisos' => $avisos];
        });
    }

    /**
     * Registos que impedem a eliminação (projRegistosDaTarefa, ui_projects.js:1968-1984).
     *
     * @param  list<int>  $ids
     * @return array<string, int>
     */
    public function registosDaTarefa(array $ids): array
    {
        return array_filter([
            'folhas_horas' => FolhaHorasProjeto::query()->whereIn('tarefa_projeto_id', $ids)->count(),
            'movimentos_analiticos' => RazaoAnaliticoProjeto::query()->whereIn('tarefa_projeto_id', $ids)->count(),
            'linhas_autos' => LinhaRevisaoProjeto::query()->whereIn('tarefa_projeto_id', $ids)->count(),
            'linhas_orcamento' => LinhaOrcamentoProjeto::query()->whereIn('tarefa_projeto_id', $ids)->count(),
            'linhas_requisicao' => LinhaRequisicaoProjeto::query()->whereIn('tarefa_projeto_id', $ids)->count(),
            'linhas_compras' => ItemCompra::query()->whereIn('tarefa_projeto_id', $ids)->count(),
        ]);
    }

    /** Elimina a tarefa e as subtarefas (projEliminarTarefa). */
    public function eliminarTarefa(TarefaProjeto $t, ?string $confirmacao): array
    {
        self::exigirConfirmacao($confirmacao);

        return DB::transaction(function () use ($t) {
            $ids = [$t->id, ...$this->descendentes($t)];
            if ($reg = $this->registosDaTarefa($ids)) {
                throw new ErroNegocio("Não é possível eliminar «{$t->nome}»".(count($ids) > 1 ? ' nem as suas subtarefas' : '').': existem registos com valor analítico/financeiro. '
                    .'Mova-os para outra tarefa ou elimine-os primeiro; em alternativa, marque a tarefa como concluída.', 'TAREFA_COM_REGISTOS', 422, ['registos' => $reg]);
            }
            foreach (NoOrganigramaProjeto::query()->where('projeto_id', $t->projeto_id)->lockForUpdate()->get() as $n) {
                $restantes = array_values(array_filter($n->tarefas ?? [], fn ($x) => ! in_array((int) $x, $ids, true)));
                if (count($restantes) !== count($n->tarefas ?? [])) {
                    $n->update(['tarefas' => $restantes]);
                }
            }
            // as tarefas que dependiam destas ficam sem predecessora (a FK é RESTRICT; o legado deixava um id órfão)
            $soltas = TarefaProjeto::query()->whereIn('tarefa_predecessora_id', $ids)->whereNotIn('id', $ids)->count();
            TarefaProjeto::query()->whereIn('tarefa_predecessora_id', $ids)->update(['tarefa_predecessora_id' => null, 'tipo_dependencia' => null, 'desfasamento_dias' => null]);
            $porId = TarefaProjeto::query()->whereIn('id', $ids)->get()->keyBy('id');
            foreach (array_reverse($ids) as $id) {
                $porId[$id]->delete();
            }
            $this->projetos->registar($t->projeto_id, $t->tarefa_pai_id ? 'Eliminar subtarefa' : 'Eliminar tarefa', trim("{$t->codigo} {$t->nome}").(count($ids) > 1 ? ' + '.(count($ids) - 1).' subtarefa(s)' : '')
                .($soltas ? " · {$soltas} tarefa(s) ficaram sem predecessora" : ''));

            return $ids;
        });
    }

    // ───────────── Kanban ─────────────

    /** @return list<array{id: string, titulo: string, cor: string, estado: ?string}> */
    public function colunasKanban(Projeto $p): array
    {
        $cfg = ConfiguracaoProjeto::query()->where('projeto_id', $p->id)->where('chave', 'kanban_cols')->first();
        $cols = $cfg ? (json_decode((string) $cfg->valor, true) ?: []) : [];

        return $cols ?: self::KANBAN_PADRAO;
    }

    /** Colunas do Kanban (saveKanbanColumns): as 4 base mantêm o id; as personalizadas indicam o estado-base. */
    public function guardarColunasKanban(Projeto $p, array $colunas): array
    {
        $saida = [];
        $ids = [];
        foreach (array_values($colunas) as $n => $c) {
            $id = strtoupper(preg_replace('/\s+/', '_', trim((string) ($c['id'] ?? ''))));
            if ($id === '' || ! preg_match('/^[A-Z0-9_]{1,30}$/', $id)) {
                throw new ErroNegocio('Coluna '.($n + 1).': identificador inválido (letras, algarismos e «_», até 30).', 'COLUNA_INVALIDA', 422);
            }
            if (isset($ids[$id])) {
                throw new ErroNegocio("Coluna {$id} repetida.", 'COLUNA_REPETIDA', 422);
            }
            $ids[$id] = true;
            $estado = in_array($id, TarefaProjeto::ESTADOS, true) ? $id : ($c['estado'] ?? null);
            if ($estado !== null && ! in_array($estado, TarefaProjeto::ESTADOS, true)) {
                throw new ErroNegocio("Coluna {$id}: estado-base inválido.", 'COLUNA_INVALIDA', 422);
            }
            $cor = (string) ($c['cor'] ?? '#94a3b8');
            if (! preg_match('/^#[0-9a-fA-F]{6}$/', $cor)) {
                throw new ErroNegocio("Coluna {$id}: cor inválida (#RRGGBB).", 'COLUNA_INVALIDA', 422);
            }
            $saida[] = ['id' => $id, 'titulo' => trim((string) ($c['titulo'] ?? $id)) ?: $id, 'cor' => $cor, 'estado' => $estado];
        }
        if (! $saida) {
            throw new ErroNegocio('O Kanban tem de ter pelo menos uma coluna.', 'SEM_COLUNAS', 422);
        }
        $this->projetos->definirConfiguracao($p->id, 'kanban_cols', $saida);

        return $saida;
    }

    /** Quadro Kanban: tarefas por coluna; as que não cabem em nenhuma ficam «Por mapear». */
    public function kanban(Projeto $p): array
    {
        $cols = $this->colunasKanban($p);
        $tarefas = $this->tarefas($p);
        $exec = self::execucoes($tarefas);
        $idsCols = array_column($cols, 'id');
        $coluna = fn (TarefaProjeto $t) => in_array($t->estado_original, $idsCols, true) ? $t->estado_original
            : (in_array($t->estadoEfetivo(), $idsCols, true) ? $t->estadoEfetivo() : null);
        $cartao = fn (TarefaProjeto $t) => $t->only(['id', 'codigo', 'nome', 'marco_projeto_id', 'atribuido_a_id', 'valor_contrato'])
            + ['estado' => $t->estadoEfetivo(), 'execucao' => $exec[$t->id], 'data_inicio' => $t->data_inicio?->toDateString(), 'data_fim' => $t->data_fim?->toDateString()];
        $saida = array_map(fn ($c) => $c + ['tarefas' => $tarefas->filter(fn ($t) => $coluna($t) === $c['id'])->map($cartao)->values()->all()], $cols);
        $soltas = $tarefas->filter(fn ($t) => $coluna($t) === null);

        return ['colunas' => $saida, 'por_mapear' => $soltas->map($cartao)->values()->all()];
    }

    /** Arrastar um cartão para uma coluna (Sortable onEnd, ui_projects.js:1105-1118): CONCLUIDA força 100 %. */
    public function moverKanban(Projeto $p, TarefaProjeto $t, string $colunaId): TarefaProjeto
    {
        $col = collect($this->colunasKanban($p))->firstWhere('id', $colunaId) ?? throw new ErroNegocio("Coluna {$colunaId} inexistente.", 'COLUNA_INEXISTENTE', 422);
        $estado = $col['estado'] ?? $t->estadoEfetivo();
        $dados = $this->colunasEstado($estado, $t);
        $dados['estado_original'] = $col['id'];
        $dados['concluida_em'] = self::concluidaEm($estado, $t);
        if ($estado === 'CONCLUIDA') {
            $dados['percentagem_execucao'] = 100;
        }
        $t->update($dados);

        return $t->refresh();
    }

    // ───────────── Auxiliares ─────────────

    /** @return list<int> */
    public function descendentes(TarefaProjeto $t, ?Collection $todas = null): array
    {
        $todas ??= TarefaProjeto::query()->where('projeto_id', $t->projeto_id)->get(['id', 'tarefa_pai_id']);
        $filhos = $todas->groupBy(fn ($x) => (int) $x->tarefa_pai_id);
        $saida = [];
        $pilha = [$t->id];
        while ($pilha) {
            foreach ($filhos[array_pop($pilha)] ?? [] as $f) {
                if (! in_array($f->id, $saida, true) && $f->id !== $t->id) {
                    $saida[] = $f->id;
                    $pilha[] = $f->id;
                }
            }
        }

        return $saida;
    }

    public function membroDoProjeto(Projeto $p, int $membroId): ?MembroEquipaProjeto
    {
        return MembroEquipaProjeto::query()->whereKey($membroId)->whereIn('equipa_projeto_id', EquipaProjeto::query()->where('projeto_id', $p->id)->select('id'))->first();
    }

    /** Colunas estado / estado_original para um estado, respeitando a restrição CHECK actual. */
    private function colunasEstado(string $estado, ?TarefaProjeto $t): array
    {
        $original = $t?->estado_original;
        if ($original === null || in_array($original, TarefaProjeto::ESTADOS, true) || ($t && $t->estadoEfetivo() !== $estado)) {
            $original = $estado;
        }
        if (! ServicoProjetos::codigoAceite('tarefas_projeto', 'estado', $estado)) {
            return ['estado' => null, 'estado_original' => $estado];
        }

        return ['estado' => $estado, 'estado_original' => $original];
    }

    public static function exigirConfirmacao(?string $confirmacao): void
    {
        if (mb_strtoupper(trim((string) $confirmacao)) !== 'ELIMINAR') {
            throw new ErroNegocio('Para confirmar, envie confirmacao = «ELIMINAR». Nada foi eliminado.', 'CONFIRMACAO_EM_FALTA', 422);
        }
    }
}
