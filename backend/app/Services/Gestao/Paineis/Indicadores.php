<?php

namespace App\Services\Gestao\Paineis;

use App\Services\Contabilidade\FiltroMapas;

/**
 * Forma comum dos blocos de um painel, para o frontend desenhar sem regras próprias:
 *   - indicador (KPI): id, rotulo, valor, formato (kz | num | pct | dias | texto), subtitulo, ir (painel de destino);
 *   - gráfico: id, titulo, tipo (barras | linhas | circular), monetario, horizontal, empilhado, sem_preenchimento, rotulos,
 *     series[id, rotulo, valores, cor?] — a cor é a da série no legado (CORES_SERIES), quando a série tem significado próprio;
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
     * Cores das séries nos gráficos do legado (js/ui_painel_modulos.js, `cor` de cada série): «gráfico.série» → cor. As séries
     * sem entrada usam a paleta por posição no frontend (a mesma PALETA do legado).
     */
    public const CORES_SERIES = [
        'vendas_compras.vendas' => '#2563eb', 'vendas_compras.compras' => '#ea580c',
        'proveitos_custos.proveitos' => '#10b981', 'proveitos_custos.custos' => '#ef4444',
        'evolucao_salarial.iliquido' => '#4f46e5', 'evolucao_salarial.liquido' => '#10b981', 'evolucao_salarial.custo_total' => '#94a3b8',
        'faturacao_mensal.faturacao' => '#2563eb', 'compras_mensais.compras' => '#ea580c',
        'entradas_saidas.entradas' => '#10b981', 'entradas_saidas.saidas' => '#ef4444',
        'top_artigos.valor' => '#0d9488', 'evolucao_saldo.saldo' => '#0891b2',
        'custos_mensais.mao_obra_equipamento' => '#b45309', 'custos_mensais.compras' => '#ef4444',
        'orcamento_custo_proveito.orcamento' => '#7c3aed', 'orcamento_custo_proveito.custo' => '#ef4444', 'orcamento_custo_proveito.proveitos' => '#2563eb',
        'amortizacoes.amortizacoes' => '#db2777',
        'orcado_consumido.orcado' => '#1d4ed8', 'orcado_consumido.consumido' => '#ef4444',
        'previsao_fecho.bruto' => '#93c5fd', 'previsao_fecho.ponderado' => '#1d4ed8',
        'reconhecimentos.valor' => '#0d9488',
        'pessoas_unidade.pessoas' => '#1d4ed8', 'pessoas_unidade.em_aberto' => '#f59e0b',
        'por_empresa.proveitos' => '#10b981', 'por_empresa.custos' => '#ef4444', 'por_empresa.resultado' => '#7c3aed',
        'proveitos_homologos.ano_anterior' => '#94a3b8', 'proveitos_homologos.ano' => '#2563eb',
        'saldos.disponibilidades' => '#0891b2', 'saldos.clientes' => '#6366f1', 'saldos.fornecedores' => '#f97316',
    ];

    /** Gráficos de linhas que no legado não tinham área (semPreenchimento). */
    public const SEM_PREENCHIMENTO = ['resultado_mensal'];

    /**
     * @param  list<string>  $rotulos
     * @param  list<array<string, mixed>>  $series
     * @return array<string, mixed>
     */
    public static function grafico(string $id, string $titulo, string $tipo, array $rotulos, array $series, bool $monetario = true, array $opcoes = []): array
    {
        $series = array_map(fn ($s) => isset($s['cor']) || ! isset(self::CORES_SERIES["{$id}.".($s['id'] ?? '')]) ? $s : $s + ['cor' => self::CORES_SERIES["{$id}.{$s['id']}"]], $series);

        return ['id' => $id, 'titulo' => $titulo, 'tipo' => $tipo, 'monetario' => $monetario, 'horizontal' => $opcoes['horizontal'] ?? false,
            'empilhado' => $opcoes['empilhado'] ?? false, 'sem_preenchimento' => in_array($id, self::SEM_PREENCHIMENTO, true), 'rotulos' => array_values($rotulos), 'series' => $series];
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
