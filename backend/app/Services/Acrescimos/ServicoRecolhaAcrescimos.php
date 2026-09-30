<?php

namespace App\Services\Acrescimos;

use App\Exceptions\ErroNegocio;
use App\Models\FaturaCompra;
use App\Models\ItemAcrescimoDiferimento;
use App\Models\ItemCompra;
use App\Models\LancamentoContabil;
use App\Models\Terceiro;
use App\Models\Venda;
use App\Services\Vendas\ServicoContabilizacaoVendas;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Recolha de documentos candidatos a acréscimo/diferimento (candidatos e acrescimosParaRegularizar, ad_dados.js:382-468):
 *   - COMPRA: facturas de fornecedor (gastos, contas 7); VENDA: facturas e facturas-recibo (rendimentos, contas 6);
 *     DIARIO: lançamentos com contas 6/7 ou lançados directamente nas contas 37 das definições (em_balanco);
 *     TESOURARIA: pagamentos (contas 7) e recebimentos (contas 6).
 *   - documento contabilizado: valores das linhas reais do Diário; por contabilizar: das linhas do documento;
 *   - `ligado` indica que o documento já é origem ou regularização de um registo.
 * Correcções: documentos anulados e lançamentos estornados (ou estornos) ficam de fora; o lançamento de uma factura é
 * localizado pelo N.º do lançamento gravado no documento (o legado usava só o N.º do documento, reutilizável);
 * nas compras por contabilizar a conta de gasto é a conta de custo do produto (conta_custo/conta_compra, como a
 * contabilização das facturas de fornecedor — ServicoContabilizacaoCompras); artigos de stock e imobilizado ficam de fora.
 */
final class ServicoRecolhaAcrescimos
{
    public const FONTES = ['COMPRA', 'VENDA', 'DIARIO', 'TESOURARIA'];

    public function __construct(
        private readonly ServicoDefinicoesAcrescimos $definicoes,
        private readonly ServicoContabilizacaoVendas $vendas,
        private readonly ContextoEmpresa $contexto,
    ) {}

    /**
     * @param  array{de?: ?string, ate?: ?string, texto?: ?string}  $f
     * @return list<array<string, mixed>>
     */
    public function candidatos(string $fonte, array $f): array
    {
        if (! in_array($fonte, self::FONTES, true)) {
            throw new ErroNegocio('Fonte inválida.', 'FONTE_INVALIDA', 422);
        }
        $de = $f['de'] ?? now()->startOfYear()->toDateString();
        $ate = $f['ate'] ?? now()->toDateString();
        $ligados = [];
        foreach (ItemAcrescimoDiferimento::query()->get(['id', 'origem', 'regularizacao']) as $it) {
            foreach ([$it->origem, $it->regularizacao] as $o) {
                if (! empty($o['fonte'])) {
                    $ligados["{$o['fonte']}|".($o['id'] ?? '')] = $it->id;
                }
            }
        }
        $res = match ($fonte) {
            'COMPRA' => $this->compras($de, $ate),
            'VENDA' => $this->vendasFaturas($de, $ate),
            'DIARIO' => $this->diario($de, $ate),
            'TESOURARIA' => $this->tesouraria($de, $ate),
        };
        $nomes = Terceiro::query()->withTrashed()->whereIn('id', collect($res)->pluck('terceiro_id')->filter()->unique()->values())->pluck('nome', 'id');
        $txt = mb_strtolower(trim((string) ($f['texto'] ?? '')));
        $saida = [];
        foreach ($res as $r) {
            if (! $r['linhas']) {
                continue;
            }
            $r['terceiro'] = $nomes[$r['terceiro_id'] ?? 0] ?? '';
            $r['total'] = array_reduce($r['linhas'], fn ($s, $l) => bcadd($s, $l['valor'], 2), '0.00');
            $r['ligado'] = isset($ligados["{$r['fonte']}|{$r['id']}"]);
            if ($txt !== '' && ! collect([$r['doc'], $r['terceiro'], $r['descricao'] ?? '', ...array_column($r['linhas'], 'conta')])
                ->contains(fn ($x) => str_contains(mb_strtolower((string) $x), $txt))) {
                continue;
            }
            $saida[] = $r;
        }
        usort($saida, fn ($a, $b) => strcmp($b['data'], $a['data']));

        return $saida;
    }

    /**
     * Acréscimos em aberto (ACTIVO) da mesma natureza que o documento pode regularizar; primeiro os do mesmo terceiro
     * e depois os da mesma conta de resultados.
     *
     * @param  list<string>  $contas
     */
    public function acrescimosParaRegularizar(string $natureza, ?int $terceiroId, array $contas): array
    {
        return ItemAcrescimoDiferimento::query()->where('tipo', 'ACRESCIMO')->where('estado', 'ACTIVO')->where('natureza', $natureza)->orderBy('data_inicio')->get()
            ->map(fn ($it) => ['item' => $it, 'afinidade' => ($terceiroId && (int) $it->terceiro_id === $terceiroId ? 2 : 0) + (in_array($it->conta_resultado, $contas, true) ? 1 : 0)])
            ->sortBy([['afinidade', 'desc'], [fn ($x) => $x['item']->data_inicio->toDateString(), 'asc']])->values()->all();
    }

    /** Agrupa [conta, valor] por conta (ignora zeros). */
    private function agrupar(iterable $linhas): array
    {
        $m = [];
        foreach ($linhas as [$conta, $valor]) {
            if (bccomp($valor, '0', 2) === 0) {
                continue;
            }
            $m[$conta] = bcadd($m[$conta] ?? '0.00', $valor, 2);
        }

        return array_values(array_map(fn ($c, $v) => ['conta' => (string) $c, 'valor' => $v], array_keys($m), $m));
    }

    /** Linhas activas (não estornadas nem estornos) de um lançamento de documento. */
    private function linhasDiario(?string $numeroLan, ?string $numeroDocumento, ?int $terceiroId): Collection
    {
        $q = LancamentoContabil::query()->whereNull('estorno_de_id')->whereNull('estornado_por_id');
        if ($numeroLan) {
            return $q->where('numero_lan', $numeroLan)->get();
        }

        return $numeroDocumento ? $q->where('numero_documento', $numeroDocumento)->where(fn ($x) => $x->whereNull('terceiro_id')->orWhere('terceiro_id', $terceiroId))->get() : collect();
    }

    private function compras(string $de, string $ate): array
    {
        $r = [];
        foreach (FaturaCompra::query()->whereBetween('data', [$de, $ate])->whereNull('anulado_em')->where(fn ($q) => $q->whereNull('estado')->orWhere('estado', '<>', 'ANULADA'))->orderBy('id')->get() as $fc) {
            if ($fc->contabilizado) {
                $linhas = $this->agrupar($this->linhasDiario($fc->numero_lan_contabilizacao, $fc->numero_fatura, $fc->fornecedor_id)
                    ->filter(fn ($l) => str_starts_with($l->codigo_conta, '7'))->map(fn ($l) => [$l->codigo_conta, $l->tipo_dc === 'D' ? (string) $l->valor : bcmul((string) $l->valor, '-1', 2)]));
            } else {
                $linhas = $this->agrupar(ItemCompra::query()->where('fatura_compra_id', $fc->id)->with(['produto' => fn ($q) => $q->withTrashed()])->orderBy('id')->get()
                    ->filter(fn ($i) => ! $i->produto?->movimenta_stock && ! $i->produto?->e_ativo_imobilizado)
                    ->map(fn ($i) => [(string) ($i->produto?->conta_custo ?: $i->produto?->conta_compra), CalculadoraAcrescimos::dinheiro((string) ($i->total_kz ?? $i->total ?? 0))])
                    ->filter(fn ($x) => str_starts_with($x[0], '7')));
            }
            $r[] = ['fonte' => 'COMPRA', 'id' => $fc->id, 'doc' => $fc->numero_fatura ?: "#{$fc->id}", 'data' => $fc->data->toDateString(), 'terceiro_id' => $fc->fornecedor_id,
                'natureza' => 'CUSTO', 'contabilizado' => (bool) $fc->contabilizado, 'unidade_negocio_id' => $fc->unidade_negocio_id, 'centro_custo_id' => $fc->centro_custo_id,
                'projeto_id' => $fc->projeto_id, 'linhas' => $linhas];
        }

        return $r;
    }

    private function vendasFaturas(string $de, string $ate): array
    {
        $r = [];
        $vendas = Venda::query()->whereIn('tipo_documento', ['FT', 'FR'])->where(fn ($q) => $q->whereNull('estado')->orWhere('estado', '<>', 'ANULADO'))
            ->whereDate('data_emissao', '>=', $de)->whereDate('data_emissao', '<=', $ate)->orderBy('id')->get();
        foreach ($vendas as $v) {
            if ($v->contabilizado) {
                $linhas = $this->agrupar($this->linhasDiario($v->numero_lan_contabilizacao, $v->numero_documento, $v->cliente_id)
                    ->filter(fn ($l) => str_starts_with($l->codigo_conta, '6'))->map(fn ($l) => [$l->codigo_conta, $l->tipo_dc === 'C' ? (string) $l->valor : bcmul((string) $l->valor, '-1', 2)]));
            } else {
                try {
                    $linhas = $this->agrupar(collect($this->vendas->linhasProveitoEIva($v))->filter(fn ($c) => str_starts_with($c['conta'], '6'))->map(fn ($c) => [$c['conta'], $c['valor']]));
                } catch (ErroNegocio) {
                    $linhas = [];
                }
            }
            $r[] = ['fonte' => 'VENDA', 'id' => $v->id, 'doc' => "{$v->tipo_documento} {$v->numero_documento}", 'data' => $v->data_emissao->toDateString(), 'terceiro_id' => $v->cliente_id,
                'natureza' => 'PROVEITO', 'contabilizado' => (bool) $v->contabilizado, 'unidade_negocio_id' => $v->unidade_negocio_id, 'centro_custo_id' => $v->centro_custo_id,
                'projeto_id' => $v->projeto_id, 'linhas' => $linhas];
        }

        return $r;
    }

    private function diario(string $de, string $ate): array
    {
        $contas37 = array_values($this->definicoes->obter()['contas']);
        $linhas = LancamentoContabil::query()->whereNull('item_acrescimo_diferimento_id')->whereNull('estorno_de_id')->whereNull('estornado_por_id')
            ->whereBetween('data_documento', [$de, $ate])
            ->where(fn ($q) => $q->where('codigo_conta', 'like', '6%')->orWhere('codigo_conta', 'like', '7%')->orWhereIn('codigo_conta', $contas37))
            ->orderBy('id')->get();
        $r = [];
        foreach ($linhas->groupBy(fn ($l) => $l->numero_lan ?: "{$l->diario_id}|{$l->numero_documento}|{$l->data_documento->toDateString()}") as $k => $g) {
            $l0 = $g->first();
            $sinal = fn ($l, $dc) => $l->tipo_dc === $dc ? (string) $l->valor : bcmul((string) $l->valor, '-1', 2);
            $base = ['fonte' => 'DIARIO', 'id' => (string) $k, 'doc' => $l0->numero_documento ?: ($l0->numero_lan ?? ''), 'lan' => $l0->numero_lan, 'data' => $l0->data_documento->toDateString(),
                'terceiro_id' => $l0->terceiro_id, 'descricao' => $l0->descricao ?? '', 'contabilizado' => true, 'unidade_negocio_id' => $l0->unidade_negocio_id,
                'centro_custo_id' => $l0->centro_custo_id, 'projeto_id' => $l0->projeto_id];
            $custos = $this->agrupar($g->filter(fn ($l) => str_starts_with($l->codigo_conta, '7'))->map(fn ($l) => [$l->codigo_conta, $sinal($l, 'D')]));
            $proveitos = $this->agrupar($g->filter(fn ($l) => str_starts_with($l->codigo_conta, '6'))->map(fn ($l) => [$l->codigo_conta, $sinal($l, 'C')]));
            $em37 = $this->agrupar($g->filter(fn ($l) => in_array($l->codigo_conta, $contas37, true))->map(fn ($l) => [$l->codigo_conta, $sinal($l, 'D')]));
            if ($custos) {
                $r[] = $base + ['natureza' => 'CUSTO', 'linhas' => $custos];
            }
            if ($proveitos) {
                $r[] = $base + ['natureza' => 'PROVEITO', 'linhas' => $proveitos];
            }
            if ($em37) {
                $r[] = $base + ['natureza' => bccomp($em37[0]['valor'], '0', 2) > 0 ? 'CUSTO' : 'PROVEITO', 'em_balanco' => true,
                    'linhas' => array_map(fn ($x) => ['conta' => $x['conta'], 'valor' => ltrim($x['valor'], '-')], $em37)];
            }
        }

        return $r;
    }

    private function tesouraria(string $de, string $ate): array
    {
        $empresa = $this->contexto->obrigatorio();
        $docs = DB::table('documentos_tesouraria')->where('empresa_id', $empresa)->whereBetween('data_documento', [$de, $ate])
            ->where(fn ($q) => $q->whereNull('estado')->orWhere('estado', '<>', 'ANULADO'))->orderBy('id')->get();
        $itens = DB::table('itens_documento_tesouraria')->where('empresa_id', $empresa)->whereIn('documento_tesouraria_id', $docs->pluck('id'))->orderBy('id')->get()->groupBy('documento_tesouraria_id');
        $r = [];
        foreach ($docs as $d) {
            $its = $itens[$d->id] ?? collect();
            $pag = $d->tipo === 'PAGAMENTO';
            $linhas = $this->agrupar($its->filter(fn ($i) => str_starts_with((string) $i->codigo_conta, $pag ? '7' : '6'))
                ->map(fn ($i) => [(string) $i->codigo_conta, $i->tipo_dc === ($pag ? 'C' : 'D') ? bcmul((string) $i->valor, '-1', 2) : CalculadoraAcrescimos::dinheiro((string) $i->valor)]));
            if (! $linhas) {
                continue;
            }
            $i0 = $its->first();
            $r[] = ['fonte' => 'TESOURARIA', 'id' => $d->id, 'doc' => ($pag ? 'Pagamento ' : 'Recebimento ').($d->referencia ?: $d->id), 'data' => substr((string) $d->data_documento, 0, 10),
                'terceiro_id' => $i0?->terceiro_id, 'descricao' => $d->descricao ?? '', 'natureza' => $pag ? 'CUSTO' : 'PROVEITO', 'contabilizado' => $d->estado === 'INTEGRADO',
                'unidade_negocio_id' => $i0?->unidade_negocio_id, 'centro_custo_id' => $i0?->centro_custo_id, 'projeto_id' => $d->projeto_id, 'linhas' => $linhas];
        }

        return $r;
    }
}
