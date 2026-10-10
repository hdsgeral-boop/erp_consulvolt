<?php

namespace App\Services\POS;

use App\Models\Produto;
use App\Models\SerieFaturacaoEletronica;
use App\Models\SessaoPOS;
use App\Models\StockArmazem;
use App\Models\TerminalPOS;
use App\Services\Logistica\ServicoArmazens;
use Illuminate\Support\Carbon;

/**
 * Dados da frente de caixa que o catálogo comum (GET /logistica/produtos/catalogo) não traz, para os cartões de produto
 * do legado (renderPOSProductsGrid, ui_sales.js:6893-7044), o aviso das sessões antigas (avisoSessoesAntigas,
 * pos_gestao.js:147) e a série dos Terminais POS (renderTerminais, pos_gestao.js):
 *   - stock de cada produto que movimenta stock no ARMAZÉM do terminal (o mesmo saldo que a venda valida);
 *   - sessões abertas desde dias anteriores, de TODOS os terminais e operadores da empresa;
 *   - série das facturas-recibo de cada terminal no ano corrente.
 * As imagens dos produtos vêm do módulo de Vendas (GET /vendas/produtos/imagens).
 */
final class ServicoFrentePOS
{
    public function __construct(private readonly ServicoArmazens $armazens) {}

    /**
     * Stock dos produtos no armazém do terminal (produtos que não movimentam stock não aparecem: são «Serviço»).
     *
     * @return array{armazem_id: int, stock: array<string, string>}
     */
    public function stock(TerminalPOS $t): array
    {
        $armazem = $t->armazem_id ?: $this->armazens->garantirPredefinido()->id;
        $comStock = Produto::query()->where('movimenta_stock', true)->pluck('id');
        $saldos = StockArmazem::query()->where('armazem_id', $armazem)->whereIn('produto_id', $comStock)->pluck('quantidade_stock', 'produto_id');
        $stock = [];
        foreach ($comStock as $id) {
            $stock[(string) $id] = number_format((float) ($saldos[$id] ?? 0), 3, '.', '');
        }

        return ['armazem_id' => $armazem, 'stock' => $stock];
    }

    /**
     * Sessões abertas antes de hoje (todos os terminais e operadores, como o legado lia a cache de sessões abertas).
     * A versão anterior só via as do próprio operador — por isso T05-2026-0002 (aberta por outro operador) não aparecia.
     *
     * @return list<array<string, mixed>>
     */
    public function sessoesAntigas(): array
    {
        return SessaoPOS::query()->where('estado', 'ABERTA')->where('aberto_em', '<', now()->startOfDay())
            ->orderBy('aberto_em')->orderBy('id')
            ->get(['id', 'terminal_pos_id', 'codigo_sessao', 'codigo_terminal', 'nome_terminal', 'nome_operador', 'aberto_em'])
            ->map(fn (SessaoPOS $s) => $s->only(['id', 'terminal_pos_id', 'codigo_sessao', 'codigo_terminal', 'nome_terminal', 'nome_operador'])
                + ['aberto_em' => $s->aberto_em?->toIso8601String()])
            ->all();
    }

    /** Texto do aviso do legado («Existem 2 sessões de caixa abertas desde dias anteriores: …»), ou nulo. */
    public static function textoAviso(array $antigas): ?string
    {
        if (! $antigas) {
            return null;
        }
        $n = count($antigas);
        $lista = implode(', ', array_map(fn ($s) => sprintf('%s (%s, %s)', $s['codigo_sessao'], $s['nome_terminal'] ?? $s['codigo_terminal'] ?? '—',
            $s['aberto_em'] ? Carbon::parse((string) $s['aberto_em'])->timezone((string) config('app.timezone'))->format('d/m/Y') : '—'), $antigas));

        return ($n === 1 ? 'Existe 1 sessão de caixa aberta' : "Existem {$n} sessões de caixa abertas")
            ." desde dias anteriores: {$lista}. Feche-as para garantir a conferência diária.";
    }

    /**
     * Série das facturas-recibo de cada terminal no ano corrente («Série FR T012026» por baixo do nome, como o legado
     * mostrava «Série FR-POS/T01/2026»). Sem série criada (ainda sem vendas este ano) devolve a que será criada na
     * primeira venda (ServicoSeries::criar: código do terminal sem símbolos + ano), marcada como prevista.
     *
     * @return array<string, array{serie: string, prevista: bool}> por id de terminal
     */
    public function series(): array
    {
        $ano = (int) now()->year;
        $existentes = SerieFaturacaoEletronica::query()->where('tipo', 'FR')->where('ano', $ano)->where('estado', 'ATIVA')
            ->pluck('codigo', 'origem');
        $saida = [];
        foreach (TerminalPOS::query()->orderBy('id')->get(['id', 'codigo']) as $t) {
            $codigo = $existentes[$t->codigo] ?? null;
            $saida[(string) $t->id] = ['serie' => 'FR '.($codigo ?? preg_replace('/[^A-Za-z0-9]/', '', (string) $t->codigo).$ano), 'prevista' => $codigo === null];
        }

        return $saida;
    }
}
