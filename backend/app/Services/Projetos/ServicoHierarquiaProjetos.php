<?php

namespace App\Services\Projetos;

use App\Exceptions\ErroNegocio;
use App\Models\MarcoProjeto;
use App\Models\NoOrganigramaProjeto;
use App\Models\Projeto;
use App\Models\TarefaProjeto;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Hierarquia, datas e dependências do planeamento (Versão Actual, 2026-10-11): js/projectos_hierarquia.js.
 * Mantém do legado:
 *   - datas dentro do nível acima: subtarefa ⊂ tarefa-mãe, tarefa ⊂ fase (data de início e data alvo da fase), com a
 *     opção de encaixar automaticamente mantendo a duração sempre que couber (:63-105);
 *   - aviso das subtarefas que ficam fora quando as datas do pai mudam (conflitosAbaixo, :109-125);
 *   - dependência Fim→Início (FS, por omissão), Início→Início (SS) e Fim→Fim (FF), com desfasamento em dias:
 *     FS: início ≥ fim(pred) + 1 + desf; SS: início ≥ início(pred) + desf; FF: fim ≥ fim(pred) + desf (:127-165);
 *   - não podem ser predecessoras a própria tarefa, as suas descendentes nem as que fechariam um ciclo (:131-172);
 *   - ordenação automática dos irmãos por data, nunca antes da predecessora (Kahn; sem datas vale a ordem manual) (:174-218);
 *   - duplicar fase, tarefa ou subtarefa com tudo o que está por baixo e deslocamento das datas; a cópia nasce a 0 % e
 *     PENDENTE, sem horas, custos, autos nem medições; as precedências internas ao bloco ligam as cópias (:275-405);
 *   - mudar de nível e converter fase ↔ tarefa: as subtarefas acompanham sempre o pai (:682-838).
 * Correcções:
 *   - tudo validado no servidor, numa transacção e com bloqueio das tarefas do projecto;
 *   - converter uma tarefa em fase é recusado quando a tarefa tem registos (horas, custos, autos, orçamento,
 *     requisições, compras) ou medições — o legado apagava a tarefa e deixava esses registos órfãos;
 *   - as dependências de outras tarefas para a tarefa convertida passam a apontar para nada, com registo no log
 *     (no legado ficavam a apontar para um id inexistente);
 *   - converter uma fase em tarefa leva a meta da fase para a tarefa nova.
 */
final class ServicoHierarquiaProjetos
{
    public const TIPOS_DEP = [
        'FS' => 'Fim → Início',
        'SS' => 'Início → Início',
        'FF' => 'Fim → Fim',
    ];

    public function __construct(
        private readonly ServicoProjetos $projetos,
        private readonly ServicoPlaneamentoProjetos $planeamento,
    ) {}

    private static function dia(mixed $v): string
    {
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d');
        }

        return substr(trim((string) $v), 0, 10);
    }

    private static function pt(string $d): string
    {
        return $d !== '' ? implode('/', array_reverse(explode('-', $d))) : '—';
    }

    // ───────────── Datas dentro do nível acima ─────────────

    /**
     * Intervalo do nível acima (intervaloDoPai, :65-77): a tarefa-mãe ou, para uma tarefa principal, a fase.
     *
     * @param  Collection<int, TarefaProjeto>  $tarefas  por id
     * @param  Collection<int, MarcoProjeto>  $marcos  por id
     * @return array{de: string, ate: string, rotulo: string}|null
     */
    public static function intervaloDoPai(?int $paiId, ?int $marcoId, Collection $tarefas, Collection $marcos): ?array
    {
        if ($paiId) {
            $p = $tarefas[$paiId] ?? null;

            return $p ? ['de' => self::dia($p->data_inicio), 'ate' => self::dia($p->data_fim), 'rotulo' => "a tarefa «{$p->nome}»"] : null;
        }
        if ($marcoId) {
            $m = $marcos[$marcoId] ?? null;

            return $m ? ['de' => self::dia($m->data_inicio), 'ate' => self::dia($m->data), 'rotulo' => "a fase «{$m->nome}»"] : null;
        }

        return null;
    }

    /** Erro das datas face ao nível acima, ou null (validarDatas, :80-90). */
    public static function validarDatas(?string $inicio, ?string $fim, ?int $paiId, ?int $marcoId, Collection $tarefas, Collection $marcos): ?string
    {
        $i = self::dia($inicio);
        $f = self::dia($fim);
        if ($i !== '' && $f !== '' && $f < $i) {
            return 'A data de fim é anterior à data de início.';
        }
        $lim = self::intervaloDoPai($paiId, $marcoId, $tarefas, $marcos);
        if (! $lim) {
            return null;
        }
        $r = $lim['rotulo'];

        return match (true) {
            $lim['de'] !== '' && $i !== '' && $i < $lim['de'] => 'A data de início ('.self::pt($i).") é anterior ao início d{$r} (".self::pt($lim['de']).').',
            $lim['ate'] !== '' && $f !== '' && $f > $lim['ate'] => 'A data de fim ('.self::pt($f).") é posterior ao fim d{$r} (".self::pt($lim['ate']).').',
            $lim['ate'] !== '' && $i !== '' && $i > $lim['ate'] => 'A data de início ('.self::pt($i).") é posterior ao fim d{$r} (".self::pt($lim['ate']).').',
            $lim['de'] !== '' && $f !== '' && $f < $lim['de'] => 'A data de fim ('.self::pt($f).") é anterior ao início d{$r} (".self::pt($lim['de']).').',
            default => null,
        };
    }

    /** Encaixa as datas no nível acima (encaixar, :93-105). @return array{inicio: ?string, fim: ?string} */
    public static function encaixar(?string $inicio, ?string $fim, ?int $paiId, ?int $marcoId, Collection $tarefas, Collection $marcos): array
    {
        $i = self::dia($inicio);
        $f = self::dia($fim);
        $lim = self::intervaloDoPai($paiId, $marcoId, $tarefas, $marcos);
        if ($lim) {
            if ($lim['de'] !== '' && $i !== '' && $i < $lim['de']) {
                $i = $lim['de'];
            }
            if ($lim['ate'] !== '' && $f !== '' && $f > $lim['ate']) {
                $f = $lim['ate'];
            }
            if ($lim['de'] !== '' && $i === '') {
                $i = $lim['de'];
            }
            if ($lim['ate'] !== '' && $f === '') {
                $f = $lim['ate'];
            }
            if ($lim['ate'] !== '' && $i !== '' && $i > $lim['ate']) {
                $i = $lim['ate'];
            }
            if ($lim['de'] !== '' && $f !== '' && $f < $lim['de']) {
                $f = $lim['de'];
            }
        }
        if ($i !== '' && $f !== '' && $f < $i) {
            $f = $i;
        }

        return ['inicio' => $i ?: null, 'fim' => $f ?: null];
    }

    /**
     * Subtarefas (a qualquer profundidade) que ficam fora das datas do pai (conflitosAbaixo, :109-125).
     *
     * @return list<array{id: int, nome: string, inicio: ?string, fim: ?string}>
     */
    public static function conflitosAbaixo(TarefaProjeto $t, Collection $todas, ?array $novasDatas = null): array
    {
        $filhos = $todas->groupBy(fn ($x) => (int) $x->tarefa_pai_id);
        $fora = [];
        $verificar = function (int $paiId, string $de, string $ate, array $pilha) use (&$verificar, &$fora, $filhos) {
            foreach ($filhos[$paiId] ?? [] as $f) {
                if (isset($pilha[$f->id])) {
                    continue;
                }
                $fi = self::dia($f->data_inicio);
                $ff = self::dia($f->data_fim);
                if (($de !== '' && $fi !== '' && $fi < $de) || ($ate !== '' && $ff !== '' && $ff > $ate)) {
                    $fora[] = ['id' => $f->id, 'nome' => $f->nome, 'inicio' => $fi ?: null, 'fim' => $ff ?: null];
                }
                $verificar($f->id, $fi, $ff, $pilha + [$f->id => true]);
            }
        };
        $verificar($t->id, self::dia($novasDatas['inicio'] ?? $t->data_inicio), self::dia($novasDatas['fim'] ?? $t->data_fim), [$t->id => true]);

        return $fora;
    }

    // ───────────── Dependências ─────────────

    /** Seguir a cadeia de predecessoras a partir de $deId: chega-se a $tarefaId? (criaCiclo, :131-139) */
    public static function criaCiclo(?int $tarefaId, int $deId, Collection $tarefas): bool
    {
        if (! $tarefaId) {
            return false;
        }
        $actual = $tarefas[$deId] ?? null;
        $vistos = [];
        while ($actual && ! isset($vistos[$actual->id])) {
            if ($actual->id === $tarefaId) {
                return true;
            }
            $vistos[$actual->id] = true;
            $actual = $actual->tarefa_predecessora_id ? ($tarefas[$actual->tarefa_predecessora_id] ?? null) : null;
        }

        return false;
    }

    /** Data mais cedo de início (ou de fim, em FF) imposta pela predecessora (dataMinima, :142-150). */
    public static function dataMinima(?TarefaProjeto $pred, string $tipo, int $desf): ?string
    {
        if (! $pred) {
            return null;
        }

        return match ($tipo) {
            'SS' => self::dia($pred->data_inicio) !== '' ? RegrasMetasProjetos::somarDias(self::dia($pred->data_inicio), $desf) : null,
            'FF' => self::dia($pred->data_fim) !== '' ? RegrasMetasProjetos::somarDias(self::dia($pred->data_fim), $desf) : null,
            default => self::dia($pred->data_fim) !== '' ? RegrasMetasProjetos::somarDias(self::dia($pred->data_fim), 1 + $desf) : null,
        };
    }

    /** Erro da dependência, ou null (validarDependencia, :152-165). */
    public static function validarDependencia(?string $inicio, ?string $fim, ?TarefaProjeto $pred, string $tipo, int $desf): ?string
    {
        $min = self::dataMinima($pred, $tipo, $desf);
        if (! $min) {
            return null;
        }
        $nome = self::TIPOS_DEP[$tipo] ?? $tipo;
        if ($tipo === 'FF') {
            return self::dia($fim) !== '' && self::dia($fim) < $min ? "Depende de «{$pred->nome}» ({$nome}): não pode terminar antes de ".self::pt($min).'.' : null;
        }

        return self::dia($inicio) !== '' && self::dia($inicio) < $min ? "Depende de «{$pred->nome}» ({$nome}): não pode começar antes de ".self::pt($min).'.' : null;
    }

    /**
     * Predecessoras possíveis (candidatasDependencia, :169-172).
     *
     * @return list<array{id: int, codigo: ?string, nome: string}>
     */
    public function candidatas(Projeto $p, ?TarefaProjeto $t): array
    {
        $todas = TarefaProjeto::query()->where('projeto_id', $p->id)->get()->keyBy('id');
        $fora = $t ? array_flip($this->planeamento->descendentes($t, $todas)) : [];

        return $todas->filter(fn ($x) => (! $t || $x->id !== $t->id) && ! isset($fora[$x->id]) && ! self::criaCiclo($t?->id, $x->id, $todas))
            ->map(fn ($x) => ['id' => $x->id, 'codigo' => $x->codigo, 'nome' => $x->nome])->values()->all();
    }

    /**
     * Etiquetas de precedência da linha (simbolos, :222-233).
     *
     * @return array{predecessora: ?array{id: int, nome: string, tipo: string, desf: int, rotulo: string}, n_seguintes: int, seguintes: list<string>}
     */
    public static function simbolos(TarefaProjeto $t, Collection $tarefas, Collection $porPredecessora): array
    {
        $p = $t->tarefa_predecessora_id ? ($tarefas[$t->tarefa_predecessora_id] ?? null) : null;
        $tipo = $t->tipo_dependencia ?: 'FS';
        $desf = (int) $t->desfasamento_dias;
        $seg = $porPredecessora[$t->id] ?? collect();
        $nome = fn ($x) => trim(($x->codigo ? "{$x->codigo} " : '').$x->nome);

        return [
            'predecessora' => $p ? ['id' => $p->id, 'nome' => $nome($p), 'tipo' => $tipo, 'desf' => $desf,
                'rotulo' => (self::TIPOS_DEP[$tipo] ?? $tipo).($desf ? ' '.($desf > 0 ? '+' : '').$desf.'d' : '')] : null,
            'n_seguintes' => $seg->count(),
            'seguintes' => $seg->map($nome)->values()->all(),
        ];
    }

    // ───────────── Ordenação ─────────────

    /**
     * Irmãos por data sem nunca pôr uma tarefa antes da sua predecessora (ordenarIrmaos, :179-205).
     *
     * @param  Collection<int, TarefaProjeto>  $grupo
     * @return Collection<int, TarefaProjeto>
     */
    public static function ordenarIrmaos(Collection $grupo): Collection
    {
        $lista = $grupo->values();
        if ($lista->count() < 2) {
            return $lista;
        }
        $chave = fn (TarefaProjeto $t) => implode('|', [self::dia($t->data_inicio) ?: '9999-99-99', self::dia($t->data_fim) ?: '9999-99-99',
            str_pad((string) (($t->ordem ?? 1_000_000) + 1_000_000_000), 14, '0', STR_PAD_LEFT), str_pad((string) $t->id, 10, '0', STR_PAD_LEFT)]);
        $noGrupo = $lista->pluck('id')->flip();
        $presos = [];
        $porPred = [];
        foreach ($lista as $t) {
            $p = $t->tarefa_predecessora_id && isset($noGrupo[$t->tarefa_predecessora_id]) && $t->tarefa_predecessora_id !== $t->id ? (int) $t->tarefa_predecessora_id : null;
            $presos[$t->id] = $p;
            if ($p) {
                $porPred[$p][] = $t;
            }
        }
        $prontas = $lista->filter(fn ($t) => ! $presos[$t->id])->all();
        $saida = [];
        $vistos = [];
        while ($prontas) {
            usort($prontas, fn ($a, $b) => strcmp($chave($a), $chave($b)));
            $t = array_shift($prontas);
            if (isset($vistos[$t->id])) {
                continue;
            }
            $vistos[$t->id] = true;
            $saida[] = $t;
            foreach ($porPred[$t->id] ?? [] as $f) {
                if (! isset($vistos[$f->id])) {
                    $prontas[] = $f;
                }
            }
        }
        $resto = $lista->filter(fn ($t) => ! isset($vistos[$t->id]))->sortBy($chave)->all();

        return collect([...$saida, ...$resto]);
    }

    // ───────────── Duplicar ─────────────

    /**
     * Duplica uma fase (MARCO) ou uma tarefa com tudo o que está por baixo (projDuplicarCamada / duplicar, :281-405).
     *
     * @return array{criados: int, marco_id: ?int, tarefa_id: ?int}
     */
    public function duplicar(Projeto $p, string $tipo, int $id, ?string $nome, int $dias): array
    {
        $this->projetos->exigirAberto($p);
        if (abs($dias) > 3660) {
            throw new ErroNegocio('O deslocamento das datas não pode passar de 10 anos.', 'DESLOCAMENTO_INVALIDO', 422);
        }

        return DB::transaction(function () use ($p, $tipo, $id, $nome, $dias) {
            $todas = TarefaProjeto::query()->where('projeto_id', $p->id)->lockForUpdate()->get()->keyBy('id');
            $desloca = fn ($d) => self::dia($d) !== '' ? RegrasMetasProjetos::somarDias(self::dia($d), $dias) : null;
            $novoDe = [];
            $marcoNovo = null;
            if ($tipo === 'MARCO') {
                $alvo = MarcoProjeto::query()->where('projeto_id', $p->id)->findOrFail($id);
                $nome = trim((string) $nome) ?: "{$alvo->nome} (cópia)";
                $marcoNovo = MarcoProjeto::create(['projeto_id' => $p->id, 'nome' => mb_substr($nome, 0, 255), 'data_inicio' => $desloca($alvo->data_inicio),
                    'data' => $desloca($alvo->data), 'estado' => 'PENDENTE', 'meta_unidade' => $alvo->meta_unidade, 'meta_quantidade' => $alvo->meta_quantidade]);
                $raizes = $todas->filter(fn ($x) => ! $x->tarefa_pai_id && (int) $x->marco_projeto_id === $alvo->id);
                $marcoDestino = $marcoNovo->id;
                $paiRaiz = null;
            } else {
                $alvo = $todas[$id] ?? throw new ErroNegocio('Tarefa não encontrada.', 'TAREFA_INEXISTENTE', 404);
                $nome = trim((string) $nome) ?: "{$alvo->nome} (cópia)";
                $raizes = collect([$alvo]);
                $marcoDestino = $alvo->marco_projeto_id;
                $paiRaiz = $alvo->tarefa_pai_id;
            }
            $filhos = $todas->groupBy(fn ($x) => (int) $x->tarefa_pai_id);
            $copiar = function (TarefaProjeto $t, ?int $pai) use (&$copiar, &$novoDe, $filhos, $p, $desloca, $marcoDestino, $alvo, $tipo, $nome) {
                $n = TarefaProjeto::create(['projeto_id' => $p->id, 'tarefa_pai_id' => $pai, 'marco_projeto_id' => $marcoDestino,
                    'codigo' => $t->codigo, 'nome' => $tipo !== 'MARCO' && $t->id === $alvo->id ? mb_substr($nome, 0, 255) : $t->nome,
                    'data_inicio' => $desloca($t->data_inicio), 'data_fim' => $desloca($t->data_fim), 'estado' => 'PENDENTE', 'estado_original' => 'PENDENTE',
                    'percentagem_execucao' => 0, 'atribuido_a_id' => $t->atribuido_a_id, 'valor_contrato' => $t->valor_contrato,
                    'tarefa_predecessora_id' => $t->tarefa_predecessora_id, 'tipo_dependencia' => $t->tipo_dependencia, 'desfasamento_dias' => $t->desfasamento_dias,
                    'meta_unidade' => $t->meta_unidade, 'meta_quantidade' => $t->meta_quantidade, 'meta_realizado' => $t->temMeta() ? 0 : null,
                    'meta_execucoes' => $t->temMeta() ? [] : null, 'ordem' => $t->ordem]);
                $novoDe[$t->id] = $n->id;
                foreach ($filhos[$t->id] ?? [] as $f) {
                    if (! isset($novoDe[$f->id])) {
                        $copiar($f, $n->id);
                    }
                }
            };
            foreach ($raizes as $r) {
                $copiar($r, $tipo === 'MARCO' ? null : $paiRaiz);
            }
            // precedências internas ao bloco passam a ligar as cópias; as de fora ficam a apontar para o original
            foreach ($novoDe as $orig => $copia) {
                $pred = $todas[$orig]->tarefa_predecessora_id;
                if ($pred && isset($novoDe[$pred])) {
                    TarefaProjeto::query()->whereKey($copia)->update(['tarefa_predecessora_id' => $novoDe[$pred]]);
                }
            }
            // as posições do organigrama que tinham a tarefa original passam a ter também a cópia
            foreach (NoOrganigramaProjeto::query()->where('projeto_id', $p->id)->lockForUpdate()->get() as $no) {
                $lista = array_map('intval', $no->tarefas ?? []);
                $mais = array_values(array_filter(array_map(fn ($x) => $novoDe[$x] ?? null, $lista)));
                if ($mais) {
                    $no->update(['tarefas' => array_values(array_unique([...$lista, ...$mais]))]);
                }
            }
            $criados = count($novoDe) + ($marcoNovo ? 1 : 0);
            $this->projetos->registar($p->id, $tipo === 'MARCO' ? 'Duplicar fase' : 'Duplicar tarefa', "{$alvo->nome} → {$nome} · {$criados} registo(s)".($dias ? " · datas {$dias}d" : ''));

            return ['criados' => $criados, 'marco_id' => $marcoNovo?->id, 'tarefa_id' => $tipo === 'MARCO' ? null : ($novoDe[$alvo->id] ?? null)];
        });
    }

    // ───────────── Mudar de nível / converter ─────────────

    /**
     * Mudar de nível (projMudarNivel, :682-759).
     *   - tarefa → SUB (subtarefa de outra) ou RAIZ (tarefa principal de uma fase / sem fase): pelo mover, com datas;
     *   - tarefa → FASE: converte em fase (as subtarefas passam a tarefas principais da nova fase);
     *   - fase → SUB ou RAIZ: converte em tarefa (as tarefas da fase passam a subtarefas dela).
     *
     * @param  array{destino: string, alvo_id?: ?int, marco_projeto_id?: ?int, encaixar?: bool}  $d
     * @return array{avisos: list<string>, tarefa_id: ?int, marco_id: ?int}
     */
    public function mudarNivel(Projeto $p, string $tipo, int $id, array $d): array
    {
        $this->projetos->exigirAberto($p);
        $destino = strtoupper((string) $d['destino']);
        $encaixar = (bool) ($d['encaixar'] ?? true);
        if ($tipo === 'MARCO') {
            if (! in_array($destino, ['SUB', 'RAIZ'], true)) {
                throw new ErroNegocio('Uma fase passa a tarefa principal (RAIZ) ou a subtarefa (SUB).', 'DESTINO_INVALIDO', 422);
            }

            return $this->converterFaseEmTarefa($p, $id, $destino, $destino === 'SUB' ? ($d['alvo_id'] ?? null) : ($d['marco_projeto_id'] ?? null), $encaixar);
        }
        $t = TarefaProjeto::query()->where('projeto_id', $p->id)->findOrFail($id);
        if ($destino === 'FASE') {
            return $this->converterTarefaEmFase($p, $t);
        }
        if (! in_array($destino, ['SUB', 'RAIZ'], true)) {
            throw new ErroNegocio('Destino inválido (SUB, RAIZ ou FASE).', 'DESTINO_INVALIDO', 422);
        }
        $res = $this->planeamento->moverComDatas($t, $destino === 'SUB' ? ['tipo' => 'DENTRO', 'alvo_id' => $d['alvo_id'] ?? null]
            : ['tipo' => 'MARCO', 'marco_projeto_id' => $d['marco_projeto_id'] ?? null], $encaixar);

        return ['avisos' => $res['avisos'], 'tarefa_id' => $t->id, 'marco_id' => null];
    }

    /** Fase → tarefa: as tarefas da fase passam a subtarefas dela (converterFaseEmTarefa, :774-809). */
    private function converterFaseEmTarefa(Projeto $p, int $faseId, string $modo, ?int $ref, bool $encaixar): array
    {
        return DB::transaction(function () use ($p, $faseId, $modo, $ref, $encaixar) {
            $fase = MarcoProjeto::query()->where('projeto_id', $p->id)->lockForUpdate()->findOrFail($faseId);
            $todas = TarefaProjeto::query()->where('projeto_id', $p->id)->lockForUpdate()->get()->keyBy('id');
            $marcos = MarcoProjeto::query()->where('projeto_id', $p->id)->get()->keyBy('id');
            $filhas = $todas->filter(fn ($x) => ! $x->tarefa_pai_id && (int) $x->marco_projeto_id === $fase->id);
            $inicio = self::dia($fase->data_inicio) ?: ($filhas->map(fn ($f) => self::dia($f->data_inicio))->filter()->sort()->first() ?? '');
            $fim = self::dia($fase->data) ?: ($filhas->map(fn ($f) => self::dia($f->data_fim))->filter()->sort()->last() ?? '');
            $paiId = $modo === 'SUB' ? (int) $ref : null;
            if ($paiId && ! isset($todas[$paiId])) {
                throw new ErroNegocio('A tarefa de destino não pertence ao projecto.', 'TAREFA_DESTINO_INEXISTENTE', 422);
            }
            $marcoId = $modo === 'RAIZ' ? ($ref ?: null) : ($paiId ? $todas[$paiId]->marco_projeto_id : null);
            if ($marcoId === $fase->id) {
                throw new ErroNegocio('Escolha outra fase: a fase convertida deixa de existir.', 'DESTINO_INVALIDO', 422);
            }
            if ($marcoId && ! isset($marcos[$marcoId])) {
                throw new ErroNegocio('O milestone não pertence ao projecto.', 'MARCO_INVALIDO', 422);
            }
            $datas = ['inicio' => $inicio ?: null, 'fim' => $fim ?: null];
            if ($erro = self::validarDatas($inicio, $fim, $paiId, $marcoId, $todas, $marcos)) {
                if (! $encaixar) {
                    throw new ErroNegocio($erro, 'DATAS_FORA_DO_NIVEL', 422);
                }
                $datas = self::encaixar($inicio, $fim, $paiId, $marcoId, $todas, $marcos);
            }
            $nova = TarefaProjeto::create(['projeto_id' => $p->id, 'tarefa_pai_id' => $paiId, 'marco_projeto_id' => $marcoId, 'nome' => $fase->nome,
                'data_inicio' => $datas['inicio'], 'data_fim' => $datas['fim'], 'percentagem_execucao' => 0, 'estado' => 'PENDENTE', 'estado_original' => 'PENDENTE',
                'meta_unidade' => $fase->meta_unidade, 'meta_quantidade' => $fase->meta_quantidade, 'meta_realizado' => (float) $fase->meta_quantidade > 0 ? 0 : null,
                'ordem' => 9999]);
            foreach ($filhas as $f) {
                $f->update(['tarefa_pai_id' => $nova->id, 'marco_projeto_id' => $marcoId]);
                $desc = $this->planeamento->descendentes($f, $todas);
                if ($desc) {
                    TarefaProjeto::query()->whereIn('id', $desc)->update(['marco_projeto_id' => $marcoId]);
                }
            }
            $fase->delete();
            $this->projetos->registar($p->id, 'Converter fase em tarefa', "{$fase->nome} · {$filhas->count()} tarefa(s) passaram a subtarefas");
            $fora = $filhas->filter(fn ($f) => ($datas['inicio'] && self::dia($f->data_inicio) !== '' && self::dia($f->data_inicio) < $datas['inicio'])
                || ($datas['fim'] && self::dia($f->data_fim) !== '' && self::dia($f->data_fim) > $datas['fim']));
            $avisos = $fora->isEmpty() ? [] : ["{$fora->count()} tarefa(s) por baixo ficaram com datas fora do novo intervalo (".self::pt((string) $datas['inicio']).' a '.self::pt((string) $datas['fim']).'): '
                .$fora->take(3)->pluck('nome')->implode(', ').($fora->count() > 3 ? '…' : '').'. Reveja as datas.'];

            return ['avisos' => $avisos, 'tarefa_id' => $nova->id, 'marco_id' => null];
        });
    }

    /** Tarefa → fase: as subtarefas passam a tarefas principais da nova fase (converterTarefaEmFase, :822-838). */
    private function converterTarefaEmFase(Projeto $p, TarefaProjeto $t): array
    {
        return DB::transaction(function () use ($p, $t) {
            $todas = TarefaProjeto::query()->where('projeto_id', $p->id)->lockForUpdate()->get()->keyBy('id');
            $t = $todas[$t->id];
            if ($reg = $this->planeamento->registosDaTarefa([$t->id])) {
                throw new ErroNegocio("«{$t->nome}» tem registos (horas, custos, autos, orçamento ou requisições): não pode passar a fase. Mova-os para outra tarefa primeiro.",
                    'TAREFA_COM_REGISTOS', 422, ['registos' => $reg]);
            }
            if ($t->medicoes()) {
                throw new ErroNegocio("«{$t->nome}» tem medições registadas: não pode passar a fase.", 'TAREFA_COM_REGISTOS', 422);
            }
            $fim = self::dia($t->data_fim) ?: self::dia($t->data_inicio);
            $fase = MarcoProjeto::create(['projeto_id' => $p->id, 'nome' => $t->nome, 'data_inicio' => self::dia($t->data_inicio) ?: null, 'data' => $fim ?: null,
                'estado' => 'PENDENTE', 'meta_unidade' => $t->meta_unidade, 'meta_quantidade' => $t->meta_quantidade]);
            $filhas = $todas->filter(fn ($x) => (int) $x->tarefa_pai_id === $t->id);
            foreach ($filhas as $f) {
                $f->update(['tarefa_pai_id' => null, 'marco_projeto_id' => $fase->id]);
                $desc = $this->planeamento->descendentes($f, $todas);
                if ($desc) {
                    TarefaProjeto::query()->whereIn('id', $desc)->update(['marco_projeto_id' => $fase->id]);
                }
            }
            $soltas = TarefaProjeto::query()->where('projeto_id', $p->id)->where('tarefa_predecessora_id', $t->id)->update(['tarefa_predecessora_id' => null]);
            foreach (NoOrganigramaProjeto::query()->where('projeto_id', $p->id)->lockForUpdate()->get() as $n) {
                $rest = array_values(array_filter($n->tarefas ?? [], fn ($x) => (int) $x !== $t->id));
                if (count($rest) !== count($n->tarefas ?? [])) {
                    $n->update(['tarefas' => $rest]);
                }
            }
            $t->delete();
            $this->projetos->registar($p->id, 'Converter tarefa em fase', "{$t->nome} · {$filhas->count()} subtarefa(s) passaram a tarefas".($soltas ? " · {$soltas} dependência(s) retirada(s)" : ''));

            return ['avisos' => $soltas ? ["{$soltas} tarefa(s) dependiam de «{$t->nome}» e ficaram sem predecessora."] : [], 'tarefa_id' => null, 'marco_id' => $fase->id];
        });
    }
}
