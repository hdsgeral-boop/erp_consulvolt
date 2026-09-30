<?php

namespace App\Services\Logistica;

use App\Exceptions\ErroNegocio;
use App\Models\Armazem;
use App\Models\MovimentoInventario;
use App\Models\Produto;
use App\Models\StockArmazem;
use Illuminate\Support\Facades\DB;

/**
 * Armazéns e mapas de stock (js/ui_warehouse.js). Correcções (ADR-042):
 *   - armazém por omissão explícito (o legado usava «o último activo», que nunca era gravado, e caía no primeiro);
 *   - eliminar só com o stock a zero (o legado apagava e deixava stock e movimentos órfãos);
 *   - extracto do artigo com saldo corrido em quantidade e valor (o legado só listava movimentos);
 *   - alerta de ruptura pelo stock mínimo do produto (o legado usava ≤ 5 fixo);
 *   - a valorização usa o custo médio ponderado real (o legado caía no preço de venda).
 */
final class ServicoArmazens
{
    public function guardar(array $d, ?Armazem $a = null): Armazem
    {
        $d['nome'] = trim((string) $d['nome']);
        if (Armazem::query()->whereRaw('lower(nome) = lower(?)', [$d['nome']])->when($a, fn ($q) => $q->whereKeyNot($a->id))->exists()) {
            throw new ErroNegocio("Já existe o armazém «{$d['nome']}».", 'ARMAZEM_DUPLICADO', 422);
        }

        return DB::transaction(function () use ($d, $a) {
            $predefinido = ! empty($d['predefinido']) || ! Armazem::query()->when($a, fn ($q) => $q->whereKeyNot($a->id))->exists();
            if ($predefinido) {
                Armazem::query()->when($a, fn ($q) => $q->whereKeyNot($a->id))->update(['predefinido' => false]);
            }
            $d['predefinido'] = $predefinido;
            $a ? $a->update($d) : $a = Armazem::create($d);

            return $a->refresh();
        });
    }

    public function eliminar(Armazem $a): void
    {
        if (StockArmazem::query()->where('armazem_id', $a->id)->where('quantidade_stock', '<>', 0)->exists()) {
            throw new ErroNegocio("O armazém {$a->nome} tem stock: transfira-o ou regularize-o antes de o eliminar.", 'REGISTO_EM_USO', 422);
        }
        if ($a->predefinido && Armazem::query()->whereKeyNot($a->id)->exists()) {
            throw new ErroNegocio('Defina outro armazém como predefinido antes de eliminar este.', 'ARMAZEM_PREDEFINIDO', 422);
        }
        $a->delete();   // eliminação lógica: o histórico de movimentos mantém-se
    }

    public function predefinido(): ?Armazem
    {
        return Armazem::query()->where('predefinido', true)->first() ?? Armazem::query()->orderBy('id')->first();
    }

    /** O predefinido; uma empresa sem armazéns recebe o «Armazém principal» (a venda de artigos de stock não fica bloqueada). */
    public function garantirPredefinido(): Armazem
    {
        return $this->predefinido() ?? Armazem::create(['nome' => 'Armazém principal', 'codigo' => 'PRINCIPAL', 'predefinido' => true]);
    }

    /** Stock por armazém e produto, com custo médio, valor e alerta de ruptura. */
    public function stock(?int $armazem = null, bool $soComStock = false): array
    {
        return StockArmazem::query()->join('produtos as p', 'p.id', '=', 'stock_armazem.produto_id')->join('armazens as a', 'a.id', '=', 'stock_armazem.armazem_id')
            ->when($armazem, fn ($q) => $q->where('stock_armazem.armazem_id', $armazem))
            ->when($soComStock, fn ($q) => $q->where('stock_armazem.quantidade_stock', '<>', 0))
            ->where('stock_armazem.empresa_id', DB::raw('p.empresa_id'))->whereNull('p.eliminado_em')
            ->orderBy('a.nome')->orderBy('p.codigo')
            ->get(['stock_armazem.armazem_id', 'a.nome as armazem', 'p.id as produto_id', 'p.codigo', 'p.nome', 'stock_armazem.quantidade_stock', 'p.custo_medio', 'p.stock_minimo'])
            ->map(fn ($x) => [
                'armazem_id' => $x->armazem_id, 'armazem' => $x->armazem, 'produto_id' => $x->produto_id, 'codigo' => $x->codigo, 'nome' => $x->nome,
                'quantidade' => (string) $x->quantidade_stock, 'custo_medio' => (string) ($x->custo_medio ?? '0'),
                'valor' => number_format(round((float) $x->quantidade_stock * (float) ($x->custo_medio ?? 0), 2), 2, '.', ''),
                'stock_minimo' => $x->stock_minimo !== null ? (string) $x->stock_minimo : null,
                'ruptura' => $x->stock_minimo !== null && (float) $x->quantidade_stock <= (float) $x->stock_minimo,
            ])->all();
    }

    /** Valorização total e por armazém. */
    public function valorizacao(): array
    {
        $linhas = collect($this->stock());
        $porArmazem = $linhas->groupBy('armazem_id')->map(fn ($g) => ['armazem' => $g->first()['armazem'], 'valor' => number_format($g->sum(fn ($x) => (float) $x['valor']), 2, '.', ''),
            'produtos' => $g->where('quantidade', '<>', '0.000')->count()])->values()->all();

        return ['total' => number_format($linhas->sum(fn ($x) => (float) $x['valor']), 2, '.', ''), 'por_armazem' => $porArmazem,
            'rupturas' => $linhas->where('ruptura', true)->count()];
    }

    /** Extracto do artigo (kardex): saldo inicial + movimentos com saldo corrido em quantidade e valor. */
    public function extracto(int $produto, ?int $armazem, string $de, string $ate): array
    {
        $p = Produto::query()->findOrFail($produto);
        $base = MovimentoInventario::query()->where('produto_id', $p->id)->when($armazem, fn ($q) => $q->where('armazem_id', $armazem));
        $sinal = "CASE WHEN COALESCE(sentido, CASE WHEN tipo = 'SAIDA' THEN 'S' ELSE 'E' END) = 'S' THEN -1 ELSE 1 END";
        $inicial = (clone $base)->where('data', '<', $de)->selectRaw("COALESCE(SUM({$sinal} * quantidade), 0) AS q, COALESCE(SUM({$sinal} * COALESCE(valor, quantidade * preco_unitario)), 0) AS v")->first();
        [$q, $v] = [(string) $inicial->q, (string) $inicial->v];
        $linhas = [];
        foreach ((clone $base)->whereBetween('data', [$de, $ate.' 23:59:59'])->orderBy('data')->orderBy('id')->get() as $m) {
            $s = ($m->sentido ?? ($m->tipo === 'SAIDA' ? 'S' : 'E')) === 'S' ? '-' : '';
            $valor = (string) ($m->valor ?? bcmul((string) $m->quantidade, (string) $m->preco_unitario, 2));
            $q = bcadd($q, $s.$m->quantidade, 3);
            $v = bcadd($v, $s.$valor, 2);
            $linhas[] = ['id' => $m->id, 'data' => $m->data?->toDateString(), 'tipo' => $m->tipo, 'sentido' => $s ? 'S' : 'E', 'armazem_id' => $m->armazem_id,
                'referencia' => $m->referencia, 'documento_tipo' => $m->documento_tipo, 'documento_id' => $m->documento_id,
                'entrada' => $s ? null : (string) $m->quantidade, 'saida' => $s ? (string) $m->quantidade : null, 'custo_unitario' => (string) $m->preco_unitario,
                'valor' => $valor, 'saldo_quantidade' => $q, 'saldo_valor' => $v];
        }

        return ['produto' => $p->only(['id', 'codigo', 'nome', 'custo_medio', 'quantidade_stock']), 'armazem_id' => $armazem, 'de' => $de, 'ate' => $ate,
            'saldo_inicial' => ['quantidade' => (string) $inicial->q, 'valor' => number_format((float) $inicial->v, 2, '.', '')], 'movimentos' => $linhas,
            'saldo_final' => ['quantidade' => $q, 'valor' => $v]];
    }
}
