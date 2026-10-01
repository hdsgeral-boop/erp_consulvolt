<?php

namespace App\Services\Gestao\Fluxos;

use App\Exceptions\ErroNegocio;
use App\Services\Gestao\Fluxos\Avaliadores\AvaliadorFluxo;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Fluxo de Processos (js/fluxo_processos.js, fluxo_tabela.js, fluxo_narrativa.js e os fluxos por módulo). Para cada fluxo:
 *   - os processos com as etapas avaliadas a partir dos estados reais dos documentos (avaliadores em Avaliadores/);
 *   - por processo, como o componente de volume do legado (fluxo_tabela.js:85-95): etapas concluídas, etapa actual (a primeira
 *     não concluída), bloqueio, n.º de erros e de avisos;
 *   - o funil: por etapa, os processos parados nela (etapa actual), o valor e o valor pendente, quantos estão bloqueados ou
 *     com pendências e a contagem dos estados da etapa em todos os processos; mais o grupo «concluídos»;
 *   - os indicadores do topo do fluxo (os do legado para cada fluxo) e a narrativa.
 * A ordem por omissão é a do legado (ordenarPadrao, fluxo_tabela.js:71-73): mais erros primeiro, depois com avisos, os por
 * concluir (mais antigos primeiro) antes dos concluídos (mais recentes primeiro).
 * O ecrã do legado mostrava só os 24 por concluir + concluídos até 12 nos fluxos em cartões; a API devolve todos, paginados.
 */
final class ServicoFluxos
{
    public const ESTADOS = ['concluida', 'curso', 'fazer', 'bloqueada'];

    /** @var array<string, array<string, mixed>> memória por pedido: empresa|fluxo => resultado */
    private array $cache = [];

    public function __construct(private readonly ContextoEmpresa $contexto) {}

    /**
     * Fluxos a que o utilizador tem acesso (o separador exige a consulta do módulo de origem), com a indicação de actividade
     * (só aparecem no ecrã os fluxos cuja primeira transacção já existe — fluxosComActividade, fluxo_processos.js:573-600).
     *
     * @param  callable(list<string>): bool  $pode
     */
    public function disponiveis(callable $pode): array
    {
        $saida = [];
        foreach (CatalogoFluxos::FLUXOS as $id => $f) {
            if (! $pode($f['vistas'])) {
                continue;
            }
            $saida[] = ['id' => $id, 'nome' => $f['nome'], 'sub' => $f['sub'], 'tem_actividade' => $this->avaliador($id)->temActividade(), 'etapas' => self::etapas($id)];
        }

        return $saida;
    }

    public static function existe(string $id): bool
    {
        return isset(CatalogoFluxos::FLUXOS[$id]);
    }

    /** @return list<string> consultas de módulo que dão acesso ao fluxo */
    public static function vistas(string $id): array
    {
        return self::definicao($id)['vistas'];
    }

    /** Definição, indicadores, funil e dados próprios do fluxo (sem a lista de processos). */
    public function resumo(string $id): array
    {
        $r = $this->calcular($id);
        $f = self::definicao($id);

        return ['fluxo' => ['id' => $id, 'nome' => $f['nome'], 'sub' => $f['sub'], 'etapas' => self::etapas($id), 'narrativa' => CatalogoFluxos::narrativa($id)],
            'tem_actividade' => $this->avaliador($id)->temActividade(), 'total' => count($r['processos']), 'kpis' => $r['kpis'], 'funil' => $r['funil'], 'extra' => $r['extra']];
    }

    /**
     * Lista paginada dos processos (linhas leves, sem factos), filtrável por etapa actual (ou «concluidos»), estado e texto.
     *
     * @param  array{etapa?: ?string, estado?: ?string, pesquisa?: ?string, pagina?: ?int, por_pagina?: ?int}  $f
     */
    public function processos(string $id, array $f): LengthAwarePaginator
    {
        $etapas = array_keys(self::definicao($id)['etapas']);
        if (! empty($f['etapa']) && $f['etapa'] !== 'concluidos' && ! in_array($f['etapa'], $etapas, true)) {
            throw new ErroNegocio('Etapa desconhecida neste fluxo.', 'ETAPA_INVALIDA', 422, ['etapa' => ['Use: '.implode(', ', $etapas).' ou concluidos.']]);
        }
        $lista = array_values(array_filter($this->calcular($id)['processos'], function ($p) use ($f) {
            if (! empty($f['etapa']) && ($f['etapa'] === 'concluidos' ? $p['etapa_actual'] !== null : $p['etapa_actual'] !== $f['etapa'])) {
                return false;
            }
            $ok = match ($f['estado'] ?? null) {
                'em_curso' => $p['etapa_actual'] !== null && ! $p['bloqueado'], 'bloqueado' => $p['bloqueado'], 'concluido' => $p['etapa_actual'] === null,
                'com_pendencias' => $p['n_erros'] + $p['n_avisos'] > 0, default => true,
            };
            if (! $ok) {
                return false;
            }
            $q = trim((string) ($f['pesquisa'] ?? ''));

            return $q === '' || str_contains(mb_strtolower($p['titulo'].' '.$p['subtitulo'].' '.$p['chave']), mb_strtolower($q));
        }));
        $porPagina = max(1, min(200, (int) ($f['por_pagina'] ?? 50)));
        $pagina = max(1, (int) ($f['pagina'] ?? 1));
        $linhas = array_map(fn ($p) => self::linha($p, $id), array_slice($lista, ($pagina - 1) * $porPagina, $porPagina));

        return new LengthAwarePaginator($linhas, count($lista), $porPagina, $pagina);
    }

    /** Processo completo: etapas com factos, problemas e acções, e a narrativa de cada etapa. */
    public function processo(string $id, string $chave): array
    {
        foreach ($this->calcular($id)['processos'] as $p) {
            if ($p['chave'] === $chave) {
                $narr = CatalogoFluxos::narrativa($id);
                $i = 0;
                foreach ($p['etapas'] as $eid => $e) {
                    $p['etapas'][$eid]['narrativa'] = $narr['etapas'][$i++] ?? null;
                }

                return $p + ['fluxo' => ['id' => $id, 'nome' => self::definicao($id)['nome']]];
            }
        }
        throw new ErroNegocio('Processo não encontrado neste fluxo.', 'PROCESSO_NAO_ENCONTRADO', 404);
    }

    /** Avalia o fluxo (uma vez por pedido) e normaliza os processos. */
    public function calcular(string $id): array
    {
        $k = $this->contexto->obrigatorio().'|'.$id;
        if (isset($this->cache[$k])) {
            return $this->cache[$k];
        }
        $etapas = self::etapas($id);
        $r = $this->avaliador($id)->avaliar();
        $processos = array_map(fn ($p) => self::normalizar($p, $etapas), $r['processos']);
        usort($processos, [self::class, 'ordenar']);
        $extra = $r['extra'] ?? [];
        if (isset($r['buracos'])) {
            $extra['periodos_em_falta'] = $r['buracos'];
        }

        return $this->cache[$k] = ['processos' => $processos, 'kpis' => $r['kpis'], 'funil' => self::funil($processos, $etapas), 'extra' => $extra ?: null];
    }

    /** Normaliza um processo: estados, contagens, etapa actual, erros e avisos; retira as chaves internas «_». */
    public static function normalizar(array $p, array $etapas): array
    {
        $mapa = ['CONCLUIDA' => 'concluida', 'NAO_APLICAVEL' => 'concluida', 'EM_CURSO' => 'curso', 'POR_FAZER' => 'fazer', 'BLOQUEADA' => 'bloqueada'];
        $E = [];
        foreach ($etapas as $e) {
            $x = $p['etapas'][$e['id']] ?? ['estado' => 'fazer', 'resumo' => '—'];
            $x['estado'] = in_array($x['estado'], self::ESTADOS, true) ? $x['estado'] : ($mapa[$x['estado']] ?? 'fazer');
            $E[$e['id']] = $x + ['factos' => [], 'problemas' => [], 'accoes' => []];
        }
        $concluidas = count(array_filter($E, fn ($x) => $x['estado'] === 'concluida'));
        $actual = null;
        foreach ($E as $eid => $x) {
            if ($x['estado'] !== 'concluida') {
                $actual = $eid;
                break;
            }
        }
        $problemas = array_merge(...array_values(array_map(fn ($x) => $x['problemas'], $E)));
        $terminado = ! empty($p['terminado']);
        foreach (array_keys($p) as $chave) {
            if (str_starts_with($chave, '_')) {
                unset($p[$chave]);
            }
        }

        return array_merge($p, ['etapas' => $E, 'total_etapas' => count($E), 'concluidas' => $terminado ? count($E) : $concluidas, 'etapa_actual' => $terminado ? null : $actual,
            'bloqueado' => ! $terminado && (bool) array_filter($E, fn ($x) => $x['estado'] === 'bloqueada'),
            'n_erros' => count(array_filter($problemas, fn ($x) => ($x['nivel'] ?? '') === 'erro')), 'n_avisos' => count(array_filter($problemas, fn ($x) => ($x['nivel'] ?? '') !== 'erro'))]);
    }

    /** ordenarPadrao (fluxo_tabela.js:71-73). */
    public static function ordenar(array $x, array $y): int
    {
        return ($y['n_erros'] <=> $x['n_erros'])
            ?: ((int) ($y['n_avisos'] > 0) <=> (int) ($x['n_avisos'] > 0))
            ?: (($x['etapa_actual'] !== null) === ($y['etapa_actual'] !== null) ? 0 : ($x['etapa_actual'] !== null ? -1 : 1))
            ?: ($x['etapa_actual'] !== null ? strcmp((string) $x['data'], (string) $y['data']) : strcmp((string) $y['data'], (string) $x['data']));
    }

    /** Funil: processos parados em cada etapa (etapa actual) e concluídos, com valores e estados por etapa. */
    public static function funil(array $processos, array $etapas): array
    {
        $grupos = [];
        foreach (array_merge($etapas, [['id' => 'concluidos', 'nome' => 'Concluídos']]) as $e) {
            $doGrupo = array_filter($processos, fn ($p) => $e['id'] === 'concluidos' ? $p['etapa_actual'] === null : $p['etapa_actual'] === $e['id']);
            $estados = array_fill_keys(self::ESTADOS, 0);
            if ($e['id'] !== 'concluidos') {
                foreach ($processos as $p) {
                    $estados[$p['etapas'][$e['id']]['estado']]++;
                }
            }
            $soma = fn (string $campo) => array_reduce($doGrupo, fn ($s, $p) => $p[$campo] === null ? $s : bcadd($s, AvaliadorFluxo::dinheiro($p[$campo]), 2), '0.00');
            $grupos[] = ['etapa' => $e['id'], 'nome' => $e['nome'], 'parados' => count($doGrupo), 'bloqueados' => count(array_filter($doGrupo, fn ($p) => $p['bloqueado'])),
                'com_pendencias' => count(array_filter($doGrupo, fn ($p) => $p['n_erros'] + $p['n_avisos'] > 0)), 'valor' => $soma('valor'), 'valor_pendente' => $soma('valor_pendente'),
                'estados' => $e['id'] === 'concluidos' ? null : $estados];
        }

        return $grupos;
    }

    /** Linha da lista (sem factos nem acções). */
    private static function linha(array $p, string $id): array
    {
        $actual = $p['etapa_actual'] ? $p['etapas'][$p['etapa_actual']] : null;
        $extra = array_diff_key($p, array_flip(['etapas', 'documentos']));

        return $extra + [
            'etapa_actual_nome' => $p['etapa_actual'] ? CatalogoFluxos::FLUXOS[$id]['etapas'][$p['etapa_actual']] : null,
            'etapa_actual_estado' => $actual['estado'] ?? 'concluida', 'etapa_actual_resumo' => $actual['resumo'] ?? null,
            'progresso' => array_map(fn ($e) => $e['estado'], $p['etapas']),
        ];
    }

    private static function definicao(string $id): array
    {
        return CatalogoFluxos::FLUXOS[$id] ?? throw new ErroNegocio('Fluxo desconhecido.', 'FLUXO_INVALIDO', 404, ['fluxo' => ['Use: '.implode(', ', array_keys(CatalogoFluxos::FLUXOS)).'.']]);
    }

    /** @return list<array{id: string, nome: string}> */
    public static function etapas(string $id): array
    {
        $e = self::definicao($id)['etapas'];

        return array_map(fn ($k, $n) => ['id' => $k, 'nome' => $n], array_keys($e), $e);
    }

    private function avaliador(string $id): AvaliadorFluxo
    {
        return app(self::definicao($id)['avaliador']);
    }
}
