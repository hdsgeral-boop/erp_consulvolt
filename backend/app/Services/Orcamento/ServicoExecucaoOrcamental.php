<?php

namespace App\Services\Orcamento;

use App\Models\LinhaOrcamento;
use App\Models\OrcamentoAnual;
use App\Models\RubricaOrcamental;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;

/**
 * Execução orçamental: realizado a partir do Diário e mapa orçado × realizado (orcamento_dados.js:298-378,
 * orcamento_ui.js:702-859). Paridade:
 *   - exploração: contas 6/7 (e as de outras classes que uma rubrica liste explicitamente); PROVEITO = C − D, CUSTO = D − C;
 *     contas 6/7 sem rubrica vão para «sem rubrica»;
 *   - tesouraria: por documento, o movimento líquido de 43/45 reparte-se pelas contrapartidas na proporção dos seus
 *     valores; transferências internas 43 ↔ 45 anulam-se; saldo inicial e saldos de fim de mês de disponibilidades;
 *   - filtros UN/CC/projecto do orçamento; classe 9 excluída;
 *   - desvio = real − orçado; favorável acima do orçado nos proveitos/recebimentos e abaixo nos custos/pagamentos;
 *     desvio desfavorável acima de 10 % assinalado.
 * Correcção (ADR-044): os lançamentos de apuramento de resultados excluem-se pelo diário de apuramento (AP-*) — o
 * legado usava o «período 13/14», campo que no backup também guarda ids de processamentos salariais.
 */
final class ServicoExecucaoOrcamental
{
    public function __construct(private readonly ContextoEmpresa $contexto) {}

    /** Lançamento a ignorar (o documento que está a ser validado já está no Diário, dentro da mesma transacção). */
    public ?string $excluirNumeroLan = null;

    private function base(int $ano)
    {
        return DB::table('lancamentos_contabeis as l')->when($this->excluirNumeroLan, fn ($q, $n) => $q->where(fn ($x) => $x->whereNull('l.numero_lan')->orWhere('l.numero_lan', '<>', $n)))->leftJoin('diarios_contabeis as d', 'd.id', '=', 'l.diario_id')
            ->where('l.empresa_id', $this->contexto->obrigatorio())->whereBetween('l.data_documento', ["{$ano}-01-01", "{$ano}-12-31"])
            ->where('l.codigo_conta', 'not like', '9%')->where(fn ($q) => $q->whereNull('d.codigo')->orWhere('d.codigo', 'not like', 'AP-%'));
    }

    private static function filtro($q, OrcamentoAnual $o, string $alias = 'l')
    {
        return $q->when($o->unidade_negocio_id, fn ($x) => $x->where("{$alias}.unidade_negocio_id", $o->unidade_negocio_id))
            ->when($o->centro_custo_id, fn ($x) => $x->where("{$alias}.centro_custo_id", $o->centro_custo_id))
            ->when($o->projeto_id, fn ($x) => $x->where("{$alias}.projeto_id", $o->projeto_id));
    }

    /**
     * @return array{por_rubrica: array<int, list<float>>, sem_rubrica: list<array{conta: string, valores: list<float>}>, saldo_inicial?: float, saldos_fim_mes?: list<float>}
     */
    public function realizado(OrcamentoAnual $o, ?int $ano = null): array
    {
        $ano ??= $o->ano;
        $rubs = RubricaOrcamental::query()->where('tipo', $o->tipo)->where('ativo', true)->get();
        $cache = [];
        $rub = function (string $conta) use ($rubs, &$cache) {
            return $cache[$conta] ??= ServicoRubricasOrcamentais::rubricaDaConta($rubs, $conta) ?? false;
        };
        $porRubrica = [];
        $sem = [];
        $somar = function (&$alvo, $k, int $mes, float $v) {
            $alvo[$k] ??= array_fill(0, 12, 0.0);
            $alvo[$k][$mes] += $v;
        };
        if ($o->tipo === 'EXPLORACAO') {
            $linhas = self::filtro($this->base($ano), $o)
                ->selectRaw("l.codigo_conta AS conta, EXTRACT(MONTH FROM l.data_documento)::int - 1 AS mes, SUM(CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE -l.valor END) AS dc")
                ->groupBy('l.codigo_conta', 'mes')->get();
            foreach ($linhas as $l) {
                $r = $rub((string) $l->conta);
                $dc = (float) $l->dc;
                if (! $r) {
                    if (preg_match('/^[67]/', (string) $l->conta)) {
                        $somar($sem, (string) $l->conta, (int) $l->mes, str_starts_with((string) $l->conta, '6') ? -$dc : $dc);
                    }

                    continue;
                }
                $somar($porRubrica, $r->id, (int) $l->mes, $r->natureza === 'PROVEITO' ? -$dc : $dc);
            }
        } else {
            // documentos com movimento de disponibilidades; chave = n.º de lançamento (ou diário|documento|data)
            $chave = "COALESCE(l.numero_lan, l.diario_id::text || '|' || COALESCE(l.numero_documento, '') || '|' || l.data_documento::text)";
            $docs = $this->base($ano)->where(fn ($q) => $q->where('l.codigo_conta', 'like', '43%')->orWhere('l.codigo_conta', 'like', '45%'))->selectRaw("{$chave} AS chave")->distinct()->pluck('chave');
            $linhas = $this->base($ano)->whereIn(DB::raw($chave), $docs)
                ->get([DB::raw("{$chave} AS chave"), 'l.codigo_conta', 'l.tipo_dc', 'l.valor', 'l.data_documento', 'l.unidade_negocio_id', 'l.centro_custo_id', 'l.projeto_id']);
            foreach ($linhas->groupBy('chave') as $ls) {
                $caixa = $ls->filter(fn ($l) => ServicoRubricasOrcamentais::ehDisponibilidade((string) $l->codigo_conta));
                $liquido = $caixa->sum(fn ($l) => $l->tipo_dc === 'D' ? (float) $l->valor : -(float) $l->valor);
                if (abs($liquido) < 0.005) {
                    continue;   // transferência interna ou sem efeito
                }
                $mes = (int) substr((string) $caixa->first()->data_documento, 5, 2) - 1;
                $contrap = $ls->filter(fn ($l) => ! ServicoRubricasOrcamentais::ehDisponibilidade((string) $l->codigo_conta) && $l->tipo_dc === ($liquido > 0 ? 'C' : 'D'));
                $peso = $contrap->sum(fn ($l) => (float) $l->valor);
                if (! $peso) {
                    $somar($sem, '(sem contrapartida)', $mes, $liquido);

                    continue;
                }
                foreach ($contrap as $l) {
                    if (($o->unidade_negocio_id && (int) $l->unidade_negocio_id !== $o->unidade_negocio_id) || ($o->centro_custo_id && (int) $l->centro_custo_id !== $o->centro_custo_id)
                        || ($o->projeto_id && (int) $l->projeto_id !== $o->projeto_id)) {
                        continue;
                    }
                    $parte = $liquido * (float) $l->valor / $peso;
                    $r = $rub((string) $l->codigo_conta);
                    $r ? $somar($porRubrica, $r->id, $mes, $r->natureza === 'RECEBIMENTO' ? $parte : -$parte) : $somar($sem, (string) $l->codigo_conta, $mes, $parte);
                }
            }
        }
        $arred = fn (array $v) => array_map(fn ($x) => round($x, 2), $v);
        $res = ['por_rubrica' => array_map($arred, $porRubrica),
            'sem_rubrica' => array_values(array_filter(array_map(fn ($k, $v) => ['conta' => (string) $k, 'valores' => $arred($v)], array_keys($sem), $sem),
                fn ($e) => collect($e['valores'])->contains(fn ($x) => abs($x) >= 0.01)))];
        if ($o->tipo === 'TESOURARIA') {
            $disp = fn ($q) => $q->where(fn ($x) => $x->where('l.codigo_conta', 'like', '43%')->orWhere('l.codigo_conta', 'like', '45%'));
            $saldo = (float) $disp(DB::table('lancamentos_contabeis as l')->where('l.empresa_id', $this->contexto->obrigatorio())->where('l.data_documento', '<', "{$ano}-01-01"))
                ->selectRaw("COALESCE(SUM(CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE -l.valor END), 0) AS s")->value('s');
            $res['saldo_inicial'] = round($saldo, 2);
            $mensal = $disp($this->base($ano))->selectRaw("EXTRACT(MONTH FROM l.data_documento)::int - 1 AS mes, SUM(CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE -l.valor END) AS s")
                ->groupBy('mes')->pluck('s', 'mes');
            $res['saldos_fim_mes'] = [];
            for ($m = 0; $m < 12; $m++) {
                $saldo += (float) ($mensal[$m] ?? 0);
                $res['saldos_fim_mes'][] = round($saldo, 2);
            }
        }

        return $res;
    }

    /**
     * Mapa orçado × realizado. vista: MES (só o mês), ACUMULADO (até ao mês) ou ANO.
     *
     * @return array<string, mixed>
     */
    public function controlo(OrcamentoAnual $o, int $mes, string $vista = 'ACUMULADO'): array
    {
        $real = $this->realizado($o);
        $inicial = $this->versaoInicial($o);
        $linhasIniciais = $inicial && $inicial->id !== $o->id ? LinhaOrcamento::query()->where('orcamento_anual_id', $inicial->id)->get()->keyBy('rubrica_orcamental_id') : null;
        $meses = match ($vista) {
            'MES' => [$mes - 1], 'ANO' => range(0, 11), default => range(0, $mes - 1)
        };
        $soma = fn (?array $v) => round(array_sum(array_intersect_key(array_map('floatval', (array) $v), array_flip($meses))), 2);
        $linhas = [];
        $orcadas = LinhaOrcamento::query()->where('orcamento_anual_id', $o->id)->get()->keyBy('rubrica_orcamental_id');
        foreach (RubricaOrcamental::query()->where('tipo', $o->tipo)->orderBy('ordem')->orderBy('codigo')->get() as $r) {
            $orc = $soma($orcadas[$r->id]->valores ?? null);
            $rea = $soma($real['por_rubrica'][$r->id] ?? null);
            if (! $orc && ! $rea && ! $r->ativo) {
                continue;
            }
            $desvio = round($rea - $orc, 2);
            $entrada = in_array($r->natureza, ['PROVEITO', 'RECEBIMENTO'], true);
            $favoravel = $entrada ? $desvio >= 0 : $desvio <= 0;
            $pct = $orc ? round($desvio / abs($orc) * 100, 2) : null;
            $linhas[] = ['rubrica_id' => $r->id, 'codigo' => $r->codigo, 'nome' => $r->nome, 'natureza' => $r->natureza, 'grupo' => $r->grupo,
                'orcado' => $orc, 'orcado_inicial' => $linhasIniciais ? $soma($linhasIniciais[$r->id]->valores ?? null) : $orc, 'realizado' => $rea, 'desvio' => $desvio,
                'execucao_pct' => $orc ? round($rea / $orc * 100, 2) : null, 'desvio_pct' => $pct, 'favoravel' => $favoravel,
                // Decisão 24 do utilizador: realizado desfavorável sem orçamento (pct nulo) conta como desvio desfavorável significativo.
                'sem_orcamento' => ! $orc && $rea != 0.0,
                'desvio_significativo' => ! $favoravel && ($pct === null || abs($pct) > 10),
                'mensal' => ['orcado' => array_map('floatval', (array) ($orcadas[$r->id]->valores ?? array_fill(0, 12, 0))), 'realizado' => $real['por_rubrica'][$r->id] ?? array_fill(0, 12, 0.0)]];
        }
        $piores = collect($linhas)->where('favoravel', false)->sortByDesc(fn ($l) => abs($l['desvio']))->take(5)->values()->all();

        return ['orcamento' => $o->only(['id', 'ano', 'tipo', 'nome', 'versao', 'estado', 'unidade_negocio_id', 'centro_custo_id', 'projeto_id']), 'mes' => $mes, 'vista' => $vista,
            'linhas' => $linhas, 'piores_desvios' => $piores, 'sem_rubrica' => $real['sem_rubrica'],
            'totais' => ['orcado' => round(array_sum(array_column($linhas, 'orcado')), 2), 'realizado' => round(array_sum(array_column($linhas, 'realizado')), 2)]]
            + array_intersect_key($real, array_flip(['saldo_inicial', 'saldos_fim_mes']));
    }

    public function versaoInicial(OrcamentoAnual $o): ?OrcamentoAnual
    {
        $x = $o;
        for ($n = 0; $x->versao_origem_id && $n < 100; $n++) {
            $x = OrcamentoAnual::query()->find($x->versao_origem_id) ?? $x;
        }

        return $x;
    }
}
