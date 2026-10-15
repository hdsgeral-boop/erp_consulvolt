<?php

namespace App\Services\Projetos;

use App\Exceptions\ErroNegocio;
use App\Models\TarefaProjeto;
use DateTimeImmutable;
use Illuminate\Support\Collection;

/**
 * Regras puras das metas por quantidade e do calendário de trabalho do projecto (Versão Actual, 2026-10-11):
 * js/projectos_metas.js. Sem acesso à base de dados — servem o planeamento, o mapa de quantidades, a carga de trabalho,
 * o orçamento mapeado e a ponte para a produtividade, para os números baterem certo entre ecrãs.
 * Mantém do legado:
 *   - calendário `{horas_dia, dias: {0..6: 0 | 0,5 | 1}}`, 0 = domingo; por omissão segunda a sexta, 8 h/dia (:134-152);
 *   - dias úteis = Σ factor do dia da semana; horas previstas = dias úteis × horas/dia (:167-183);
 *   - colunas da escala alinhadas à unidade: semana à segunda, trimestre em Jan/Abr/Jul/Out, semestre em Jan/Jul (:200-242);
 *   - repartição da meta pelos dias de trabalho de cada coluna, com arredondamento cumulativo; sem dias úteis no
 *     prazo, reparte pelos dias de calendário (:249-273);
 *   - ritmo exigido: por dia = meta ÷ dias úteis; semana = dia × Σ factores; mês = semana × 4,33; hora = meta ÷ (dias × h) (:335-348);
 *   - soma de um ramo sem contar duas vezes o pai que tem filhos com meta (somaRamo, :69-84).
 * Correcções:
 *   - o calendário é validado no servidor: horas por dia entre 0,5 e 24, factores 0, 0,5 ou 1 e pelo menos um dia de
 *     trabalho (o legado só o garantia no formulário);
 *   - a enumeração de dias tem um limite de 4 000 iterações, como no legado, mas devolve erro em vez de cortar em silêncio
 *     quando a janela do mapa é maior.
 */
final class RegrasMetasProjetos
{
    public const UNIDADES = ['un', 'm', 'm²', 'm³', 'kg', 'ton', 'km', 'L', 'h', 'dia', 'visita', 'ensaio', 'lote', 'peça', '%'];

    public const CAL_DEFEITO = ['horas_dia' => 8.0, 'dias' => [0 => 0.0, 1 => 1.0, 2 => 1.0, 3 => 1.0, 4 => 1.0, 5 => 1.0, 6 => 0.0]];

    public const MODELOS_CAL = [
        'SEG_SEX' => ['nome' => 'Segunda a sexta', 'dias' => [0 => 0, 1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 0]],
        'SEG_SEX_SAB12' => ['nome' => 'Segunda a sexta + meio sábado', 'dias' => [0 => 0, 1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 0.5]],
        'SEG_SAB' => ['nome' => 'Segunda a sábado', 'dias' => [0 => 0, 1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 1]],
        'TODOS' => ['nome' => 'Todos os dias', 'dias' => [0 => 1, 1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 1]],
    ];

    public const ESCALAS = ['dia', 'semana', 'mes', 'trimestre', 'semestre', 'ano'];

    private const MESES = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];

    private const LIMITE_DIAS = 4000;

    public static function r2(float|int|string|null $v): float
    {
        return round((float) $v, 2);
    }

    public static function r4(float|int|string|null $v): float
    {
        return round((float) $v, 4);
    }

    // ───────────── Calendário ─────────────

    /**
     * Calendário efectivo do projecto (calendario, projectos_metas.js:143-152): dias em falta ou inválidos ficam com o
     * valor por omissão; horas por dia ≤ 0 passam a 8.
     *
     * @return array{horas_dia: float, dias: array<int, float>}
     */
    public static function calendario(?array $c): array
    {
        $dias = [];
        for ($d = 0; $d <= 6; $d++) {
            $v = isset($c['dias']) && is_array($c['dias']) && array_key_exists($d, $c['dias']) && is_numeric($c['dias'][$d]) ? (float) $c['dias'][$d] : self::CAL_DEFEITO['dias'][$d];
            $dias[$d] = max(0.0, min(1.0, $v));
        }
        $h = (float) ($c['horas_dia'] ?? 0);

        return ['horas_dia' => $h > 0 ? $h : self::CAL_DEFEITO['horas_dia'], 'dias' => $dias];
    }

    /**
     * Valida e normaliza o calendário a gravar (ui_projects.js:246-334, saveNewProject): horas/dia entre 0,5 e 24;
     * cada dia com 0, 0,5 ou 1; pelo menos um dia com factor > 0. Aceita `modelo` (SEG_SEX…) em vez dos dias.
     *
     * @return array{horas_dia: float, dias: array<string, float>}
     */
    public static function validarCalendario(array $d): array
    {
        $modelo = $d['modelo'] ?? null;
        if ($modelo !== null && $modelo !== 'PERSONALIZADO') {
            $dias = self::MODELOS_CAL[$modelo]['dias'] ?? throw new ErroNegocio('Modelo de calendário inválido.', 'CALENDARIO_INVALIDO', 422);
        } else {
            $dias = $d['dias'] ?? null;
            if (! is_array($dias)) {
                throw new ErroNegocio('Indique o factor de cada dia da semana (0, 0,5 ou 1).', 'CALENDARIO_INVALIDO', 422);
            }
        }
        $h = (float) ($d['horas_dia'] ?? 8);
        if ($h < 0.5 || $h > 24) {
            throw new ErroNegocio('As horas por dia têm de estar entre 0,5 e 24.', 'CALENDARIO_INVALIDO', 422);
        }
        $saida = [];
        for ($i = 0; $i <= 6; $i++) {
            $v = $dias[$i] ?? $dias[(string) $i] ?? 0;
            if (! is_numeric($v) || ! in_array((float) $v, [0.0, 0.5, 1.0], true)) {
                throw new ErroNegocio('Cada dia vale 0 (não se trabalha), 0,5 (meio dia) ou 1 (dia inteiro).', 'CALENDARIO_INVALIDO', 422);
            }
            $saida[(string) $i] = (float) $v;
        }
        if (array_sum($saida) <= 0) {
            throw new ErroNegocio('O calendário tem de ter pelo menos um dia de trabalho.', 'CALENDARIO_INVALIDO', 422);
        }

        return ['horas_dia' => round($h, 2), 'dias' => $saida];
    }

    /** Código do modelo quando os dias correspondem a um deles (modeloDe, :155-158). */
    public static function modeloDe(array $cal): ?string
    {
        foreach (self::MODELOS_CAL as $k => $m) {
            if (array_reduce(range(0, 6), fn ($ok, $d) => $ok && (float) $m['dias'][$d] === (float) $cal['dias'][$d], true)) {
                return $k;
            }
        }

        return null;
    }

    private static function data(?string $v): ?DateTimeImmutable
    {
        $v = substr(trim((string) $v), 0, 10);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);

        return $d ?: null;
    }

    public static function somarDias(string $data, int $n): string
    {
        $d = self::data($data) ?? throw new ErroNegocio('Data inválida.', 'DATA_INVALIDA', 422);

        return $d->modify(($n >= 0 ? '+' : '').$n.' days')->format('Y-m-d');
    }

    /** Dias de calendário entre duas datas, ambas inclusive (diasEntre, :121). */
    public static function diasEntre(?string $de, ?string $ate): int
    {
        $a = self::data($de);
        $b = self::data($ate);

        return $a && $b ? max(0, (int) $a->diff($b)->format('%r%a') + 1) : 0;
    }

    /** Peso de trabalho do intervalo: Σ factor de cada dia (diasUteis, :167-176). */
    public static function diasUteis(?string $de, ?string $ate, array $cal): float
    {
        $a = self::data($de);
        $b = self::data($ate);
        if (! $a || ! $b || $b < $a) {
            return 0.0;
        }
        $total = (int) $a->diff($b)->format('%a') + 1;
        $semanas = intdiv($total, 7);
        $soma = $semanas * array_sum($cal['dias']);
        $resto = $total % 7;
        $dow = (int) $a->format('w');
        for ($i = 0; $i < $resto; $i++) {
            $soma += (float) $cal['dias'][($dow + $i) % 7];
        }

        return round($soma, 2);
    }

    /** Horas previstas: dias úteis do prazo × horas/dia (horasPrevistas, :178-183). Sem início não há previsão. */
    public static function horasPrevistas(?string $inicio, ?string $fim, array $cal): float
    {
        $i = substr((string) $inicio, 0, 10);
        $f = substr((string) ($fim ?: $inicio), 0, 10);
        if ($i === '' || $f === '') {
            return 0.0;
        }

        return round(self::diasUteis($i, $f, $cal) * $cal['horas_dia'], 2);
    }

    // ───────────── Escala de tempo ─────────────

    /**
     * Colunas do calendário entre duas datas (inclusive), alinhadas à unidade (periodosDaEscala, :200-242).
     *
     * @return list<array{de: string, ate: string, rotulo: string, titulo: string, fds: bool}>
     */
    public static function periodosDaEscala(?string $de, ?string $ate, string $escala): array
    {
        $e = in_array($escala, self::ESCALAS, true) ? $escala : 'mes';
        $ini0 = self::data($de);
        $fim0 = self::data($ate);
        if (! $ini0 || ! $fim0 || $fim0 < $ini0) {
            return [];
        }
        $mes = (int) $ini0->format('n');
        $ano = (int) $ini0->format('Y');
        $ini = match ($e) {
            'dia' => $ini0,
            'semana' => $ini0->modify('-'.(((int) $ini0->format('w') + 6) % 7).' days'),
            'mes' => $ini0->setDate($ano, $mes, 1),
            'trimestre' => $ini0->setDate($ano, intdiv($mes - 1, 3) * 3 + 1, 1),
            'semestre' => $ini0->setDate($ano, $mes <= 6 ? 1 : 7, 1),
            'ano' => $ini0->setDate($ano, 1, 1),
        };
        $avancar = fn (DateTimeImmutable $d) => match ($e) {
            'dia' => $d->modify('+1 day'),
            'semana' => $d->modify('+7 days'),
            'mes' => $d->modify('first day of next month'),
            'trimestre' => $d->modify('first day of +3 months'),
            'semestre' => $d->modify('first day of +6 months'),
            'ano' => $d->setDate((int) $d->format('Y') + 1, 1, 1),
        };
        $saida = [];
        $guarda = 0;
        while ($ini <= $fim0) {
            if (++$guarda > self::LIMITE_DIAS) {
                throw new ErroNegocio('A janela tem colunas a mais para a escala escolhida: escolha uma escala maior ou um período mais curto.', 'JANELA_EXCESSIVA', 422);
            }
            $fim = $avancar($ini);
            $d1 = $ini < $ini0 ? $ini0 : $ini;
            $ultimo = $fim->modify('-1 day');
            $d2 = $ultimo > $fim0 ? $fim0 : $ultimo;
            if ($d2 >= $d1) {
                $m = (int) $ini->format('n');
                $y = $ini->format('Y');
                $saida[] = ['de' => $d1->format('Y-m-d'), 'ate' => $d2->format('Y-m-d'),
                    'rotulo' => match ($e) {
                        'dia' => (int) $ini->format('j') === 1 ? self::MESES[$m - 1] : $ini->format('j'),
                        'semana' => $ini->format('j').'/'.$m,
                        'mes' => self::MESES[$m - 1].($m === 1 ? " {$y}" : ''),
                        'trimestre' => 'T'.(intdiv($m - 1, 3) + 1)." {$y}",
                        'semestre' => 'S'.($m <= 6 ? 1 : 2)." {$y}",
                        'ano' => $y,
                    },
                    'titulo' => $d1->format('Y-m-d').' a '.$d2->format('Y-m-d'),
                    'fds' => $e === 'dia' && in_array((int) $ini->format('w'), [0, 6], true)];
            }
            $ini = $fim;
        }

        return $saida;
    }

    /**
     * Reparte a quantidade pelas colunas na proporção dos dias de trabalho do prazo (repartirNoTempo, :249-273).
     *
     * @param  list<array{de: string, ate: string}>  $colunas
     * @return array{por_coluna: list<float>, dentro: float, dias: float, por_calendario: bool}
     */
    public static function repartirNoTempo(float $q, ?string $inicio, ?string $fim, array $colunas, array $cal): array
    {
        $vazio = array_fill(0, count($colunas), 0.0);
        $ini = substr((string) $inicio, 0, 10);
        $f = substr((string) ($fim ?: $inicio), 0, 10);
        if (! ($q > 0) || $ini === '') {
            return ['por_coluna' => $vazio, 'dentro' => 0.0, 'dias' => 0.0, 'por_calendario' => false];
        }
        $uteis = self::diasUteis($ini, $f, $cal);
        $porCal = ! ($uteis > 0);
        $dias = $porCal ? (float) self::diasEntre($ini, $f) : $uteis;
        if (! $dias) {
            return ['por_coluna' => $vazio, 'dentro' => 0.0, 'dias' => 0.0, 'por_calendario' => $porCal];
        }
        $acumDias = 0.0;
        $acumQ = 0.0;
        $dentro = 0.0;
        $saida = [];
        foreach ($colunas as $c) {
            $d1 = max($c['de'], $ini);
            $d2 = min($c['ate'], $f);
            $peso = $d1 > $d2 ? 0 : ($porCal ? self::diasEntre($d1, $d2) : self::diasUteis($d1, $d2, $cal));
            $acumDias = self::r2($acumDias + $peso);
            $ate = self::r2($q * min(1, $acumDias / $dias));
            $v = self::r2($ate - $acumQ);
            $acumQ = $ate;
            $dentro = self::r2($dentro + $v);
            $saida[] = $v;
        }

        return ['por_coluna' => $saida, 'dentro' => $dentro, 'dias' => $dias, 'por_calendario' => $porCal];
    }

    /** @return array{por_coluna: list<float>, total: float, sem_prazo: bool} (horasPrevistasNoTempo, :276-292) */
    public static function horasPrevistasNoTempo(?string $inicio, ?string $fim, array $colunas, array $cal): array
    {
        $ini = substr((string) $inicio, 0, 10);
        $f = substr((string) ($fim ?: $inicio), 0, 10);
        if ($ini === '' || $f === '') {
            return ['por_coluna' => array_fill(0, count($colunas), 0.0), 'total' => 0.0, 'sem_prazo' => true];
        }
        $total = 0.0;
        $saida = [];
        foreach ($colunas as $c) {
            $d1 = max($c['de'], $ini);
            $d2 = min($c['ate'], $f);
            $v = $d1 > $d2 ? 0.0 : self::r2(self::diasUteis($d1, $d2, $cal) * $cal['horas_dia']);
            $total = self::r2($total + $v);
            $saida[] = $v;
        }

        return ['por_coluna' => $saida, 'total' => $total, 'sem_prazo' => false];
    }

    /** Quantidade medida em cada coluna, pelo histórico das execuções (realizadoNoTempo, :295-304). @return list<float> */
    public static function realizadoNoTempo(TarefaProjeto $t, array $colunas): array
    {
        $ex = $t->medicoes();

        return array_map(fn ($c) => self::r2(array_reduce($ex, function ($s, $x) use ($c) {
            $d = substr((string) ($x['data'] ?? ''), 0, 10);

            return $d !== '' && $d >= $c['de'] && $d <= $c['ate'] ? $s + (float) ($x['q'] ?? 0) : $s;
        }, 0.0)), $colunas);
    }

    /**
     * Ritmo exigido pela meta (ritmo, :335-348): null sem meta ou sem início.
     *
     * @return array{dias: float, corridos: int, uteis: float, horas: float, por_calendario: bool, dia: float, semana: float, mes: float, hora: float, unidade: string}|null
     */
    public static function ritmo(?float $meta, ?string $unidade, ?string $inicio, ?string $fim, array $cal): ?array
    {
        if (! ($meta > 0) || ! $inicio) {
            return null;
        }
        $ini = substr($inicio, 0, 10);
        $f = substr((string) ($fim ?: $inicio), 0, 10);
        $uteis = self::diasUteis($ini, $f, $cal);
        $corridos = self::diasEntre($ini, $f);
        $dias = $uteis > 0 ? $uteis : (float) $corridos;
        if (! $dias) {
            return null;
        }
        $semana = array_sum($cal['dias']) ?: 7;

        return ['dias' => $dias, 'corridos' => $corridos, 'uteis' => $uteis, 'horas' => self::r2($dias * $cal['horas_dia']), 'por_calendario' => ! ($uteis > 0),
            'dia' => self::r2($meta / $dias), 'semana' => self::r2($meta / $dias * $semana), 'mes' => self::r2($meta / $dias * $semana * 4.33),
            'hora' => $cal['horas_dia'] > 0 ? self::r2($meta / ($dias * $cal['horas_dia'])) : 0.0, 'unidade' => trim((string) $unidade) ?: 'un'];
    }

    // ───────────── Árvore e metas ─────────────

    /**
     * Soma das metas de um conjunto de ramos (somaRamo, :69-84): usa a meta da tarefa e, quando os filhos têm meta,
     * a deles (o mesmo trabalho não conta duas vezes).
     *
     * @param  Collection<int, Collection<int, TarefaProjeto>>  $filhos  tarefas agrupadas por tarefa_pai_id
     * @param  iterable<TarefaProjeto>  $lista
     * @return array{meta: float, feito: float, unidades: list<string>, unidade: string, houve: bool}
     */
    public static function somaRamo(Collection $filhos, iterable $lista, array $pilha = []): array
    {
        $meta = 0.0;
        $feito = 0.0;
        $uns = [];
        $houve = false;
        foreach ($lista as $t) {
            if (isset($pilha[$t->id])) {
                continue;
            }
            $f = $filhos[$t->id] ?? collect();
            $s = $f->isNotEmpty() ? self::somaRamo($filhos, $f, $pilha + [$t->id => true]) : null;
            if ($s && $s['houve']) {
                $meta += $s['meta'];
                $feito += $s['feito'];
                $uns = array_merge($uns, $s['unidades']);
                $houve = true;

                continue;
            }
            if ($t->temMeta()) {
                $meta += (float) $t->meta_quantidade;
                $feito += min((float) $t->meta_realizado, (float) $t->meta_quantidade);
                $uns[] = $t->unidadeMeta();
                $houve = true;
            }
        }
        $uns = array_values(array_unique($uns));

        return ['meta' => self::r2($meta), 'feito' => self::r2($feito), 'unidades' => $uns, 'unidade' => count($uns) === 1 ? $uns[0] : '', 'houve' => $houve];
    }

    /**
     * O que mostrar na linha de uma tarefa (metaDoRamo, :88-96): com subtarefas com meta, a quantidade sobe delas;
     * senão a meta da própria tarefa, mesmo acima do realizado.
     *
     * @return array{meta: float, feito: float, unidade: string, dos_filhos: bool}|null
     */
    public static function metaDoRamo(Collection $filhos, TarefaProjeto $t): ?array
    {
        $f = $filhos[$t->id] ?? collect();
        if ($f->isNotEmpty()) {
            $s = self::somaRamo($filhos, $f, [$t->id => true]);
            if ($s['houve']) {
                return ['meta' => $s['meta'], 'feito' => $s['feito'], 'unidade' => $s['unidade'] ?: $t->unidadeMeta(), 'dos_filhos' => true];
            }
        }

        return $t->temMeta() ? ['meta' => (float) $t->meta_quantidade, 'feito' => (float) $t->meta_realizado, 'unidade' => $t->unidadeMeta(), 'dos_filhos' => false] : null;
    }

    /** Tarefas que se medem: com meta própria e sem subtarefas com meta (medidasDe, projectos_orcamento_qtd.js:43-48). */
    public static function medidas(Collection $tarefas): Collection
    {
        $comFilhoComMeta = $tarefas->filter(fn ($t) => $t->tarefa_pai_id && $t->temMeta())->pluck('tarefa_pai_id')->map(fn ($v) => (int) $v)->flip();

        return $tarefas->filter(fn ($t) => $t->temMeta() && ! isset($comFilhoComMeta[$t->id]))->values();
    }

    /**
     * Unidade comparável (chaveUn, produtividade_projectos.js:39-45): sem acentos, minúsculas, sem espaços e com os
     * sinónimos habituais (m² = m2 = metros quadrados).
     */
    public static function chaveUnidade(?string $u): string
    {
        $sin = ['m2' => 'm2', 'm²' => 'm2', 'metrosquadrados' => 'm2', 'm3' => 'm3', 'm³' => 'm3', 'metroscubicos' => 'm3',
            'm' => 'metros', 'metro' => 'metros', 'metros' => 'metros', 'un' => 'unidades', 'unidade' => 'unidades', 'unidades' => 'unidades',
            'pecas' => 'pecas', 'peca' => 'pecas', 'h' => 'horas', 'hora' => 'horas', 'horas' => 'horas', 'ton' => 'toneladas', 'tonelada' => 'toneladas'];
        $bruto = mb_strtolower(trim((string) $u));
        $s = strtr($bruto, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ç' => 'c', '²' => '2', '³' => '3']);
        $s = preg_replace('/[^a-z0-9%]/', '', $s);

        return $sin[$bruto] ?? $sin[$s] ?? $s;
    }
}
