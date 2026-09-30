<?php

namespace App\Services\Projetos;

use App\Exceptions\ErroNegocio;
use App\Models\LinhaOrcamentoProjeto;
use App\Models\MembroEquipaProjeto;
use App\Models\NoOrganigramaProjeto;
use App\Models\Projeto;
use App\Models\TarefaProjeto;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Organigrama do projecto/obra (js/projectos_organigrama.js): posições hierárquicas, alocação dos membros da equipa,
 * tarefas associadas, orçamento mapeado e valores por posição.
 * Mantém do legado:
 *   - posição com título obrigatório, área, vagas (0 = sem limite), cor, «reporta a», responsável (ocupante),
 *     assistente/apoio (fica antes das outras do nível) e disposição das subposições (COLUNA ou lado a lado);
 *   - uma posição não reporta a si própria nem a uma posição que dela depende;
 *   - várias posições no mesmo nível numa só operação, todas ou nenhuma, sem títulos|área repetidos no nível;
 *   - eliminar: as subposições passam a reportar à chefia da eliminada e os ocupantes ficam sem posição;
 *   - alocar acima das vagas só com confirmação; sair de uma posição retira a responsabilidade;
 *   - arrumar: para trás/para a frente no nível (o assistente troca com assistente), subir, descer, assistente;
 *   - tarefas: uma posição tem várias tarefas e uma tarefa pode estar em várias posições; só tarefas do projecto;
 *   - valores da posição: orçamento e executado das suas tarefas mais as linhas de orçamento mapeadas directamente
 *     (sem contar duas vezes a linha que já pertence a uma dessas tarefas), desvio e consumo;
 *   - modelo base de obra (8 posições) só com o organigrama vazio.
 * Correcções:
 *   - o executado por tarefa é o do razão analítico único (o legado somava o auto e a factura que ele gerava);
 *   - eliminar uma posição limpa também as linhas de orçamento que lhe estavam mapeadas (o legado deixava o id);
 *   - o responsável de uma posição e o membro de uma linha de orçamento têm de ser ocupantes dessa posição
 *     (o legado só o garantia no ecrã).
 */
final class ServicoOrganigramaProjetos
{
    public const CORES = ['azul', 'verde', 'laranja', 'roxo', 'cinza', 'vermelho', 'ciano'];

    public function __construct(
        private readonly ServicoProjetos $projetos,
        private readonly ServicoAnaliticoProjetos $analitico,
    ) {}

    /** Árvore com membros, tarefas e valores (renderProjectOrganigrama / dados). */
    public function organigrama(Projeto $p, ServicoEquipasProjetos $equipas): array
    {
        $nos = $this->nos($p);
        $membros = collect($equipas->membros($p));
        $idsT = TarefaProjeto::query()->where('projeto_id', $p->id)->pluck('id')->flip();
        $custos = $this->analitico->porTarefa($p);
        $orcamento = LinhaOrcamentoProjeto::query()->where('projeto_id', $p->id)->get();
        $saida = [];
        $vistasTarefas = [];
        $vistasLinhas = [];
        foreach ($nos as $n) {
            $tarefas = array_values(array_filter(array_map('intval', $n->tarefas ?? []), fn ($id) => isset($idsT[$id])));
            $directas = $orcamento->filter(fn ($l) => (int) $l->no_organigrama_projeto_id === $n->id && ! in_array((int) $l->tarefa_projeto_id, $tarefas, true));
            $saida[] = $n->toArray() + ['tarefas' => $tarefas, 'membros' => $membros->where('no_organigrama_projeto_id', $n->id)->values()->all(),
                'valores' => self::somar($tarefas, $custos, $directas)];
            foreach ($tarefas as $t) {
                $vistasTarefas[$t] = true;
            }
            foreach ($directas as $l) {
                $vistasLinhas[$l->id] = $l;
            }
        }
        $totais = self::somar(array_keys($vistasTarefas), $custos, collect($vistasLinhas));

        return ['posicoes' => $saida, 'sem_posicao' => $membros->whereNull('no_organigrama_projeto_id')->values()->all(),
            'totais' => $totais + ['tarefas_sem_posicao' => $idsT->keys()->diff(array_keys($vistasTarefas))->count(),
                'orcamento_sem_responsavel' => $orcamento->filter(fn ($l) => ! $l->no_organigrama_projeto_id && ! ($l->tarefa_projeto_id && isset($vistasTarefas[$l->tarefa_projeto_id])))->count(),
                'vagas' => (int) $nos->sum('vagas'), 'membros' => $membros->count(), 'alocados' => $membros->whereNotNull('no_organigrama_projeto_id')->count()]];
    }

    /**
     * Totais de um conjunto de tarefas mais linhas de orçamento directas (somar, projectos_organigrama.js:180-199).
     *
     * @param  list<int>  $tarefas
     */
    public static function somar(array $tarefas, array $custos, Collection $directas): array
    {
        $t = ['orcamento' => '0.00', 'executado' => '0.00', 'horas' => 0.0, 'requisicoes' => 0, 'com_orcamento' => 0, 'orcamento_direto' => '0.00',
            'orc_por_rubrica' => [], 'exec_por_rubrica' => []];
        foreach ($tarefas as $id) {
            $x = $custos[$id] ?? null;
            if (! $x) {
                continue;
            }
            $t['orcamento'] = bcadd($t['orcamento'], $x['orcamento'], 2);
            $t['executado'] = bcadd($t['executado'], $x['executado'], 2);
            $t['horas'] += $x['horas'];
            $t['requisicoes'] += $x['requisicoes'];
            $t['com_orcamento'] += (float) $x['orcamento'] > 0 ? 1 : 0;
            foreach (['orc_por_rubrica', 'exec_por_rubrica'] as $k) {
                foreach ($x[$k] as $r => $v) {
                    $t[$k][$r] = bcadd($t[$k][$r] ?? '0', $v, 2);
                }
            }
        }
        foreach ($directas as $l) {
            $v = ServicoAnaliticoProjetos::dinheiro($l->montante);
            $t['orcamento'] = bcadd($t['orcamento'], $v, 2);
            $t['orcamento_direto'] = bcadd($t['orcamento_direto'], $v, 2);
            $r = $l->rubrica ?: 'DIVERSOS';
            $t['orc_por_rubrica'][$r] = bcadd($t['orc_por_rubrica'][$r] ?? '0', $v, 2);
        }
        $t['desvio'] = bcsub($t['executado'], $t['orcamento'], 2);
        $t['consumo_pct'] = (float) $t['orcamento'] > 0 ? (int) round((float) $t['executado'] / (float) $t['orcamento'] * 100) : null;

        return $t;
    }

    // ───────────── Posições ─────────────

    /** @param  array<string, mixed>  $d */
    public function guardarPosicao(Projeto $p, array $d, ?NoOrganigramaProjeto $n = null): NoOrganigramaProjeto
    {
        $titulo = trim((string) ($d['titulo'] ?? $n?->titulo ?? ''));
        if ($titulo === '') {
            throw new ErroNegocio('Indique a posição / cargo.', 'TITULO_OBRIGATORIO', 422);
        }
        $pai = array_key_exists('no_pai_id', $d) ? ($d['no_pai_id'] ?: null) : $n?->no_pai_id;
        $cor = $d['cor'] ?? $n?->cor ?? 'azul';
        if (! in_array($cor, self::CORES, true)) {
            $cor = 'azul';
        }

        return DB::transaction(function () use ($p, $d, $n, $titulo, $pai, $cor) {
            $nos = NoOrganigramaProjeto::query()->where('projeto_id', $p->id)->lockForUpdate()->get();
            if ($pai) {
                if (! $nos->contains('id', (int) $pai)) {
                    throw new ErroNegocio('A posição superior não existe neste projecto.', 'POSICAO_SUPERIOR_INVALIDA', 422);
                }
                if ($n && ((int) $pai === $n->id || in_array((int) $pai, self::descendentes($nos, $n->id), true))) {
                    throw new ErroNegocio('Uma posição não pode reportar a si própria nem a uma posição que dela depende.', 'CICLO_POSICOES', 422);
                }
            }
            $dados = ['projeto_id' => $p->id, 'titulo' => $titulo, 'area' => mb_substr(trim((string) ($d['area'] ?? $n?->area ?? '')), 0, 30) ?: null,
                'descricao' => trim((string) ($d['descricao'] ?? $n?->descricao ?? '')) ?: null, 'vagas' => max(0, (int) ($d['vagas'] ?? $n?->vagas ?? 1)),
                'no_pai_id' => $pai, 'cor' => $cor, 'apoio' => (int) (bool) ($d['apoio'] ?? $n?->apoio ?? 0)];
            if ($n && array_key_exists('membro_responsavel_id', $d)) {
                $resp = $d['membro_responsavel_id'] ?: null;
                if ($resp && ! MembroEquipaProjeto::query()->whereKey($resp)->where('no_organigrama_projeto_id', $n->id)->exists()) {
                    throw new ErroNegocio('O responsável tem de ocupar esta posição.', 'RESPONSAVEL_INVALIDO', 422);
                }
                $dados['membro_responsavel_id'] = $resp;
            }
            if ($n) {
                $n->update($dados);
                $this->projetos->registar($p->id, 'Editar posição', $titulo);

                return $n->refresh();
            }
            $irmaos = $nos->filter(fn ($x) => (int) $x->no_pai_id === (int) $pai)->count();
            $novo = NoOrganigramaProjeto::create($dados + ['ordem' => ($irmaos + 1) * 10, 'membro_responsavel_id' => null]);
            $this->projetos->registar($p->id, 'Criar posição', $titulo);

            return $novo;
        });
    }

    /**
     * Várias posições no mesmo nível, todas ou nenhuma (projOrgGravarPosicoes, projectos_organigrama.js:814-842).
     *
     * @param  list<array{titulo: string, area?: ?string, vagas?: mixed}>  $linhas
     */
    public function guardarPosicoes(Projeto $p, ?int $pai, string $cor, array $linhas): int
    {
        return DB::transaction(function () use ($p, $pai, $cor, $linhas) {
            $nos = NoOrganigramaProjeto::query()->where('projeto_id', $p->id)->lockForUpdate()->get();
            if ($pai && ! $nos->contains('id', $pai)) {
                throw new ErroNegocio('A posição superior não existe.', 'POSICAO_SUPERIOR_INVALIDA', 422);
            }
            $validas = array_values(array_filter(array_map(fn ($l) => ['titulo' => trim((string) ($l['titulo'] ?? '')), 'area' => mb_substr(trim((string) ($l['area'] ?? '')), 0, 30),
                'vagas' => max(0, (int) ($l['vagas'] ?? 0))], $linhas), fn ($l) => $l['titulo'] !== ''));
            if (! $validas) {
                throw new ErroNegocio('Indique pelo menos uma posição.', 'SEM_POSICOES', 422);
            }
            $chave = fn ($t, $a) => mb_strtolower($t).'|'.mb_strtolower((string) $a);
            $irmaos = $nos->filter(fn ($x) => (int) $x->no_pai_id === (int) $pai);
            $existentes = $irmaos->map(fn ($x) => $chave(trim($x->titulo), trim((string) $x->area)))->flip();
            $erros = [];
            $vistos = [];
            foreach ($validas as $i => $l) {
                $k = $chave($l['titulo'], $l['area']);
                if (isset($existentes[$k])) {
                    $erros[] = 'Linha '.($i + 1).": já existe «{$l['titulo']}»".($l['area'] ? " ({$l['area']})" : '').' neste nível.';
                }
                if (isset($vistos[$k])) {
                    $erros[] = 'Linha '.($i + 1).": «{$l['titulo']}»".($l['area'] ? " ({$l['area']})" : '').' está repetida.';
                }
                $vistos[$k] = true;
            }
            if ($erros) {
                throw new ErroNegocio(implode(' ', $erros).' Para posições iguais em frentes diferentes, indique a área de cada uma.', 'POSICOES_REPETIDAS', 422, ['linhas' => $erros]);
            }
            $base = (int) $irmaos->max('ordem');
            foreach ($validas as $i => $l) {
                NoOrganigramaProjeto::create(['projeto_id' => $p->id, 'no_pai_id' => $pai, 'titulo' => $l['titulo'], 'area' => $l['area'] ?: null, 'vagas' => $l['vagas'],
                    'ordem' => $base + ($i + 1) * 10, 'cor' => in_array($cor, self::CORES, true) ? $cor : 'azul', 'membro_responsavel_id' => null]);
            }
            $this->projetos->registar($p->id, 'Criar posições em lote', implode(', ', array_column($validas, 'titulo')));

            return count($validas);
        });
    }

    public function eliminarPosicao(Projeto $p, NoOrganigramaProjeto $n): void
    {
        DB::transaction(function () use ($p, $n) {
            NoOrganigramaProjeto::query()->where('projeto_id', $p->id)->where('no_pai_id', $n->id)->update(['no_pai_id' => $n->no_pai_id]);
            MembroEquipaProjeto::query()->where('no_organigrama_projeto_id', $n->id)->update(['no_organigrama_projeto_id' => null]);
            LinhaOrcamentoProjeto::query()->where('no_organigrama_projeto_id', $n->id)->update(['no_organigrama_projeto_id' => null, 'membro_equipa_projeto_id' => null]);
            $n->delete();
            $this->projetos->registar($p->id, 'Eliminar posição', $n->titulo);
        });
    }

    /**
     * Membros → posição (null = sem posição) (projOrgAlocar, projectos_organigrama.js:863-882).
     *
     * @param  list<int>  $ids
     */
    public function alocar(Projeto $p, array $ids, ?int $noId, bool $confirmarExcesso = false): int
    {
        return DB::transaction(function () use ($p, $ids, $noId, $confirmarExcesso) {
            $membros = MembroEquipaProjeto::query()->whereIn('id', array_map('intval', $ids))
                ->whereIn('equipa_projeto_id', DB::table('equipas_projeto')->where('projeto_id', $p->id)->select('id'))->lockForUpdate()->get();
            if ($membros->isEmpty()) {
                throw new ErroNegocio('Nenhum dos membros indicados pertence à equipa do projecto.', 'MEMBRO_INEXISTENTE', 422);
            }
            if ($noId) {
                $n = NoOrganigramaProjeto::query()->where('projeto_id', $p->id)->lockForUpdate()->find($noId) ?? throw new ErroNegocio('Posição inexistente.', 'POSICAO_INEXISTENTE', 422);
                $ja = MembroEquipaProjeto::query()->where('no_organigrama_projeto_id', $n->id)->whereNotIn('id', $membros->pluck('id'))->count();
                if ((int) $n->vagas && $ja + $membros->count() > (int) $n->vagas && ! $confirmarExcesso) {
                    throw new ErroNegocio("A posição «{$n->titulo}» tem {$n->vagas} vaga(s) e ficaria com ".($ja + $membros->count()).' membro(s). Confirme para alocar.',
                        'VAGAS_EXCEDIDAS', 422, ['vagas' => (int) $n->vagas, 'ficaria_com' => $ja + $membros->count()]);
                }
            }
            foreach ($membros as $m) {
                if ($m->no_organigrama_projeto_id && (int) $m->no_organigrama_projeto_id !== (int) $noId) {
                    NoOrganigramaProjeto::query()->whereKey($m->no_organigrama_projeto_id)->where('membro_responsavel_id', $m->id)->update(['membro_responsavel_id' => null]);
                }
                $m->update(['no_organigrama_projeto_id' => $noId]);
            }

            return $membros->count();
        });
    }

    /** Arrumar uma posição (projOrgArrumar): TRAS | FRENTE | SUBIR | DESCER | APOIO. */
    public function arrumar(Projeto $p, NoOrganigramaProjeto $n, string $acao): NoOrganigramaProjeto
    {
        return DB::transaction(function () use ($p, $n, $acao) {
            $nos = NoOrganigramaProjeto::query()->where('projeto_id', $p->id)->lockForUpdate()->get();
            $n = $nos->firstWhere('id', $n->id);
            $pai = $n->no_pai_id ? $nos->firstWhere('id', $n->no_pai_id) : null;
            switch (strtoupper($acao)) {
                case 'APOIO':
                    $n->update(['apoio' => $n->apoio ? 0 : 1]);
                    break;
                case 'TRAS':
                case 'FRENTE':
                    $irmaos = $this->renumerar($nos, $n->no_pai_id)->filter(fn ($x) => (bool) $x->apoio === (bool) $n->apoio)->values();
                    $i = $irmaos->search(fn ($x) => $x->id === $n->id);
                    $j = strtoupper($acao) === 'TRAS' ? $i - 1 : $i + 1;
                    if ($j < 0 || $j >= $irmaos->count()) {
                        throw new ErroNegocio(strtoupper($acao) === 'TRAS' ? 'Já é a primeira caixa deste nível.' : 'Já é a última caixa deste nível.', 'SEM_MOVIMENTO', 422);
                    }
                    [$oa, $ob] = [$irmaos[$i]->ordem, $irmaos[$j]->ordem];
                    $irmaos[$i]->update(['ordem' => $ob]);
                    $irmaos[$j]->update(['ordem' => $oa]);
                    break;
                case 'SUBIR':
                    if (! $pai) {
                        throw new ErroNegocio('Esta posição já está no topo do organigrama.', 'SEM_MOVIMENTO', 422);
                    }
                    $this->renumerar($nos, $pai->no_pai_id);
                    $n->update(['no_pai_id' => $pai->no_pai_id, 'ordem' => (int) $pai->refresh()->ordem + 5]);
                    break;
                case 'DESCER':
                    $irmaos = self::ordenados($nos, $n->no_pai_id);
                    $i = $irmaos->search(fn ($x) => $x->id === $n->id);
                    $novoPai = $i > 0 ? $irmaos[$i - 1] : throw new ErroNegocio('Não há uma caixa acima desta no mesmo nível para lhe passar a reportar.', 'SEM_MOVIMENTO', 422);
                    $n->update(['no_pai_id' => $novoPai->id, 'ordem' => (self::ordenados($nos, $novoPai->id)->count() + 1) * 10]);
                    break;
                default:
                    throw new ErroNegocio('Acção inválida (TRAS, FRENTE, SUBIR, DESCER ou APOIO).', 'ACAO_INVALIDA', 422);
            }
            $this->projetos->registar($p->id, 'Arrumar posição', "{$n->titulo}: {$acao}");

            return $n->refresh();
        });
    }

    /** Disposição das subposições na vista vertical (projOrgDisposicao / projOrgDisposicaoGlobal). */
    public function disposicao(Projeto $p, ?array $ids, ?string $valor, ?string $modoGlobal = null): int
    {
        $nos = NoOrganigramaProjeto::query()->where('projeto_id', $p->id)->get();
        if ($modoGlobal) {
            $filhos = $nos->groupBy(fn ($x) => (int) $x->no_pai_id);
            $ids = $modoGlobal === 'FINAIS'
                ? $nos->filter(fn ($n) => ($filhos[$n->id] ?? collect())->count() >= 2 && ($filhos[$n->id] ?? collect())->every(fn ($f) => ! isset($filhos[$f->id])))->pluck('id')->all()
                : $nos->where('disposicao', 'COLUNA')->pluck('id')->all();
            $valor = $modoGlobal === 'FINAIS' ? 'COLUNA' : null;
        }
        $ids = array_values(array_intersect(array_map('intval', $ids ?? []), $nos->pluck('id')->all()));

        return NoOrganigramaProjeto::query()->whereIn('id', $ids)->update(['disposicao' => $valor === 'COLUNA' ? 'COLUNA' : null]);
    }

    /** Tarefas associadas à posição (projOrgAssociarTarefas): só tarefas do projecto, sem repetição. */
    public function associarTarefas(Projeto $p, NoOrganigramaProjeto $n, array $ids): NoOrganigramaProjeto
    {
        $validas = TarefaProjeto::query()->where('projeto_id', $p->id)->pluck('id')->all();
        $lista = array_values(array_unique(array_intersect(array_map('intval', $ids), $validas)));
        $n->update(['tarefas' => $lista]);
        $this->projetos->registar($p->id, 'Associar tarefas', "{$n->titulo}: ".count($lista).' tarefa(s)');

        return $n->refresh();
    }

    /**
     * Mapear o orçamento (projOrgMapearOrcamento): cada linha a uma posição e ao membro responsável dessa posição.
     *
     * @param  list<array{linha_id: int, no_organigrama_projeto_id?: ?int, membro_equipa_projeto_id?: ?int}>  $alteracoes
     */
    public function mapearOrcamento(Projeto $p, array $alteracoes): int
    {
        return DB::transaction(function () use ($p, $alteracoes) {
            $nos = NoOrganigramaProjeto::query()->where('projeto_id', $p->id)->pluck('id')->flip();
            $n = 0;
            foreach ($alteracoes as $a) {
                $l = LinhaOrcamentoProjeto::query()->where('projeto_id', $p->id)->lockForUpdate()->find($a['linha_id'] ?? 0)
                    ?? throw new ErroNegocio('Linha de orçamento inexistente neste projecto.', 'LINHA_INEXISTENTE', 422);
                $no = $a['no_organigrama_projeto_id'] ?? null;
                $mb = $a['membro_equipa_projeto_id'] ?? null;
                if ($no && ! isset($nos[$no])) {
                    throw new ErroNegocio('Posição inexistente neste projecto.', 'POSICAO_INEXISTENTE', 422);
                }
                if ($mb && (! $no || ! MembroEquipaProjeto::query()->whereKey($mb)->where('no_organigrama_projeto_id', $no)->exists())) {
                    throw new ErroNegocio('O membro responsável tem de ocupar a posição escolhida.', 'MEMBRO_INVALIDO', 422);
                }
                $l->update(['no_organigrama_projeto_id' => $no, 'membro_equipa_projeto_id' => $mb]);
                $n++;
            }
            $this->projetos->registar($p->id, 'Mapear orçamento', "{$n} linha(s) de orçamento");

            return $n;
        });
    }

    /** Modelo base de obra (projOrgModeloBase, projectos_organigrama.js:933-949). */
    public function modeloBase(Projeto $p): int
    {
        return DB::transaction(function () use ($p) {
            if (NoOrganigramaProjeto::query()->where('projeto_id', $p->id)->lockForUpdate()->exists()) {
                throw new ErroNegocio('O projecto já tem organigrama.', 'ORGANIGRAMA_EXISTENTE', 422);
            }
            $add = fn (string $titulo, ?int $pai, array $x = []) => NoOrganigramaProjeto::create(['projeto_id' => $p->id, 'no_pai_id' => $pai, 'titulo' => $titulo,
                'area' => $x['area'] ?? null, 'vagas' => $x['vagas'] ?? 1, 'ordem' => $x['ordem'] ?? 10, 'cor' => $x['cor'] ?? 'azul', 'membro_responsavel_id' => null])->id;
            $dir = $add('Director de Projecto / Obra', null, ['cor' => 'azul']);
            $eng = $add('Engenheiro Residente', $dir, ['cor' => 'ciano', 'area' => 'Produção']);
            $enc = $add('Encarregado Geral', $eng, ['cor' => 'laranja', 'area' => 'Produção']);
            $add('Encarregado de Frente', $enc, ['cor' => 'laranja', 'area' => 'Produção', 'vagas' => 2]);
            $add('Técnico de Segurança (HST)', $dir, ['ordem' => 20, 'cor' => 'vermelho', 'area' => 'Higiene e segurança']);
            $add('Técnico de Qualidade', $dir, ['ordem' => 30, 'cor' => 'verde', 'area' => 'Qualidade']);
            $add('Medições e Orçamentos', $dir, ['ordem' => 40, 'cor' => 'roxo', 'area' => 'Controlo']);
            $add('Administrativo / Logística', $dir, ['ordem' => 50, 'cor' => 'cinza', 'area' => 'Apoio']);
            $this->projetos->registar($p->id, 'Criar modelo de obra', $p->codigo);

            return 8;
        });
    }

    // ───────────── Auxiliares ─────────────

    /** @return Collection<int, NoOrganigramaProjeto> */
    public function nos(Projeto $p): Collection
    {
        return NoOrganigramaProjeto::query()->where('projeto_id', $p->id)->get()->sortBy([['ordem', 'asc'], ['id', 'asc']])->values();
    }

    /** Irmãos pela ordem de desenho: assistentes primeiro, depois a ordem (ordenados). */
    private static function ordenados(Collection $nos, ?int $pai): Collection
    {
        return $nos->filter(fn ($x) => (int) $x->no_pai_id === (int) $pai)
            ->sort(fn ($a, $b) => ((int) (bool) $b->apoio <=> (int) (bool) $a->apoio) ?: (((int) $a->ordem <=> (int) $b->ordem) ?: ($a->id <=> $b->id)))->values();
    }

    /** Renumera os irmãos 10, 20, 30… (renumerar). */
    private function renumerar(Collection $nos, ?int $pai): Collection
    {
        $irmaos = self::ordenados($nos, $pai);
        foreach ($irmaos as $i => $x) {
            if ((int) $x->ordem !== ($i + 1) * 10) {
                $x->update(['ordem' => ($i + 1) * 10]);
            }
        }

        return $irmaos;
    }

    /** @return list<int> */
    public static function descendentes(Collection $nos, int $id): array
    {
        $filhos = $nos->groupBy(fn ($x) => (int) $x->no_pai_id);
        $saida = [];
        $pilha = [$id];
        while ($pilha) {
            foreach ($filhos[array_pop($pilha)] ?? [] as $f) {
                if (! in_array($f->id, $saida, true) && $f->id !== $id) {
                    $saida[] = $f->id;
                    $pilha[] = $f->id;
                }
            }
        }

        return $saida;
    }
}
