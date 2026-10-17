<?php

namespace App\Services\RH;

use App\Exceptions\ErroNegocio;
use App\Models\Colaborador;
use App\Models\EquipaProjeto;
use App\Models\FolhaHorasProjeto;
use App\Models\ItemProdutividadeRH;
use App\Models\MembroEquipaProjeto;
use App\Models\PeriodoProdutividadeRH;
use App\Models\Projeto;
use App\Models\RegistoProdutividadeRH;
use App\Models\TarefaProjeto;
use App\Services\Projetos\RegrasMetasProjetos;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * RH › Produtividade ← execução dos projectos (Versão Actual, 2026-10-11): js/modules/rh/produtividade_projectos.js.
 * Três fontes, cada uma a casar com uma métrica dos itens de produtividade:
 *   - HORAS ← folhas de horas das tarefas (por colaborador e data);
 *   - QUANTIDADE ← medições das metas (meta_execucoes), atribuídas a quem as executou (membro_id da medição, folha de
 *     medição por pessoa) ou, sem executante, ao responsável da tarefa;
 *   - TAREFAS ← tarefas com concluida_em dentro do intervalo.
 * Mantém do legado:
 *   - recolha agrupada por (tipo, unidade, colaborador); execução de externos ou sem responsável fica à parte, com o
 *     motivo (recolher, :57-158);
 *   - grupos (tipo + unidade comparável) e sugestão do item: mesma métrica e, nas quantidades, unidade equivalente
 *     (m² = m2 = metros quadrados) (grupos/sugerir, :161-180);
 *   - análise contra o período (aberto) e os contratos (só colaboradores elegíveis com o item); data do registo = última
 *     medição, nunca futura e ajustada à janela do período (analisar, :187-224);
 *   - chave de idempotência PRJ|tipo|unidade|colaborador|item|período: reenviar actualiza o registo em vez de duplicar;
 *     origem PROJECTO e `fonte` com projectos, tarefas e intervalo (:208-216, 231-244).
 * Correcções:
 *   - tudo no servidor: permissão `rh_prod_registar` no envio, período bloqueado e reenvio numa só transacção (ou entra
 *     tudo o que é válido ou nada); a análise não grava nada;
 *   - a folha de horas aponta ao colaborador sem ambiguidade (colaborador_id ou o membro interno da equipa — o legado
 *     tinha de adivinhar entre o id do colaborador e o id do membro nas folhas antigas);
 *   - a chave cabe nos 50 caracteres da coluna (unidades longas passam a um resumo de 8 caracteres).
 */
final class ServicoProdutividadeProjetos
{
    public const TIPOS = [
        'HORAS' => ['nome' => 'Horas das folhas de horas', 'metrica' => 'HORAS'],
        'QUANTIDADE' => ['nome' => 'Quantidades medidas nas metas', 'metrica' => 'QUANTIDADE'],
        'TAREFAS' => ['nome' => 'Tarefas concluídas', 'metrica' => 'TAREFAS'],
    ];

    public const ORIGEM = 'PROJECTO';

    public function __construct(private readonly ServicoProdutividade $produtividade) {}

    /**
     * Recolha da execução (recolher, :57-158).
     *
     * @param  list<int>|null  $projetoIds
     * @return array{de: string, ate: string, projetos: list<array{id: int, nome: string}>, linhas: list<array<string, mixed>>, orfas: list<array<string, mixed>>}
     */
    public function recolher(string $de, string $ate, ?array $projetoIds = null): array
    {
        if ($ate < $de) {
            throw new ErroNegocio('O fim do intervalo não pode ser anterior ao início.', 'DATAS_INVALIDAS', 422);
        }
        if (RegrasMetasProjetos::diasEntre($de, $ate) > 400) {
            throw new ErroNegocio('O intervalo de recolha não pode passar de 400 dias.', 'JANELA_EXCESSIVA', 422);
        }
        $projetos = Projeto::query()->when($projetoIds !== null, fn ($q) => $q->whereIn('id', $projetoIds ?: [0]))->orderBy('codigo')->get(['id', 'codigo', 'nome']);
        $saida = ['de' => $de, 'ate' => $ate, 'projetos' => $projetos->map(fn ($p) => ['id' => $p->id, 'nome' => trim(($p->codigo ? "{$p->codigo} · " : '').$p->nome)])->all(),
            'linhas' => [], 'orfas' => []];
        if ($projetos->isEmpty()) {
            return $saida;
        }
        $pids = $projetos->pluck('id')->all();
        $tarefas = TarefaProjeto::query()->whereIn('projeto_id', $pids)->get()->keyBy('id');
        $membros = MembroEquipaProjeto::query()->whereIn('equipa_projeto_id', EquipaProjeto::query()->whereIn('projeto_id', $pids)->select('id'))->get()->keyBy('id');
        $folhas = FolhaHorasProjeto::query()->whereIn('projeto_id', $pids)->whereBetween('data', [$de, $ate])->get();
        $idsColab = $membros->pluck('colaborador_id')->merge($folhas->pluck('colaborador_id'))->filter()->unique()->values()->all();
        $nomes = Colaborador::query()->withTrashed()->whereIn('id', $idsColab ?: [0])->pluck('nome_completo', 'id');
        $linhas = [];
        $orfas = [];
        $juntar = function (array &$mapa, string $k, array $base, float $q, ?string $data, ?TarefaProjeto $t) {
            $mapa[$k] ??= $base + ['quantidade' => 0.0, 'registos' => 0, 'ultima' => '', 'projetos' => [], 'tarefas' => []];
            $mapa[$k]['quantidade'] = RegrasMetasProjetos::r4($mapa[$k]['quantidade'] + $q);
            $mapa[$k]['registos']++;
            if ($data && $data > $mapa[$k]['ultima']) {
                $mapa[$k]['ultima'] = $data;
            }
            if ($t) {
                $mapa[$k]['projetos'][$t->projeto_id] = $t->projeto_id;
                $mapa[$k]['tarefas'][$t->id] = $t->id;
            }
        };
        $colaboradorDaTarefa = fn (?TarefaProjeto $t) => $t && $t->atribuido_a_id ? ($membros[$t->atribuido_a_id]->colaborador_id ?? null) : null;
        $motivo = function (?TarefaProjeto $t) use ($membros): string {
            if (! $t || ! $t->atribuido_a_id) {
                return 'tarefa sem responsável atribuído';
            }
            $m = $membros[$t->atribuido_a_id] ?? null;

            return ! $m ? 'o responsável já não está na equipa' : 'o responsável não é colaborador interno ('.($m->terceiro_id ? 'entidade externa' : 'membro externo').')';
        };
        $base = fn (string $tipo, string $un, int $c) => ['tipo' => $tipo, 'unidade' => $un, 'colaborador_id' => $c, 'nome' => $nomes[$c] ?? "#{$c}"];
        // 1) horas: o colaborador da folha, ou o do membro interno que a lançou
        foreach ($folhas as $f) {
            $t = $tarefas[$f->tarefa_projeto_id] ?? null;
            $c = $f->colaborador_id ?: ($f->membro_equipa_projeto_id ? ($membros[$f->membro_equipa_projeto_id]->colaborador_id ?? null) : null);
            $d = $f->data?->toDateString();
            $c ? $juntar($linhas, "HORAS|horas|{$c}", $base('HORAS', 'horas', (int) $c), (float) $f->horas, $d, $t)
                : $juntar($orfas, 'HORAS|horas|folha', ['tipo' => 'HORAS', 'unidade' => 'horas', 'motivo' => 'a folha de horas não aponta a um colaborador interno'], (float) $f->horas, $d, $t);
        }
        // 2) quantidades medidas (vão ao responsável da tarefa) e 3) tarefas concluídas no intervalo
        foreach ($tarefas as $t) {
            $c = $colaboradorDaTarefa($t);
            $un = trim((string) $t->meta_unidade) ?: 'un';
            foreach ($t->medicoes() as $x) {
                $d = substr((string) ($x['data'] ?? ''), 0, 10);
                if ($d === '' || $d < $de || $d > $ate || ! (float) ($x['q'] ?? 0)) {
                    continue;
                }
                // medição com executante (folha de medição por pessoa): conta para esse colaborador
                $mx = isset($x['membro_id']) ? ($membros[(int) $x['membro_id']] ?? null) : null;
                $cx = isset($x['membro_id']) ? $mx?->colaborador_id : $c;
                $mot = isset($x['membro_id']) ? (! $mx ? 'o executante já não está na equipa' : 'o executante não é colaborador interno ('.($mx->terceiro_id ? 'entidade externa' : 'membro externo').')') : $motivo($t);
                $cx ? $juntar($linhas, "QUANTIDADE|{$un}|{$cx}", $base('QUANTIDADE', $un, (int) $cx), (float) $x['q'], $d, $t)
                    : $juntar($orfas, "QUANTIDADE|{$un}|{$mot}", ['tipo' => 'QUANTIDADE', 'unidade' => $un, 'motivo' => $mot], (float) $x['q'], $d, $t);
            }
            $d = $t->concluida_em?->toDateString();
            if ($d && $d >= $de && $d <= $ate) {
                $c ? $juntar($linhas, "TAREFAS|tarefas|{$c}", $base('TAREFAS', 'tarefas', (int) $c), 1, $d, $t)
                    : $juntar($orfas, "TAREFAS|tarefas|{$motivo($t)}", ['tipo' => 'TAREFAS', 'unidade' => 'tarefas', 'motivo' => $motivo($t)], 1, $d, $t);
            }
        }
        $arrumar = fn (array $l) => array_merge($l, ['projetos' => array_values($l['projetos']), 'tarefas' => array_values($l['tarefas'])]);
        $saida['linhas'] = collect($linhas)->map($arrumar)->sortBy(fn ($l) => [$l['tipo'], $l['unidade'], mb_strtolower($l['nome'])])->values()->all();
        $saida['orfas'] = collect($orfas)->map($arrumar)->values()->all();

        return $saida;
    }

    /** Grupos a mapear para itens (grupos + sugerir, :161-180). @return list<array<string, mixed>> */
    public function grupos(array $colheita): array
    {
        $itens = ItemProdutividadeRH::query()->where('ativo', true)->orderBy('codigo')->get();
        $g = [];
        foreach ($colheita['linhas'] as $l) {
            $k = $l['tipo'].'|'.RegrasMetasProjetos::chaveUnidade($l['unidade']);
            $g[$k] ??= ['chave' => $k, 'tipo' => $l['tipo'], 'unidade' => $l['unidade'], 'colaboradores' => 0, 'quantidade' => 0.0];
            $g[$k]['colaboradores']++;
            $g[$k]['quantidade'] = RegrasMetasProjetos::r4($g[$k]['quantidade'] + $l['quantidade']);
        }

        return array_values(array_map(function ($x) use ($itens) {
            $cand = $itens->where('metrica', self::TIPOS[$x['tipo']]['metrica'])->values();
            $mesmaUn = $cand->first(fn ($i) => RegrasMetasProjetos::chaveUnidade($i->unidade) === RegrasMetasProjetos::chaveUnidade($x['unidade']));
            $sug = $x['tipo'] === 'QUANTIDADE' ? $mesmaUn : ($cand->count() === 1 ? $cand->first() : $mesmaUn);

            return $x + ['item_sugerido_id' => $sug?->id, 'candidatos' => $cand->map(fn ($i) => ['id' => $i->id, 'codigo' => $i->codigo, 'descricao' => $i->descricao, 'unidade' => $i->unidade])->all()];
        }, $g));
    }

    /** Chave de idempotência (PRJ|tipo|unidade|colaborador|item|período), com a unidade resumida se não couber em 50. */
    public static function chave(string $tipo, string $unidade, int $colaborador, int $item, int $periodo): string
    {
        $un = RegrasMetasProjetos::chaveUnidade($unidade);
        $k = "PRJ|{$tipo}|{$un}|{$colaborador}|{$item}|{$periodo}";

        return strlen($k) <= 50 ? $k : "PRJ|{$tipo}|".substr(md5($un), 0, 8)."|{$colaborador}|{$item}|{$periodo}";
    }

    /**
     * Análise contra o período e os contratos (analisar, :187-224): nada é gravado aqui.
     *
     * @param  array<string, int|null>  $mapa  'TIPO|unidade' => item_id
     * @return array{periodo: array<string, mixed>, validas: list<array<string, mixed>>, erros: list<array<string, mixed>>, sem_item: list<array<string, mixed>>, orfas: list<array<string, mixed>>, total: float}
     */
    public function analisar(PeriodoProdutividadeRH $p, string $de, string $ate, array $mapa, ?array $projetoIds = null): array
    {
        if ($p->estado !== 'ABERTO') {
            throw new ErroNegocio("A produtividade de {$p->mes} está fechada. Reabra-a para importar a execução dos projectos.", 'PERIODO_FECHADO', 422);
        }
        $colheita = $this->recolher($de, $ate, $projetoIds);
        $eleg = $this->produtividade->elegiveis($p);
        $itens = ItemProdutividadeRH::query()->get()->keyBy('id');
        $actuais = RegistoProdutividadeRH::query()->where('periodo_produtividade_id', $p->id)->get();
        $porChave = $actuais->whereNotNull('origem_chave')->keyBy('origem_chave');
        $hoje = now()->toDateString();
        $ini = $p->data_inicio->toDateString();
        $fim = $p->data_fim->toDateString();
        $out = ['periodo' => $p->only(['id', 'mes', 'estado']) + ['data_inicio' => $ini, 'data_fim' => $fim], 'validas' => [], 'erros' => [], 'sem_item' => [],
            'orfas' => $colheita['orfas'], 'total' => 0.0];
        foreach ($colheita['linhas'] as $l) {
            $itemId = $mapa[$l['tipo'].'|'.RegrasMetasProjetos::chaveUnidade($l['unidade'])] ?? null;
            $item = $itemId ? ($itens[(int) $itemId] ?? null) : null;
            if (! $item) {
                $out['sem_item'][] = $l;

                continue;
            }
            $data = min($l['ultima'] ?: $colheita['ate'], $hoje, $fim);
            $data = max($data, $ini);
            $chave = self::chave($l['tipo'], $l['unidade'], (int) $l['colaborador_id'], $item->id, $p->id);
            $existente = $porChave[$chave] ?? null;
            $base = ['linha' => $l, 'item' => ['id' => $item->id, 'codigo' => $item->codigo, 'descricao' => $item->descricao, 'unidade' => $item->unidade]];
            $erro = match (true) {
                $item->metrica !== self::TIPOS[$l['tipo']]['metrica'] => "O item {$item->codigo} mede ".mb_strtolower((string) $item->metrica).', não '.mb_strtolower(self::TIPOS[$l['tipo']]['nome']).'.',
                ! ($item->ativo ?? true) => "O item {$item->codigo} está inactivo.",
                ! isset($eleg[$l['colaborador_id']]) => 'O colaborador não é elegível: tem de estar ACTIVO e ter o item no contrato.',
                ! isset($eleg[$l['colaborador_id']]['itens'][$item->id]) => "O item {$item->codigo} não está no contrato do colaborador.",
                $item->metrica === 'OBJECTIVO' && $l['quantidade'] > 1000, $l['quantidade'] < 0 => 'Quantidade inválida.',
                ! $existente && $actuais->contains(fn ($r) => (int) $r->colaborador_id === (int) $l['colaborador_id'] && (int) $r->item_produtividade_id === $item->id
                    && $r->data?->toDateString() === $data) => 'Já existe um registo deste item para o colaborador nessa data (introduzido à mão).',
                default => null,
            };
            if ($erro) {
                $out['erros'][] = $base + ['erro' => $erro];

                continue;
            }
            $preco = $existente ? (float) $existente->preco_unitario : (float) $eleg[$l['colaborador_id']]['itens'][$item->id]['preco'];
            $valor = RegrasMetasProjetos::r2($l['quantidade'] * $preco);
            $out['validas'][] = $base + ['chave' => $chave, 'data' => $data, 'preco_unitario' => $preco, 'valor' => $valor, 'registo_existente_id' => $existente?->id,
                'observacoes' => "Execução de projectos: {$l['registos']} registo(s) em ".count($l['projetos'])." projecto(s), {$colheita['de']} a {$colheita['ate']}.",
                'fonte' => ['origem' => 'PROJECTOS', 'tipo' => $l['tipo'], 'unidade' => $l['unidade'], 'de' => $colheita['de'], 'ate' => $colheita['ate'],
                    'projetos' => $l['projetos'], 'tarefas' => array_slice($l['tarefas'], 0, 200), 'registos' => $l['registos']]];
            $out['total'] = RegrasMetasProjetos::r2($out['total'] + $valor);
        }

        return $out;
    }

    /**
     * Envio para o período (enviar, :231-244): cria ou actualiza um registo por chave, numa só transacção.
     *
     * @return array{criados: int, actualizados: int, total: float, erros: int, sem_item: int}
     */
    public function enviar(PeriodoProdutividadeRH $p, string $de, string $ate, array $mapa, ?array $projetoIds = null): array
    {
        return DB::transaction(function () use ($p, $de, $ate, $mapa, $projetoIds) {
            $p = PeriodoProdutividadeRH::query()->lockForUpdate()->findOrFail($p->id);
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ["produtividade_projetos:{$p->id}"]);
            $a = $this->analisar($p, $de, $ate, $mapa, $projetoIds);
            if (! $a['validas']) {
                throw new ErroNegocio('Não há nada para enviar.', 'NADA_A_ENVIAR', 422, ['erros' => count($a['erros']), 'sem_item' => count($a['sem_item'])]);
            }
            $criados = 0;
            $actualizados = 0;
            foreach ($a['validas'] as $v) {
                $existente = $v['registo_existente_id'] ? RegistoProdutividadeRH::query()->find($v['registo_existente_id']) : null;
                $d = ['colaborador_id' => $v['linha']['colaborador_id'], 'item_produtividade_id' => $v['item']['id'], 'quantidade' => $v['linha']['quantidade'],
                    'data' => $v['data'], 'observacoes' => $v['observacoes']];
                $r = $this->produtividade->gravarRegisto($p, $d, $existente, self::ORIGEM);
                $r->update(['origem_chave' => $v['chave'], 'fonte' => $v['fonte'], 'atualizado_por' => Auth::user()?->nome_utilizador]);
                $existente ? $actualizados++ : $criados++;
            }
            // a auditoria fica nos próprios registos (Auditavel), com origem PROJECTO e a fonte

            return ['criados' => $criados, 'actualizados' => $actualizados, 'total' => $a['total'], 'erros' => count($a['erros']), 'sem_item' => count($a['sem_item'])];
        });
    }
}
