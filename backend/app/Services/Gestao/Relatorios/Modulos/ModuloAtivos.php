<?php

namespace App\Services\Gestao\Relatorios\Modulos;

use App\Services\Ativos\CalculadoraAmortizacoes;
use App\Services\Gestao\Relatorios\PeriodosGestao;
use Illuminate\Support\Facades\DB;

/**
 * Activos (relatorios_gestao.js:426-467): investimento, amortizações, abates e manutenção do imobilizado.
 * Regras do legado: em uso no fim = adquiridos até ao fim e sem abate/venda até ao fim; aquisições com data no período;
 * amortizações CONTABILIZADAS com período (MM-AAAA) dentro dos meses do período; acumulado e valor líquido = situação
 * actual dos activos em uso; manutenções com data de execução (ou data) no período.
 * O período de cada quota é lido pela regra do módulo (CalculadoraAmortizacoes::periodo, ADR-051).
 */
final class ModuloAtivos extends ModuloGestao
{
    public function id(): string
    {
        return 'activos';
    }

    public function nome(): string
    {
        return 'Activos';
    }

    public function descricao(): string
    {
        return 'Investimento, amortizações, abates e manutenção do imobilizado.';
    }

    public function calcular(array $p): array
    {
        $e = $this->empresa();
        $abatidosAte = DB::table('abates_vendas_ativos')->where('empresa_id', $e)->where('data', '<=', $p['fim'])->pluck('ativo_imobilizado_id')->flip();
        $ativos = DB::table('ativos_imobilizados')->where('empresa_id', $e)->whereNull('eliminado_em')
            ->get(['id', 'categoria_ativo_id', 'valor_aquisicao', 'amortizacao_acumulada', 'data_aquisicao']);
        $emUso = $ativos->filter(fn ($a) => $a->data_aquisicao && $a->data_aquisicao <= $p['fim'] && ! isset($abatidosAte[$a->id]));
        $bruto = $emUso->reduce(fn ($s, $a) => bcadd($s, self::dinheiro($a->valor_aquisicao), 2), '0.00');
        $acumulado = $emUso->reduce(fn ($s, $a) => bcadd($s, self::dinheiro($a->amortizacao_acumulada), 2), '0.00');
        $aquis = $ativos->filter(fn ($a) => $a->data_aquisicao && $a->data_aquisicao >= $p['inicio'] && $a->data_aquisicao <= $p['fim']);
        $valAquis = $aquis->reduce(fn ($s, $a) => bcadd($s, self::dinheiro($a->valor_aquisicao), 2), '0.00');
        $mi = substr($p['inicio'], 0, 7);
        $mf = substr($p['fim'], 0, 7);
        $valAmort = DB::table('amortizacoes_ativos')->where('empresa_id', $e)->where('contabilizado', true)->get(['periodo_codigo', 'data', 'valor'])
            ->filter(function ($d) use ($mi, $mf) {
                $per = CalculadoraAmortizacoes::periodo((string) $d->periodo_codigo);
                $chave = $per ? sprintf('%04d-%02d', $per[0], $per[1]) : ($d->data ? substr((string) $d->data, 0, 7) : null);

                return $chave && $chave >= $mi && $chave <= $mf;
            })->reduce(fn ($s, $d) => bcadd($s, self::dinheiro($d->valor), 2), '0.00');
        $abates = DB::table('abates_vendas_ativos')->where('empresa_id', $e)->whereBetween('data', [$p['inicio'], $p['fim']])->count();
        $manut = self::dinheiro(DB::table('registos_manutencao_ativos')->where('empresa_id', $e)
            ->whereRaw('COALESCE(data_execucao, data) BETWEEN ? AND ?', [$p['inicio'], $p['fim']])->sum('custo'));
        $cats = DB::table('categorias_ativos')->where('empresa_id', $e)->pluck('nome', 'id');
        $porCat = $emUso->groupBy(fn ($a) => (string) ($a->categoria_ativo_id ?? ''))->map(fn ($g, $k) => [
            'categoria' => $cats[$k] ?? 'Sem categoria', 'n' => $g->count(),
            'bruto' => $g->reduce(fn ($s, $a) => bcadd($s, self::dinheiro($a->valor_aquisicao), 2), '0.00'),
            'acumulado' => $g->reduce(fn ($s, $a) => bcadd($s, self::dinheiro($a->amortizacao_acumulada), 2), '0.00'),
        ])->sortByDesc(fn ($x) => (float) $x['bruto'])->values()->all();
        $taxa = self::pct($valAmort, $bruto);

        return [
            'kpis' => [
                self::k('bruto', 'Valor bruto em uso (fim)', $bruto, 'kz', 'neutro', 'Custo de aquisição dos activos adquiridos até ao fim e não abatidos.'),
                self::k('n', 'Nº de activos em uso', $emUso->count(), 'num', 'neutro'),
                self::k('aquis', 'Investimento (aquisições)', $valAquis, 'kz', 'neutro', 'Activos com data de aquisição no período.'),
                self::k('n_aquis', 'Nº de aquisições', $aquis->count(), 'num', 'neutro'),
                self::k('amort', 'Amortizações do período', $valAmort, 'kz', 'neutro', 'Amortizações contabilizadas com período dentro das datas.'),
                self::k('taxa_amort', 'Taxa de amortização anualizada', $taxa === null ? null : $taxa * (365 / PeriodosGestao::dias($p)), 'pct', 'neutro', 'Amortizações ÷ valor bruto, anualizada.'),
                self::k('vlc', 'Valor líquido contabilístico', bcsub($bruto, $acumulado, 2), 'kz', 'neutro', 'Valor bruto − amortizações acumuladas.', ['actual' => true]),
                self::k('desgaste', 'Grau de desgaste', self::pct($acumulado, $bruto), 'pct', 'desce', 'Amortizações acumuladas ÷ valor bruto (perto de 100% = equipamento a precisar de renovação).', ['actual' => true]),
                self::k('abates', 'Abates / alienações', $abates, 'num', 'neutro'),
                self::k('manut', 'Custo de manutenção', $manut, 'kz', 'desce', 'Manutenções registadas no período.'),
                self::k('manut_pct', 'Manutenção / valor bruto', self::pct($manut, $bruto), 'pct', 'desce'),
            ],
            'tabelas' => [self::tabela('por_categoria', 'Activos em uso por categoria', 'categoria',
                [['categoria', 'Categoria'], ['n', 'Nº', 'num'], ['bruto', 'Valor bruto', 'kz'], ['acumulado', 'Amort. acumuladas', 'kz']], $porCat)],
            'graficos' => [],
            'notas' => ['Amortizações acumuladas e valor líquido referem-se à situação actual dos activos em uso no fim do período.'],
        ];
    }
}
