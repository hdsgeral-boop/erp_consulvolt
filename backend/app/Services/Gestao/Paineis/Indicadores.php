<?php

namespace App\Services\Gestao\Paineis;

use App\Services\Contabilidade\FiltroMapas;

/**
 * Forma comum dos blocos de um painel, para o frontend desenhar sem regras próprias:
 *   - indicador (KPI): id, rotulo, valor, formato (kz | num | pct | dias | texto), subtitulo, ir (painel de destino);
 *   - gráfico: id, titulo, tipo (barras | linhas | circular), monetario, horizontal, empilhado, rotulos, series[id, rotulo, valores];
 *   - tabela: id, titulo, colunas[id, rotulo, formato], linhas (objectos com as chaves das colunas).
 * Valores monetários em texto com 2 casas (ADR-022); contagens inteiras; percentagens com 2 casas ou null.
 */
final class Indicadores
{
    /** @return array<string, mixed> */
    public static function kpi(string $id, string $rotulo, mixed $valor, string $formato = 'kz', ?string $subtitulo = null, ?string $ir = null, array $extra = []): array
    {
        return ['id' => $id, 'rotulo' => $rotulo, 'valor' => $valor, 'formato' => $formato, 'subtitulo' => $subtitulo, 'ir' => $ir] + $extra;
    }

    /**
     * @param  list<string>  $rotulos
     * @param  list<array<string, mixed>>  $series
     * @return array<string, mixed>
     */
    public static function grafico(string $id, string $titulo, string $tipo, array $rotulos, array $series, bool $monetario = true, array $opcoes = []): array
    {
        return ['id' => $id, 'titulo' => $titulo, 'tipo' => $tipo, 'monetario' => $monetario, 'horizontal' => $opcoes['horizontal'] ?? false,
            'empilhado' => $opcoes['empilhado'] ?? false, 'rotulos' => array_values($rotulos), 'series' => $series];
    }

    /** @return array{id: string, rotulo: string, valores: list<mixed>} */
    public static function serie(string $id, string $rotulo, array $valores): array
    {
        return ['id' => $id, 'rotulo' => $rotulo, 'valores' => array_values($valores)];
    }

    /**
     * @param  list<array{0: string, 1: string, 2?: string}>  $colunas  [id, rotulo, formato]
     * @param  list<array<string, mixed>>  $linhas
     * @return array<string, mixed>
     */
    public static function tabela(string $id, string $titulo, array $colunas, array $linhas): array
    {
        return ['id' => $id, 'titulo' => $titulo, 'colunas' => array_map(fn ($c) => ['id' => $c[0], 'rotulo' => $c[1], 'formato' => $c[2] ?? 'texto'], $colunas),
            'linhas' => array_values($linhas)];
    }

    public static function dinheiro(mixed $v): string
    {
        return FiltroMapas::dinheiro($v);
    }

    /** Percentagem a/b × 100 com 2 casas; null quando a base é zero (o legado mostrava "-"). */
    public static function pct(mixed $parte, mixed $base): ?float
    {
        $b = (float) $base;

        return abs($b) < 0.005 ? null : round((float) $parte / $b * 100, 2);
    }

    /** Variação percentual face a um valor anterior (sobre o módulo do anterior, como o legado). */
    public static function variacao(mixed $actual, mixed $anterior): ?float
    {
        $a = (float) $anterior;

        return abs($a) < 0.005 ? null : round(((float) $actual - $a) / abs($a) * 100, 2);
    }

    public static function negar(string $v): string
    {
        $r = bcmul($v, '-1', 2);

        return $r === '-0.00' ? '0.00' : $r;
    }
}
