<?php

namespace App\Services\Projetos;

use App\Exceptions\ErroNegocio;
use App\Models\LinhaOrcamentoProjeto;
use App\Models\MarcoProjeto;
use App\Models\NoOrganigramaProjeto;
use App\Models\Projeto;
use App\Models\TarefaProjeto;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Orçamento mapeado pelas quantidades (Versão Actual, 2026-10-11): js/projectos_orcamento_qtd.js.
 * Uma rubrica do orçamento base pode ser mapeada, numa ou em várias partes, sobre o trabalho medido: unidade, âmbito
 * (todas as tarefas da unidade, certas fases ou tarefas escolhidas), base (META ou EXECUTADO) e valor. Daí sai um preço
 * por unidade e o valor reparte-se pelas tarefas e, através delas, pelas fases, áreas do organigrama e pessoas.
 * O valor da rubrica não muda: é uma leitura do mesmo dinheiro, repartido.
 * Mantém do legado:
 *   - linhas_orcamento_projeto.mapas_quantidades = [{unidade, escopo: {tipo, ids}, valor, base, preco_unitario,
 *     quantidade_mapeada, criado_em, criado_por}]; o formato antigo `mapa_quantidades` (um objecto) continua a ser lido e
 *     sai na primeira gravação (:13-21, 70-71, 309-311);
 *   - só se mapeia o saldo por mapear = montante − Σ valores; uma rubrica inteiramente mapeada não aceita mais (:344-349);
 *   - preço = valor ÷ Σ qtd das tarefas do âmbito na unidade, com qtd = meta ou min(realizado, meta); valor(tarefa) =
 *     qtd × preço e o resto do arredondamento vai para a maior linha (:76-95);
 *   - por área: partes iguais pelas áreas da tarefa; por pessoa: o responsável directo leva tudo; sem ele, reparte pelos
 *     membros da área na proporção das horas alocadas ou em partes iguais (:113-144);
 *   - retirar um mapeamento devolve o valor ao saldo (:323-339).
 * Correcções:
 *   - validação no servidor (permissão `proj_orcamento_qtd`, âmbito com pelo menos uma fase/tarefa do projecto, valor > 0
 *     e ≤ saldo), com a linha bloqueada para dois mapeamentos simultâneos não passarem o montante;
 *   - baixar o montante de uma rubrica abaixo do já mapeado é recusado (ServicoOrcamentoProjetos::guardarLinha);
 *   - o mapeamento é recusado quando o âmbito não tem quantidade nenhuma (o legado gravava um preço 0).
 */
final class ServicoOrcamentoQuantidadesProjetos
{
    public const BASES = ['META' => 'Quantidade prevista (meta)', 'EXECUTADO' => 'Quantidade já executada'];

    public const ESCOPOS = ['UNIDADE', 'FASE', 'TAREFAS'];

    public function __construct(
        private readonly ServicoProjetos $projetos,
        private readonly ServicoEquipasProjetos $equipas,
    ) {}

    /** Mapeamentos da linha (lê o formato antigo de um só objecto). @return list<array<string, mixed>> */
    public static function mapasDe(LinhaOrcamentoProjeto $l): array
    {
        if (is_array($l->mapas_quantidades)) {
            return array_values(array_filter($l->mapas_quantidades, fn ($m) => is_array($m) && ! empty($m['unidade'])));
        }
        $antigo = $l->mapa_quantidades;

        return is_array($antigo) && ! empty($antigo['unidade']) ? [$antigo] : [];
    }

    public static function valorMapeado(LinhaOrcamentoProjeto $l): float
    {
        return RegrasMetasProjetos::r2(array_sum(array_map(fn ($m) => (float) ($m['valor'] ?? 0), self::mapasDe($l))));
    }

    public static function saldo(LinhaOrcamentoProjeto $l): float
    {
        return RegrasMetasProjetos::r2((float) $l->montante - self::valorMapeado($l));
    }

    private static function qtd(TarefaProjeto $t, string $base): float
    {
        return $base === 'EXECUTADO' ? min((float) $t->meta_realizado, (float) $t->meta_quantidade) : (float) $t->meta_quantidade;
    }

    /** Tarefas do âmbito na unidade (noEscopo, :51-63). */
    private static function noEscopo(Collection $medidas, array $mapa): Collection
    {
        $esc = $mapa['escopo'] ?? ['tipo' => 'UNIDADE'];
        $ids = array_map('intval', (array) ($esc['ids'] ?? []));
        $lista = match ($esc['tipo'] ?? 'UNIDADE') {
            'TAREFAS' => $medidas->filter(fn ($t) => in_array($t->id, $ids, true)),
            'FASE' => $medidas->filter(fn ($t) => in_array((int) $t->marco_projeto_id, $ids, true)),
            default => $medidas,
        };

        return $lista->filter(fn ($t) => $t->unidadeMeta() === (string) $mapa['unidade'])->values();
    }

    /**
     * Distribuição de um mapeamento pelas tarefas (distribuirMapa, :76-95).
     *
     * @return array{unidade: string, base: string, quantidade: float, valor: float, preco_unitario: float, linhas: list<array{tarefa: TarefaProjeto, quantidade: float, valor: float}>}
     */
    public static function distribuir(array $mapa, Collection $medidas): array
    {
        $base = ($mapa['base'] ?? 'META') === 'EXECUTADO' ? 'EXECUTADO' : 'META';
        $alvo = self::noEscopo($medidas, $mapa);
        $total = RegrasMetasProjetos::r4($alvo->sum(fn ($t) => self::qtd($t, $base)));
        $valor = RegrasMetasProjetos::r2($mapa['valor'] ?? 0);
        $preco = $total > 0 ? RegrasMetasProjetos::r4($valor / $total) : 0.0;
        $linhas = $alvo->map(fn ($t) => ['tarefa' => $t, 'quantidade' => RegrasMetasProjetos::r4(self::qtd($t, $base)), 'valor' => RegrasMetasProjetos::r2(self::qtd($t, $base) * $preco)])
            ->filter(fn ($x) => $x['quantidade'] > 0)->values()->all();
        $soma = RegrasMetasProjetos::r2(array_sum(array_column($linhas, 'valor')));
        if ($linhas && abs($soma - $valor) >= 0.01) {
            $maior = 0;
            foreach ($linhas as $i => $x) {
                if ($x['valor'] > $linhas[$maior]['valor']) {
                    $maior = $i;
                }
            }
            $linhas[$maior]['valor'] = RegrasMetasProjetos::r2($linhas[$maior]['valor'] + ($valor - $soma));
        }

        return ['unidade' => (string) $mapa['unidade'], 'base' => $base, 'quantidade' => $total, 'valor' => $valor, 'preco_unitario' => $preco, 'linhas' => $linhas];
    }

    /** Valor mapeado por tarefa somando todos os mapeamentos das linhas (distribuirLinhas, :100-109). @return array<int, float> */
    public static function valorPorTarefa(Collection $linhas, Collection $tarefas): array
    {
        $medidas = RegrasMetasProjetos::medidas($tarefas);
        $saida = [];
        foreach ($linhas as $l) {
            foreach (self::mapasDe($l) as $m) {
                foreach (self::distribuir($m, $medidas)['linhas'] as $x) {
                    $saida[$x['tarefa']->id] = RegrasMetasProjetos::r2(($saida[$x['tarefa']->id] ?? 0) + $x['valor']);
                }
            }
        }

        return $saida;
    }

    // ───────────── Consulta ─────────────

    /**
     * Estado do mapeamento por rubrica e a «Repartição do orçamento mapeado» (orcDistribuicaoQtd, :372-480):
     * por tarefa, fase, área do organigrama e pessoa.
     */
    public function resumo(Projeto $p): array
    {
        $linhas = LinhaOrcamentoProjeto::query()->where('projeto_id', $p->id)->orderBy('id')->get();
        $tarefas = TarefaProjeto::query()->where('projeto_id', $p->id)->get();
        $medidas = RegrasMetasProjetos::medidas($tarefas);
        $fases = MarcoProjeto::query()->where('projeto_id', $p->id)->pluck('nome', 'id');
        $nos = NoOrganigramaProjeto::query()->where('projeto_id', $p->id)->get();
        $membros = collect($this->equipas->membros($p))->keyBy('id');
        $porTarefa = [];
        $rubricas = [];
        foreach ($linhas as $l) {
            $mapas = self::mapasDe($l);
            $rubricas[] = ['linha_id' => $l->id, 'rubrica' => $l->rubrica, 'montante' => (float) $l->montante, 'mapeado' => self::valorMapeado($l), 'saldo' => self::saldo($l),
                'pct_mapeado' => (float) $l->montante > 0 ? (int) round(self::valorMapeado($l) / (float) $l->montante * 100) : 0,
                'totalmente_mapeada' => $mapas && self::saldo($l) < 0.01,
                'mapas' => array_map(fn ($m, $i) => ['indice' => $i] + $m, $mapas, array_keys($mapas))];
            foreach ($mapas as $m) {
                foreach (self::distribuir($m, $medidas)['linhas'] as $x) {
                    $id = $x['tarefa']->id;
                    $porTarefa[$id] ??= ['tarefa' => $x['tarefa'], 'valor' => 0.0, 'quantidade' => 0.0];
                    $porTarefa[$id]['valor'] = RegrasMetasProjetos::r2($porTarefa[$id]['valor'] + $x['valor']);
                    $porTarefa[$id]['quantidade'] = RegrasMetasProjetos::r4($porTarefa[$id]['quantidade'] + $x['quantidade']);
                }
            }
        }
        $fase = [];
        $area = [];
        $pessoa = [];
        $juntar = function (array &$mapa, string $k, string $nome, float $v, float $q) {
            $mapa[$k] ??= ['nome' => $nome, 'valor' => 0.0, 'quantidade' => 0.0];
            $mapa[$k]['valor'] = RegrasMetasProjetos::r2($mapa[$k]['valor'] + $v);
            $mapa[$k]['quantidade'] = RegrasMetasProjetos::r4($mapa[$k]['quantidade'] + $q);
        };
        foreach ($porTarefa as ['tarefa' => $t, 'valor' => $v, 'quantidade' => $q]) {
            $juntar($fase, (string) ($t->marco_projeto_id ?: 'sem'), $fases[$t->marco_projeto_id] ?? 'Sem fase', $v, $q);
            $areas = $nos->filter(fn ($n) => in_array($t->id, array_map('intval', $n->tarefas ?? []), true))->values();
            if ($areas->isEmpty()) {
                $juntar($area, 'sem', 'Sem área do organigrama', $v, $q);
            }
            foreach ($areas as $n) {
                $juntar($area, (string) $n->id, $n->titulo ?: ($n->area ?: "Posição {$n->id}"), RegrasMetasProjetos::r2($v / $areas->count()), RegrasMetasProjetos::r4($q / $areas->count()));
            }
            $directo = $t->atribuido_a_id ? ($membros[$t->atribuido_a_id] ?? null) : null;
            if ($directo) {
                $juntar($pessoa, (string) $directo['id'], $directo['nome'], $v, $q);

                continue;
            }
            $gente = $membros->filter(fn ($m) => $areas->contains('id', (int) $m['no_organigrama_projeto_id']))->values();
            if ($gente->isEmpty()) {
                $juntar($pessoa, 'sem', 'Sem responsável', $v, $q);

                continue;
            }
            $pesos = $gente->map(fn ($m) => max(0.0, (float) $m['horas_alocadas']));
            $somaP = $pesos->sum();
            foreach ($gente as $i => $m) {
                $f = $somaP > 0 ? $pesos[$i] / $somaP : 1 / $gente->count();
                $juntar($pessoa, (string) $m['id'], $m['nome'], RegrasMetasProjetos::r2($v * $f), RegrasMetasProjetos::r4($q * $f));
            }
        }
        $ordenar = fn (array $m) => collect($m)->sortByDesc('valor')->values()->all();

        return [
            'unidades' => $medidas->map(fn ($t) => $t->unidadeMeta())->unique()->sort()->values()->all(),
            'rubricas' => $rubricas,
            'por_tarefa' => collect($porTarefa)->map(fn ($x) => ['tarefa_id' => $x['tarefa']->id, 'codigo' => $x['tarefa']->codigo, 'nome' => $x['tarefa']->nome,
                'unidade' => $x['tarefa']->unidadeMeta(), 'quantidade' => $x['quantidade'], 'valor' => $x['valor']])->sortByDesc('valor')->values()->all(),
            'por_fase' => $ordenar($fase), 'por_area' => $ordenar($area), 'por_pessoa' => $ordenar($pessoa),
            'total_mapeado' => RegrasMetasProjetos::r2(array_sum(array_column($rubricas, 'mapeado'))),
        ];
    }

    /** Previsão do mapeamento sem gravar (prever, :267-293). */
    public function prever(Projeto $p, LinhaOrcamentoProjeto $l, array $d): array
    {
        $mapa = $this->normalizar($p, $d);
        $dist = self::distribuir($mapa, RegrasMetasProjetos::medidas(TarefaProjeto::query()->where('projeto_id', $p->id)->get()));

        return self::serializar($dist) + ['saldo' => self::saldo($l), 'sobra' => RegrasMetasProjetos::r2(self::saldo($l) - $mapa['valor'])];
    }

    // ───────────── Gravação ─────────────

    /**
     * Mapeia parte do saldo da rubrica (orcMapearQuantidade, «ok», :341-365).
     *
     * @param  array{unidade: string, base?: string, escopo?: array{tipo: string, ids?: list<int>}, valor: mixed}  $d
     */
    public function mapear(Projeto $p, int $linhaId, array $d): array
    {
        return DB::transaction(function () use ($p, $linhaId, $d) {
            $l = LinhaOrcamentoProjeto::query()->where('projeto_id', $p->id)->lockForUpdate()->findOrFail($linhaId);
            $mapa = $this->normalizar($p, $d);
            $saldo = self::saldo($l);
            if ($mapa['valor'] - $saldo > 0.009) {
                throw new ErroNegocio($saldo > 0
                    ? 'Só pode mapear até '.number_format($saldo, 2, ',', ' ').' Kz — é o que falta nesta rubrica. Para mapear mais, retire antes um dos mapeamentos.'
                    : 'Esta rubrica já está inteiramente mapeada. Retire um dos mapeamentos para libertar saldo.', 'SALDO_INSUFICIENTE', 422, ['saldo' => $saldo]);
            }
            $dist = self::distribuir($mapa, RegrasMetasProjetos::medidas(TarefaProjeto::query()->where('projeto_id', $p->id)->get()));
            if (! $dist['linhas']) {
                throw new ErroNegocio('Nenhuma tarefa com quantidade neste âmbito: nada a repartir.', 'AMBITO_SEM_QUANTIDADE', 422);
            }
            $mapas = [...self::mapasDe($l), $mapa + ['preco_unitario' => $dist['preco_unitario'], 'quantidade_mapeada' => $dist['quantidade'],
                'criado_em' => now()->toIso8601String(), 'criado_por' => (string) Auth::user()?->nome_utilizador]];
            $l->update(['mapas_quantidades' => $mapas, 'mapa_quantidades' => null]);
            $fmt = fn ($v) => number_format((float) $v, 2, ',', ' ');
            $this->projetos->registar($p->id, 'Mapear orçamento por quantidade', "{$l->rubrica}: {$fmt($mapa['valor'])} Kz sobre {$dist['quantidade']} {$dist['unidade']} ({$fmt($dist['preco_unitario'])} Kz/{$dist['unidade']})");

            return self::serializar($dist) + ['saldo' => self::saldo($l->refresh())];
        });
    }

    /** Retira um mapeamento; o valor volta ao saldo (:323-339). */
    public function retirar(Projeto $p, int $linhaId, int $indice): array
    {
        return DB::transaction(function () use ($p, $linhaId, $indice) {
            $l = LinhaOrcamentoProjeto::query()->where('projeto_id', $p->id)->lockForUpdate()->findOrFail($linhaId);
            $mapas = self::mapasDe($l);
            $m = $mapas[$indice] ?? throw new ErroNegocio('Mapeamento inexistente.', 'MAPEAMENTO_INEXISTENTE', 422);
            array_splice($mapas, $indice, 1);
            $l->update(['mapas_quantidades' => $mapas, 'mapa_quantidades' => null]);
            $this->projetos->registar($p->id, 'Retirar mapeamento por quantidade', "{$l->rubrica}: ".number_format((float) $m['valor'], 2, ',', ' ').' Kz devolvidos ao saldo');

            return ['saldo' => self::saldo($l->refresh()), 'mapas' => count($mapas)];
        });
    }

    /** Valida e normaliza o pedido de mapeamento. */
    private function normalizar(Projeto $p, array $d): array
    {
        $unidade = trim((string) ($d['unidade'] ?? ''));
        if ($unidade === '') {
            throw new ErroNegocio('Indique a unidade de medida.', 'META_SEM_UNIDADE', 422);
        }
        $base = strtoupper((string) ($d['base'] ?? 'META'));
        if (! isset(self::BASES[$base])) {
            throw new ErroNegocio('Base inválida (META ou EXECUTADO).', 'BASE_INVALIDA', 422);
        }
        $tipo = strtoupper((string) ($d['escopo']['tipo'] ?? 'UNIDADE'));
        if (! in_array($tipo, self::ESCOPOS, true)) {
            throw new ErroNegocio('Âmbito inválido (UNIDADE, FASE ou TAREFAS).', 'AMBITO_INVALIDO', 422);
        }
        $ids = array_values(array_unique(array_map('intval', (array) ($d['escopo']['ids'] ?? []))));
        if ($tipo !== 'UNIDADE') {
            if (! $ids) {
                throw new ErroNegocio('Escolha pelo menos uma fase ou tarefa.', 'AMBITO_INVALIDO', 422);
            }
            $existentes = ($tipo === 'FASE' ? MarcoProjeto::query() : TarefaProjeto::query())->where('projeto_id', $p->id)->whereIn('id', $ids)->count();
            if ($existentes !== count($ids)) {
                throw new ErroNegocio('Há fases ou tarefas do âmbito que não pertencem ao projecto.', 'AMBITO_INVALIDO', 422);
            }
        }
        $valor = RegrasMetasProjetos::r2($d['valor'] ?? 0);
        if (! ($valor > 0)) {
            throw new ErroNegocio('Indique o valor a mapear.', 'VALOR_INVALIDO', 422);
        }

        return ['unidade' => mb_substr($unidade, 0, 20), 'base' => $base, 'escopo' => ['tipo' => $tipo, 'ids' => $tipo === 'UNIDADE' ? [] : $ids], 'valor' => $valor];
    }

    private static function serializar(array $dist): array
    {
        return ['unidade' => $dist['unidade'], 'base' => $dist['base'], 'quantidade' => $dist['quantidade'], 'valor' => $dist['valor'], 'preco_unitario' => $dist['preco_unitario'],
            'linhas' => array_map(fn ($x) => ['tarefa_id' => $x['tarefa']->id, 'codigo' => $x['tarefa']->codigo, 'nome' => $x['tarefa']->nome,
                'quantidade' => $x['quantidade'], 'valor' => $x['valor']], $dist['linhas'])];
    }
}
