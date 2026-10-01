<?php

namespace App\Services\Gestao\Relatorios;

use App\Exceptions\ErroNegocio;
use App\Services\Gestao\Relatorios\Modulos\ModuloAtivos;
use App\Services\Gestao\Relatorios\Modulos\ModuloCompras;
use App\Services\Gestao\Relatorios\Modulos\ModuloFinancas;
use App\Services\Gestao\Relatorios\Modulos\ModuloGestao;
use App\Services\Gestao\Relatorios\Modulos\ModuloProjetos;
use App\Services\Gestao\Relatorios\Modulos\ModuloRH;
use App\Services\Gestao\Relatorios\Modulos\ModuloServicos;
use App\Services\Gestao\Relatorios\Modulos\ModuloStock;
use App\Services\Gestao\Relatorios\Modulos\ModuloTesouraria;
use App\Services\Gestao\Relatorios\Modulos\ModuloVendas;

/**
 * Relatórios de gestão por módulo, com selecção e comparação de períodos (renderRelatoriosGestao,
 * modules/gestao/relatorios_gestao.js:574-850). Nove módulos (finanças, vendas, compras, tesouraria, stock, RH, activos,
 * projectos, POS e serviços) e o resumo executivo. Igual ao legado:
 *   - variação Δ = A − B e Δ% = (A − B) ÷ |B| × 100 (B = 0 → sem Δ%); leitura «boa» quando a variação vai no sentido do
 *     indicador (sobe/desce), «neutra» com Δ = 0 ou indicador neutro;
 *   - tabelas comparam a coluna principal (a primeira em Kz) pela chave da linha;
 *   - resumo: indicadores-chave de cada módulo e as maiores variações (|Δ%| ≥ 20 %, sem neutros nem «situação actual»), até 12.
 * O cálculo de cada módulo é feito no servidor em SQL agregado; o frontend desenha os cartões, os gráficos e exporta.
 */
final class ServicoRelatoriosGestao
{
    /** Indicadores do resumo executivo (RESUMO, relatorios_gestao.js:829). */
    public const RESUMO = ['financas' => ['vn', 'ebitda', 'resultado', 'margem_liq', 'disponib'], 'vendas' => ['liq', 'clientes', 'margem_pct'], 'compras' => ['liq'],
        'tesouraria' => ['fluxo', 'cobertura'], 'rh' => ['custo', 'absentismo'], 'stock' => ['saidas', 'cobertura'], 'activos' => ['aquis'], 'projectos' => ['margem'],
        'servicos' => ['pos_vendas', 'hot_ocupacao']];

    /** @var array<string, ModuloGestao> */
    private array $modulos;

    public function __construct(ModuloFinancas $financas, ModuloVendas $vendas, ModuloCompras $compras, ModuloTesouraria $tesouraria, ModuloStock $stock,
        ModuloRH $rh, ModuloAtivos $ativos, ModuloProjetos $projetos, ModuloServicos $servicos)
    {
        foreach ([$financas, $vendas, $compras, $tesouraria, $stock, $rh, $ativos, $projetos, $servicos] as $m) {
            $this->modulos[$m->id()] = $m;
        }
    }

    /** Catálogo: módulos, opções de período e de comparação. */
    public function catalogo(): array
    {
        return [
            'modulos' => array_values(array_map(fn (ModuloGestao $m) => ['id' => $m->id(), 'nome' => $m->nome(), 'descricao' => $m->descricao()], $this->modulos)),
            'presets_a' => self::opcoes(PeriodosGestao::PRESETS_A), 'comparacoes' => self::opcoes(PeriodosGestao::COMPARACOES),
        ];
    }

    /** Um módulo nos períodos A e B, com a comparação. */
    public function modulo(string $id, array $periodos): array
    {
        $m = $this->modulos[$id] ?? throw new ErroNegocio('Módulo de relatório desconhecido.', 'MODULO_INVALIDO', 404, ['modulo' => ['Use: '.implode(', ', array_keys($this->modulos)).'.']]);

        return ['modulo' => ['id' => $m->id(), 'nome' => $m->nome(), 'descricao' => $m->descricao()], 'periodos' => $periodos] + $this->comparar($m, $periodos);
    }

    /** Todos os módulos (impressão «Imprimir todos» e exportação Excel). */
    public function todos(array $periodos): array
    {
        $comparacoes = array_map(fn (ModuloGestao $m) => $this->comparar($m, $periodos), $this->modulos);
        $modulos = [];
        foreach ($comparacoes as $id => $r) {
            $m = $this->modulos[$id];
            $modulos[] = ['modulo' => ['id' => $id, 'nome' => $m->nome(), 'descricao' => $m->descricao()]] + $r;
        }

        return $this->resumoDe($comparacoes, $periodos) + ['modulos' => $modulos];
    }

    /** Resumo executivo (htmlResumo, relatorios_gestao.js:830-850). */
    public function resumo(array $periodos): array
    {
        return $this->resumoDe(array_map(fn (ModuloGestao $m) => $this->comparar($m, $periodos), $this->modulos), $periodos);
    }

    /** @param  array<string, array<string, mixed>>  $comparacoes */
    private function resumoDe(array $comparacoes, array $periodos): array
    {
        $blocos = [];
        $destaques = [];
        foreach ($comparacoes as $id => $r) {
            $m = $this->modulos[$id];
            $blocos[] = ['modulo' => ['id' => $id, 'nome' => $m->nome()], 'kpis' => array_values(array_filter($r['kpis'], fn ($k) => in_array($k['chave'], self::RESUMO[$id] ?? [], true)))];
            if ($periodos['b'] !== null) {
                foreach ($r['kpis'] as $k) {
                    $v = $k['variacao'];
                    if ($v['pct'] !== null && abs($v['pct']) >= 20 && $k['sentido'] !== 'neutro' && ! $k['actual'] && (float) $v['abs'] != 0.0) {
                        $destaques[] = ['modulo' => $m->nome(), 'modulo_id' => $id] + $k;
                    }
                }
            }
        }
        usort($destaques, fn ($a, $b) => abs($b['variacao']['pct']) <=> abs($a['variacao']['pct']));

        return ['periodos' => $periodos, 'blocos' => $blocos, 'destaques' => array_slice($destaques, 0, 12)];
    }

    /** Calcula A e B e junta a comparação a cada indicador, tabela e gráfico. */
    private function comparar(ModuloGestao $m, array $periodos): array
    {
        $a = $m->calcular($periodos['a']);
        $b = $periodos['b'] !== null ? $m->calcular($periodos['b']) : null;
        $kB = $b ? array_column($b['kpis'], null, 'chave') : [];
        $kpis = array_map(function ($k) use ($kB, $b) {
            $vb = $b ? ($kB[$k['chave']]['valor'] ?? null) : null;
            $var = $b ? self::variacao($k['valor'], $vb, $k['formato']) : ['abs' => null, 'pct' => null];
            $out = $k;
            unset($out['valor']);

            return $out + ['a' => $k['valor'], 'b' => $vb, 'variacao' => $var, 'leitura' => $b ? self::leitura($k['sentido'], $var['abs']) : 'neutra'];
        }, $a['kpis']);
        $tabelas = [];
        foreach ($a['tabelas'] as $i => $t) {
            $principal = collect($t['colunas'])->firstWhere('formato', 'kz') ?? collect($t['colunas'])->first(fn ($c) => $c['formato'] !== null);
            $tB = $b['tabelas'][$i] ?? null;
            $mapaB = $tB ? collect($tB['linhas'])->keyBy(fn ($l) => (string) $l[$t['chave']]) : collect();
            $t['coluna_principal'] = $principal['id'] ?? null;
            if ($b && $principal) {
                $t['linhas'] = array_map(function ($l) use ($mapaB, $t, $principal) {
                    $lb = $mapaB[(string) $l[$t['chave']]] ?? null;
                    $vb = $lb[$principal['id']] ?? null;

                    return $l + ['b' => $vb, 'variacao_pct' => self::variacao($l[$principal['id']] ?? 0, $vb ?? 0, (string) $principal['formato'])['pct']];
                }, $t['linhas']);
            }
            $tabelas[] = $t;
        }
        $graficos = [];
        foreach ($a['graficos'] as $i => $g) {
            $gB = $b['graficos'][$i] ?? null;
            $graficos[] = ['id' => $g['id'], 'titulo' => $g['titulo'], 'tipo' => $g['tipo'], 'rotulos_a' => $g['rotulos'], 'rotulos_b' => $gB['rotulos'] ?? [],
                'series' => array_map(fn ($s, $j) => ['rotulo' => $s['rotulo'], 'a' => $s['valores'], 'b' => $gB['series'][$j]['valores'] ?? []], $g['series'], array_keys($g['series']))];
        }

        return ['notas' => $a['notas'], 'kpis' => $kpis, 'tabelas' => $tabelas, 'graficos' => $graficos];
    }

    /** @return array{abs: string|float|null, pct: ?float} */
    public static function variacao(mixed $a, mixed $b, string $formato): array
    {
        if ($a === null || $b === null) {
            return ['abs' => null, 'pct' => null];
        }
        $abs = $formato === 'kz' ? bcsub(ModuloGestao::dinheiro($a), ModuloGestao::dinheiro($b), 2) : round((float) $a - (float) $b, 4);
        $pct = (float) $b != 0.0 ? round(((float) $a - (float) $b) / abs((float) $b) * 100, 2) : null;

        return ['abs' => $abs, 'pct' => $pct];
    }

    public static function leitura(string $sentido, mixed $abs): string
    {
        if ($abs === null || abs((float) $abs) < 1e-9 || $sentido === 'neutro') {
            return 'neutra';
        }

        return ((float) $abs > 0) === ($sentido === 'sobe') ? 'boa' : 'ma';
    }

    private static function opcoes(array $m): array
    {
        return array_map(fn ($k, $v) => ['id' => $k, 'nome' => $v], array_keys($m), $m);
    }
}
