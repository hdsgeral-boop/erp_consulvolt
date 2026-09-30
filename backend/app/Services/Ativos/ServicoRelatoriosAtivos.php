<?php

namespace App\Services\Ativos;

use App\Models\AbateVendaAtivo;
use App\Models\AmortizacaoAtivo;
use App\Models\AtivoImobilizado;
use App\Models\CategoriaAtivo;
use App\Models\LancamentoContabil;
use Illuminate\Support\Collection;

/**
 * Mapas do imobilizado:
 *   - mapa de amortizações do ano (renderAmortizationMap, js/ui_assets.js:2925-3078): activos adquiridos até ao ano, acumulado
 *     até 31/12 anterior (inicial + quotas dos anos anteriores), as 12 quotas do ano, acumulado final e valor líquido. Como no
 *     legado inclui os rascunhos (é o mapa de trabalho), agora assinalados célula a célula;
 *   - mapa fiscal de reintegrações e amortizações (renderFiscalAssetMap, ui_assets.js:3625-3769): só quotas integradas;
 *     correcção: o legado listava todos os activos, mesmo os adquiridos depois do ano, e os abatidos em anos anteriores;
 *   - resumo por categoria (bruto, amortização acumulada, líquido) para a Nota 4 das demonstrações (ui_reports.js:4100-4127),
 *     sem os activos abatidos (que o abate retira do balanço);
 *   - fluxo do imobilizado (js/fluxo_imobilizado.js): da aquisição à integração das amortizações, com as mesmas etapas e regras.
 */
final class ServicoRelatoriosAtivos
{
    public function __construct(private readonly ServicoAmortizacoes $amortizacoes) {}

    public function mapaAmortizacoes(int $ano): array
    {
        $cats = CategoriaAtivo::withTrashed()->get()->keyBy('id');
        $ativos = AtivoImobilizado::query()->whereYear('data_aquisicao', '<=', $ano)->orderBy('codigo')->get();
        $registos = AmortizacaoAtivo::query()->whereIn('ativo_imobilizado_id', $ativos->pluck('id'))->get()->groupBy('ativo_imobilizado_id');
        $linhas = [];
        $tot = ['aquisicao_anos_anteriores' => '0.00', 'aquisicao_ano' => '0.00', 'acumulado_anterior' => '0.00', 'ano' => '0.00', 'acumulado' => '0.00', 'liquido' => '0.00',
            'meses' => array_fill(1, 12, '0.00')];
        foreach ($ativos as $a) {
            $c = $cats[$a->categoria_ativo_id] ?? null;
            $anterior = CalculadoraAmortizacoes::d($a->amortizacao_acumulada_inicial);
            $meses = array_fill(1, 12, null);
            $doAno = '0.00';
            foreach ($registos[$a->id] ?? [] as $r) {
                [$y, $m] = CalculadoraAmortizacoes::periodo((string) $r->periodo_codigo) ?? [0, 0];
                $v = CalculadoraAmortizacoes::d($r->valor);
                if ($y < $ano) {
                    $anterior = bcadd($anterior, $v, 2);
                } elseif ($y === $ano) {
                    $meses[$m] = ['valor' => $v, 'contabilizado' => (bool) $r->contabilizado, 'amortizacao_id' => $r->id];
                    $doAno = bcadd($doAno, $v, 2);
                    $tot['meses'][$m] = bcadd($tot['meses'][$m], $v, 2);
                }
            }
            $novo = (int) $a->data_aquisicao->format('Y') === $ano;
            $aquisicao = CalculadoraAmortizacoes::d($a->valor_aquisicao);
            $acumulado = bcadd($anterior, $doAno, 2);
            $linha = [
                'ativo_imobilizado_id' => $a->id, 'codigo' => $a->codigo, 'descricao' => $a->descricao, 'estado' => $a->estado, 'categoria' => $c?->nome,
                'taxa' => bccomp(CalculadoraAmortizacoes::d($a->quota_fixa), '0', 2) > 0 ? 'FIXA' : bcadd((string) ($c->taxa_anual ?? 0), '0', 2),
                'anos_vida' => (int) $a->vida_util > 0 ? bcdiv((string) $a->vida_util, '12', 1) : null, 'data_aquisicao' => $a->data_aquisicao->toDateString(),
                'aquisicao_anos_anteriores' => $novo ? '0.00' : $aquisicao, 'aquisicao_ano' => $novo ? $aquisicao : '0.00',
                'acumulado_anterior' => $anterior, 'meses' => $meses, 'ano' => $doAno, 'acumulado' => $acumulado, 'liquido' => bcsub($aquisicao, $acumulado, 2),
            ];
            foreach (['aquisicao_anos_anteriores', 'aquisicao_ano', 'acumulado_anterior', 'ano', 'acumulado', 'liquido'] as $k) {
                $tot[$k] = bcadd($tot[$k], $linha[$k], 2);
            }
            $linhas[] = $linha;
        }

        return ['ano' => $ano, 'linhas' => $linhas, 'totais' => $tot];
    }

    public function mapaFiscal(int $ano): array
    {
        $cats = CategoriaAtivo::withTrashed()->get()->keyBy('id');
        $abatidosAntes = AbateVendaAtivo::query()->where('data', '<', "{$ano}-01-01")->pluck('ativo_imobilizado_id')->flip();
        $ativos = AtivoImobilizado::query()->whereYear('data_aquisicao', '<=', $ano)->orderBy('codigo')->get()->reject(fn ($a) => isset($abatidosAntes[$a->id]));
        $contas = LancamentoContabil::query()->whereIn('id', $ativos->pluck('lancamento_contabil_id')->filter())->pluck('codigo_conta', 'id');
        $integradas = AmortizacaoAtivo::query()->whereIn('ativo_imobilizado_id', $ativos->pluck('id'))->where('contabilizado', true)->get()->groupBy('ativo_imobilizado_id');
        $linhas = [];
        $tot = ['valor_aquisicao' => '0.00', 'anteriores' => '0.00', 'exercicio' => '0.00', 'acumuladas' => '0.00', 'liquido' => '0.00'];
        foreach ($ativos->values() as $a) {
            $c = $cats[$a->categoria_ativo_id] ?? null;
            $anteriores = CalculadoraAmortizacoes::d($a->amortizacao_acumulada_inicial);
            $exercicio = '0.00';
            foreach ($integradas[$a->id] ?? [] as $r) {
                $y = (CalculadoraAmortizacoes::periodo((string) $r->periodo_codigo) ?? [0])[0];
                if ($y < $ano) {
                    $anteriores = bcadd($anteriores, CalculadoraAmortizacoes::d($r->valor), 2);
                } elseif ($y === $ano) {
                    $exercicio = bcadd($exercicio, CalculadoraAmortizacoes::d($r->valor), 2);
                }
            }
            $aquisicao = CalculadoraAmortizacoes::d($a->valor_aquisicao);
            $acumuladas = bcadd($anteriores, $exercicio, 2);
            $linha = [
                'ativo_imobilizado_id' => $a->id, 'codigo' => $a->codigo, 'descricao' => $a->descricao,
                'conta' => $contas[$a->lancamento_contabil_id] ?? $c?->conta_ativo, 'mes_aquisicao' => (int) $a->data_aquisicao->format('n'),
                'ano_aquisicao' => (int) $a->data_aquisicao->format('Y'), 'mes_inicio_utilizacao' => (int) $a->data_aquisicao->format('n'),
                'ano_inicio_utilizacao' => (int) $a->data_aquisicao->format('Y'), 'valor_aquisicao' => $aquisicao,
                'anos_vida' => (int) $a->vida_util > 0 ? bcdiv((string) $a->vida_util, '12', 1) : null,
                'taxa_categoria' => bcadd((string) ($c->taxa_anual ?? 0), '0', 2), 'taxa_efectiva' => (int) $a->vida_util > 0 ? bcdiv('1200', (string) $a->vida_util, 2) : null,
                'anteriores' => $anteriores, 'exercicio' => $exercicio, 'acumuladas' => $acumuladas, 'liquido' => bcsub($aquisicao, $acumuladas, 2),
            ];
            foreach (array_keys($tot) as $k) {
                $tot[$k] = bcadd($tot[$k], $linha[$k], 2);
            }
            $linhas[] = $linha;
        }

        return ['ano' => $ano, 'linhas' => $linhas, 'totais' => $tot];
    }

    /** @return list<array{categoria_ativo_id: ?int, categoria: string, ativos: int, bruto: string, acumulada: string, liquido: string}> */
    public function resumoPorCategoria(): array
    {
        $cats = CategoriaAtivo::withTrashed()->get()->keyBy('id');

        return AtivoImobilizado::query()->where('estado', '<>', AtivoImobilizado::ESTADO_ABATIDO)->get()->groupBy('categoria_ativo_id')
            ->map(function (Collection $g, $id) use ($cats) {
                $bruto = $g->reduce(fn ($s, $a) => bcadd($s, CalculadoraAmortizacoes::d($a->valor_aquisicao), 2), '0.00');
                $acumulada = $g->reduce(fn ($s, $a) => bcadd($s, CalculadoraAmortizacoes::d($a->amortizacao_acumulada), 2), '0.00');

                return ['categoria_ativo_id' => $id ? (int) $id : null, 'categoria' => $cats[$id]->nome ?? 'Sem categoria', 'ativos' => $g->count(),
                    'bruto' => $bruto, 'acumulada' => $acumulada, 'liquido' => bcsub($bruto, $acumulada, 2)];
            })->sortBy('categoria')->values()->all();
    }

    /**
     * Fluxo do imobilizado (fluxo_imobilizado.js:55-200): uma aquisição = lançamento no activo (11/12 a débito) ou o grupo dos
     * activos sem lançamento de compra (migração/cadastro). Etapas: fact, contab, invent, cat, calc, integ, com estado
     * concluida | curso | fazer | bloqueada. As facturas de fornecedor por contabilizar com artigos de imobilizado (etapa B do
     * legado) dependem de Compras e ficam fora (ADR-051).
     */
    public function fluxo(?string $referencia = null): array
    {
        $ultimo = (int) date('Y', strtotime($referencia ?? 'now')) * 12 + (int) date('n', strtotime($referencia ?? 'now')) - 2;
        $cats = CategoriaAtivo::withTrashed()->get()->keyBy('id');
        $ativos = AtivoImobilizado::query()->orderBy('codigo')->get();
        $registos = AmortizacaoAtivo::query()->get()->groupBy('ativo_imobilizado_id');
        $porCalcular = collect($this->amortizacoes->mesesPorCalcular($ultimo))->groupBy('ativo_imobilizado_id');
        $linhas = LancamentoContabil::query()->where('tipo_dc', 'D')->where(fn ($q) => $q->where('codigo_conta', 'like', '11%')->orWhere('codigo_conta', 'like', '12%'))
            ->whereNull('estorno_de_id')->whereNull('estornado_por_id')->with('terceiro:id,nome')->orderByDesc('data_documento')->get();
        $porLinha = $ativos->whereNotNull('lancamento_contabil_id')->groupBy('lancamento_contabil_id');

        $itens = [];
        foreach ($linhas->groupBy(fn ($l) => $l->diario_id.'|'.$l->chave()) as $k => $g) {
            $l0 = $g->first();
            $valor = $g->reduce(fn ($s, $l) => bcadd($s, CalculadoraAmortizacoes::d($l->valor), 2), '0.00');
            $ligados = $g->flatMap(fn ($l) => $porLinha[$l->id] ?? [])->values();
            $inventariado = $ligados->reduce(fn ($s, $a) => bcadd($s, CalculadoraAmortizacoes::d($a->valor_aquisicao), 2), '0.00');
            $itens[] = $this->avaliar(['chave' => "LAN|{$k}", 'tipo' => 'lancamento', 'titulo' => $l0->numero_lan ?: ($l0->numero_documento ?: "Lançamento {$l0->id}"),
                'data' => $l0->data_documento?->toDateString(), 'fornecedor' => $l0->terceiro?->nome, 'valor' => $valor, 'inventariado' => $inventariado,
                'contas' => $g->pluck('codigo_conta')->unique()->values()->all()], $ligados, $cats, $registos, $porCalcular);
        }
        $sem = $ativos->whereNull('lancamento_contabil_id')->values();
        if ($sem->isNotEmpty()) {
            $itens[] = $this->avaliar(['chave' => 'MIG', 'tipo' => 'migracao', 'titulo' => "Activos sem lançamento de compra ({$sem->count()})",
                'data' => $sem->max(fn ($a) => $a->data_aquisicao?->toDateString()), 'fornecedor' => null,
                'valor' => $sem->reduce(fn ($s, $a) => bcadd($s, CalculadoraAmortizacoes::d($a->valor_aquisicao), 2), '0.00'), 'inventariado' => null, 'contas' => []],
                $sem, $cats, $registos, $porCalcular);
        }
        $pend = $this->amortizacoes->pendentes($referencia);

        return [
            'kpis' => [
                'em_curso' => count(array_filter($itens, fn ($i) => ! $i['bloqueado'] && $i['concluidas'] < 6)),
                'bloqueadas' => count(array_filter($itens, fn ($i) => $i['bloqueado'])),
                'concluidas' => count(array_filter($itens, fn ($i) => $i['concluidas'] === 6)),
                'meses_por_calcular' => count($pend['por_calcular']), 'meses_por_integrar' => count($pend['por_integrar']),
            ],
            'meses_por_calcular' => $pend['por_calcular'], 'meses_por_integrar' => $pend['por_integrar'], 'aquisicoes' => $itens,
        ];
    }

    private function avaliar(array $it, Collection $ativos, Collection $cats, Collection $registos, Collection $porCalcular): array
    {
        $e = [];
        $mig = $it['tipo'] === 'migracao';
        $e['fact'] = ['estado' => 'concluida', 'resumo' => $mig ? 'Migração / cadastro' : 'Lançamento no activo'];
        $e['contab'] = ['estado' => 'concluida', 'resumo' => $mig ? 'Não aplicável' : implode(', ', $it['contas'])];
        if ($mig) {
            $e['invent'] = ['estado' => 'concluida', 'resumo' => $ativos->count().' activo(s)'];
        } else {
            $falta = bcsub($it['valor'], $it['inventariado'], 2);
            $e['invent'] = $ativos->isEmpty() ? ['estado' => 'curso', 'resumo' => 'Por inventariar', 'por_inventariar' => $it['valor']]
                : (bccomp($falta, '0.01', 2) >= 0 ? ['estado' => 'curso', 'resumo' => 'Inventariação parcial', 'por_inventariar' => $falta]
                    : ['estado' => 'concluida', 'resumo' => $ativos->count().' activo(s)']);
        }
        if ($ativos->isEmpty()) {
            $e['cat'] = ['estado' => 'fazer', 'resumo' => 'Depois de inventariar'];
            $e['calc'] = ['estado' => 'fazer', 'resumo' => 'Depois de inventariar'];
            $e['integ'] = ['estado' => 'fazer', 'resumo' => 'Depois de calcular'];
        } else {
            $problemas = [];
            foreach ($ativos as $a) {
                $c = $cats[$a->categoria_ativo_id] ?? null;
                if (! $c) {
                    $problemas["a{$a->id}"] = "{$a->codigo}: sem categoria.";
                } elseif (! $c->conta_gasto || ! $c->conta_amortizacao_acumulada) {
                    $problemas["c{$c->id}"] = "Categoria «{$c->nome}»: falta a conta de gasto ou a de amortização acumulada.";
                }
                if ((int) $a->vida_util <= 0 && ! ($c && bccomp((string) $c->taxa_anual, '0', 4) > 0)) {
                    $problemas["v{$a->id}"] = "{$a->codigo}: sem vida útil nem taxa anual de amortização.";
                }
            }
            $e['cat'] = $problemas ? ['estado' => 'bloqueada', 'resumo' => count($problemas).' pendência(s)', 'problemas' => array_values($problemas)]
                : ['estado' => 'concluida', 'resumo' => $ativos->map(fn ($a) => $cats[$a->categoria_ativo_id]->nome ?? null)->filter()->unique()->implode(', ')];
            $falta = $ativos->flatMap(fn ($a) => $porCalcular[$a->id] ?? [])->pluck('periodo')->unique();
            $rascunhos = $ativos->flatMap(fn ($a) => ($registos[$a->id] ?? collect())->where('contabilizado', false))->pluck('periodo_codigo')->unique();
            $integrados = $ativos->flatMap(fn ($a) => ($registos[$a->id] ?? collect())->where('contabilizado', true))->pluck('periodo_codigo')->unique();
            $ordenar = fn ($c) => $c->sortBy(fn ($p) => CalculadoraAmortizacoes::ordem($p))->values()->all();
            $e['calc'] = $falta->isNotEmpty() ? ['estado' => $e['cat']['estado'] === 'bloqueada' ? 'bloqueada' : 'curso', 'resumo' => $falta->count().' mês(es) por calcular', 'periodos' => $ordenar($falta)]
                : ['estado' => $rascunhos->isEmpty() && $integrados->isEmpty() ? 'fazer' : 'concluida', 'resumo' => $rascunhos->isEmpty() && $integrados->isEmpty() ? 'Sem meses a amortizar' : 'Calculado'];
            $e['integ'] = $rascunhos->isNotEmpty() ? ['estado' => 'curso', 'resumo' => $rascunhos->count().' mês(es) por integrar', 'periodos' => $ordenar($rascunhos)]
                : ($falta->isNotEmpty() ? ['estado' => $integrados->isNotEmpty() ? 'curso' : 'fazer', 'resumo' => $integrados->isNotEmpty() ? 'Integrado em parte' : 'Depois de calcular']
                    : ($integrados->isNotEmpty() ? ['estado' => 'concluida', 'resumo' => 'Tudo integrado'] : ['estado' => 'fazer', 'resumo' => 'Depois de calcular']));
        }
        $concluidas = count(array_filter($e, fn ($x) => $x['estado'] === 'concluida'));

        return $it + ['ativos' => $ativos->pluck('codigo')->all(), 'etapas' => $e, 'concluidas' => $concluidas,
            'bloqueado' => (bool) array_filter($e, fn ($x) => $x['estado'] === 'bloqueada')];
    }
}
