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
     * Execução por tarefa (calcProgress, ui_projects.js:716-731): folha = percentagem própria; com subtarefas = média
     * arredondada das subtarefas.
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
                return $memo[$t->id] = (int) round((float) $t->percentagem_execucao);
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

    /** Execução global (média das tarefas principais) e por milestone (calcMilestoneProgress). */
    public static function progresso(Collection $tarefas): array
    {
        $exec = self::execucoes($tarefas);
        $raiz = $tarefas->whereNull('tarefa_pai_id');
        $media = fn (Collection $c) => $c->isEmpty() ? 0 : (int) round($c->sum(fn ($t) => $exec[$t->id]) / $c->count());
        $porMarco = [];
        foreach ($raiz->groupBy(fn ($t) => (int) $t->marco_projeto_id) as $marco => $ts) {
            $porMarco[$marco] = $media($ts);
        }

        return ['global' => $media($raiz), 'por_tarefa' => $exec, 'por_marco' => $porMarco];
    }

    /** WBS: milestones com as tarefas em árvore, execução e horas (renderProjectPlaneamento, vista «Lista / WBS»). */
    public function wbs(Projeto $p): array
    {
        $tarefas = $this->tarefas($p);
        $marcos = MarcoProjeto::query()->where('projeto_id', $p->id)->orderBy('data')->orderBy('id')->get();
        $prog = self::progresso($tarefas);
        $horas = FolhaHorasProjeto::query()->where('projeto_id', $p->id)->groupBy('tarefa_projeto_id')->selectRaw('tarefa_projeto_id, SUM(horas) AS h')->pluck('h', 'tarefa_projeto_id');
        $posicoes = [];
        foreach (NoOrganigramaProjeto::query()->where('projeto_id', $p->id)->get() as $n) {
            foreach ($n->tarefas ?? [] as $tid) {
                $posicoes[(int) $tid][] = $n->titulo.($n->area ? " ({$n->area})" : '');
            }
        }
        $filhos = $tarefas->groupBy(fn ($t) => (int) $t->tarefa_pai_id);
        $no = function (TarefaProjeto $t, int $nivel = 0) use (&$no, $filhos, $prog, $horas, $posicoes) {
            return $t->only(['id', 'codigo', 'nome', 'tarefa_pai_id', 'marco_projeto_id', 'atribuido_a_id', 'valor_contrato', 'ordem', 'estado_original'])
                + ['estado' => $t->estadoEfetivo(), 'data_inicio' => $t->data_inicio?->toDateString(), 'data_fim' => $t->data_fim?->toDateString(),
                    'percentagem_execucao' => (int) round((float) $t->percentagem_execucao), 'execucao' => $prog['por_tarefa'][$t->id], 'nivel' => $nivel,
                    'horas' => (float) ($horas[$t->id] ?? 0), 'posicoes' => $posicoes[$t->id] ?? [],
                    'subtarefas' => ($filhos[$t->id] ?? collect())->map(fn ($f) => $no($f, $nivel + 1))->values()->all()];
        };
        $raiz = $tarefas->whereNull('tarefa_pai_id');
        $idsMarcos = $marcos->pluck('id')->all();
        $grupos = $marcos->map(fn ($m) => ['marco' => $m->only(['id', 'nome', 'estado']) + ['data' => $m->data?->toDateString()],
            'execucao' => $prog['por_marco'][$m->id] ?? 0, 'tarefas' => $raiz->where('marco_projeto_id', $m->id)->map(fn ($t) => $no($t))->values()->all()])->all();
        $grupos[] = ['marco' => null, 'execucao' => $prog['por_marco'][0] ?? 0,
            'tarefas' => $raiz->filter(fn ($t) => ! $t->marco_projeto_id || ! in_array($t->marco_projeto_id, $idsMarcos, true))->map(fn ($t) => $no($t))->values()->all()];

        return ['execucao_global' => $prog['global'], 'grupos' => $grupos];
    }

    /** @return Collection<int, TarefaProjeto> tarefas pela ordem da árvore (projOrdemTarefa: sem ordem ficam no fim, pela criação) */
    public function tarefas(Projeto $p): Collection
    {
        return TarefaProjeto::query()->where('projeto_id', $p->id)->get()
            ->sortBy(fn ($t) => $t->ordem !== null ? (int) $t->ordem : 1_000_000 + $t->id)->values();
    }

    // ───────────── Milestones ─────────────

    public function guardarMarco(Projeto $p, array $d, ?MarcoProjeto $m = null): MarcoProjeto
    {
        $nome = trim((string) ($d['nome'] ?? $m?->nome ?? ''));
        if ($nome === '') {
            throw new ErroNegocio('Indique a designação do milestone.', 'NOME_OBRIGATORIO', 422);
        }
        $dados = ['projeto_id' => $p->id, 'nome' => $nome, 'data' => array_key_exists('data', $d) ? $d['data'] : $m?->data?->toDateString(), 'estado' => $m?->estado ?? 'PENDENTE'];
        if ($m) {
            $m->update($dados);

            return $m->refresh();
        }

        return MarcoProjeto::create($dados);
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
        $nome = trim((string) ($d['nome'] ?? $t?->nome ?? ''));
        if ($nome === '') {
            throw new ErroNegocio('A designação é obrigatória.', 'NOME_OBRIGATORIO', 422);
        }
        $pai = array_key_exists('tarefa_pai_id', $d) ? $d['tarefa_pai_id'] : $t?->tarefa_pai_id;
        if ($pai) {
            $tp = TarefaProjeto::query()->where('projeto_id', $p->id)->find($pai) ?? throw new ErroNegocio('A tarefa-pai não pertence ao projecto.', 'TAREFA_PAI_INVALIDA', 422);
            if ($t && ($tp->id === $t->id || in_array($tp->id, $this->descendentes($t), true))) {
                throw new ErroNegocio('Uma tarefa não pode ser subtarefa dela própria nem de uma subtarefa sua.', 'CICLO_TAREFAS', 422);
            }
        }
        $marco = array_key_exists('marco_projeto_id', $d) ? $d['marco_projeto_id'] : $t?->marco_projeto_id;
        if ($marco && ! MarcoProjeto::query()->where('projeto_id', $p->id)->whereKey($marco)->exists()) {
            throw new ErroNegocio('O milestone não pertence ao projecto.', 'MARCO_INVALIDO', 422);
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
        $estado = $d['estado'] ?? $t?->estadoEfetivo() ?? 'PENDENTE';
        if (! in_array($estado, TarefaProjeto::ESTADOS, true)) {
            throw new ErroNegocio('Estado da tarefa inválido.', 'ESTADO_INVALIDO', 422);
        }
        $pct = array_key_exists('percentagem_execucao', $d) ? (int) $d['percentagem_execucao'] : (int) round((float) ($t?->percentagem_execucao ?? 0));
        $pct = $estado === 'CONCLUIDA' ? 100 : max(0, min(100, $pct));
        $valor = array_key_exists('valor_contrato', $d) ? $d['valor_contrato'] : $t?->valor_contrato;
        if ($valor !== null && (float) $valor < 0) {
            throw new ErroNegocio('O valor adjudicado não pode ser negativo.', 'VALOR_INVALIDO', 422);
        }
        $dados = ['projeto_id' => $p->id, 'tarefa_pai_id' => $pai ?: null, 'codigo' => array_key_exists('codigo', $d) ? ($d['codigo'] ?: null) : $t?->codigo,
            'nome' => $nome, 'data_inicio' => $inicio ?: null, 'data_fim' => $fim ?: null, 'percentagem_execucao' => $pct,
            'marco_projeto_id' => $marco ?: null, 'atribuido_a_id' => $atribuido ?: null,
            'valor_contrato' => $valor === null || $valor === '' ? null : number_format((float) $valor, 2, '.', '')] + $this->colunasEstado($estado, $t);

        return DB::transaction(function () use ($t, $dados) {
            if ($t) {
                $t->update($dados);

                return $t->refresh();
            }

            return TarefaProjeto::create($dados);
        });
    }

    /** Actualiza só a execução (updateTaskExecution, ui_projects.js:2047). */
    public function atualizarExecucao(TarefaProjeto $t, int $pct): TarefaProjeto
    {
        $t->update(['percentagem_execucao' => max(0, min(100, $pct))]);

        return $t->refresh();
    }

    /**
     * Move a tarefa (projMoverTarefa, ui_projects.js:1927-1965).
     *
     * @param  array{tipo: string, alvo_id?: ?int, marco_projeto_id?: ?int}  $destino  tipo: ANTES | DEPOIS | DENTRO | MARCO
     */
    public function mover(TarefaProjeto $t, array $destino): TarefaProjeto
    {
        return DB::transaction(function () use ($t, $destino) {
            $todas = TarefaProjeto::query()->where('projeto_id', $t->projeto_id)->lockForUpdate()->get()->keyBy('id');
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
            $t->update(['tarefa_pai_id' => $novoPai ?: null, 'marco_projeto_id' => $novoMarco ?: null]);
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

            return $t->refresh();
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
            $porId = TarefaProjeto::query()->whereIn('id', $ids)->get()->keyBy('id');
            foreach (array_reverse($ids) as $id) {
                $porId[$id]->delete();
            }
            $this->projetos->registar($t->projeto_id, $t->tarefa_pai_id ? 'Eliminar subtarefa' : 'Eliminar tarefa', trim("{$t->codigo} {$t->nome}").(count($ids) > 1 ? ' + '.(count($ids) - 1).' subtarefa(s)' : ''));

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
