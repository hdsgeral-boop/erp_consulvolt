<?php

namespace App\Services\Compras;

use App\Exceptions\ErroNegocio;
use App\Models\EncomendaCompra;
use App\Models\FaturaCompra;
use App\Models\ItemCompra;
use App\Models\Produto;
use App\Services\Contabilidade\ServicoExercicios;
use App\Services\Orcamento\ServicoControloOrcamental;
use App\Services\Sistema\ServicoCambios;
use App\Services\Vendas\CalculadoraDocumento;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;

/**
 * Facturas de fornecedor (savePurchaseInvoice / saveDirectPurchaseInvoice, js/ui_compras_v2.js:1631-2049), corrigidas:
 *   - n.º da factura único por fornecedor (o legado aceitava duplicados);
 *   - quantidades limitadas ao que falta facturar em cada linha da encomenda, gravadas NA MESMA transacção
 *     (o legado gravava invoiced_qty antes da factura, sem transacção, e por product_id);
 *   - exercício aberto também na factura directa;
 *   - a factura directa não aceita artigos de stock: estes passam por encomenda e recepção
 *     (no legado debitava a 21 sem entrada em stock e a conta ficava em aberto);
 *   - valor da conta transitória (328) por linha: consome primeiro o recebido e ainda não facturado (ao valor da
 *     recepção) e o resto fica "facturado por receber" (moedas_compras.js:226-243); a diferença para o valor da
 *     factura é diferença de câmbio na contabilização;
 *   - anulação com motivo (nunca apagar; o legado apagava a factura E o lançamento sem salvaguardas).
 */
final class ServicoFaturasCompra
{
    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoExercicios $exercicios,
        private readonly ServicoCambios $cambios,
        private readonly ServicoProcessoCompras $processo,
    ) {}

    /** @param  array{numero_fatura: string, data: string, data_vencimento?: ?string, taxa_cambio?: ?float, linhas: list<array{item_encomenda_id: int, quantidade: float|string, taxa_imposto?: float|string|null}>}  $d */
    public function registarDaEncomenda(EncomendaCompra $encomenda, array $d): FaturaCompra
    {
        $empresa = $this->contexto->obrigatorio();
        $data = substr($d['data'], 0, 10);
        $this->exercicios->exigirAberto($empresa, $data);

        return DB::transaction(function () use ($encomenda, $d, $data, $empresa) {
            $encomenda = EncomendaCompra::query()->lockForUpdate()->findOrFail($encomenda->id);
            if ($encomenda->estado === 'ANULADA') {
                throw new ErroNegocio('A encomenda está anulada.', 'ENCOMENDA_ANULADA', 422);
            }
            $this->exigirNumeroLivre($encomenda->fornecedor_id, $d['numero_fatura']);
            $moeda = CalculadoraCompra::moeda($this->cambios, $empresa, $encomenda->codigo_moeda, isset($d['taxa_cambio']) ? (string) $d['taxa_cambio'] : null, $data);
            $itens = ItemCompra::query()->where('encomenda_compra_id', $encomenda->id)->with('produto')->lockForUpdate()->get()->keyBy('id');
            $linhas = [];
            foreach (collect($d['linhas'])->filter(fn ($l) => (float) $l['quantidade'] > 0)->values() as $n => $l) {
                $item = $itens[$l['item_encomenda_id']] ?? throw new ErroNegocio('Linha '.($n + 1).': não pertence à encomenda.', 'LINHA_INVALIDA', 422);
                $pendente = bcsub((string) $item->quantidade, (string) ($item->quantidade_faturada ?? 0), 3);
                if (bccomp((string) $l['quantidade'], $pendente, 3) > 0) {
                    throw new ErroNegocio("Linha {$item->descricao}: factura {$l['quantidade']} mas só faltam facturar {$pendente}.", 'QUANTIDADE_SUPERIOR_PENDENTE', 422,
                        ['item_encomenda_id' => $item->id, 'pendente' => $pendente]);
                }
                $linhas[] = ['item' => $item, 'quantidade' => (string) $l['quantidade'], 'taxa_imposto' => (string) ($l['taxa_imposto'] ?? $item->taxa_imposto ?? 0),
                    'preco_unitario' => (string) ($moeda['estrangeira'] && $item->preco_unitario_moeda !== null ? $item->preco_unitario_moeda : $item->preco_unitario)];
            }
            if (! $linhas) {
                throw new ErroNegocio('Indique pelo menos uma quantidade a facturar.', 'SEM_QUANTIDADES', 422);
            }
            $calc = CalculadoraCompra::calcular($linhas, $moeda['taxa']);
            $fatura = $this->criarCabecalho($encomenda->fornecedor_id, $encomenda, $d, $data, $moeda, $calc);

            foreach ($linhas as $i => $l) {
                $item = $l['item'];
                $c = $calc['linhas'][$i];
                $transitoria = $item->produto?->movimenta_stock ? $this->consumirTransitoria($item, $l['quantidade'], $c['liquido_kz']) : null;
                ItemCompra::create($this->processo->linha('FATURA', ['fatura_compra_id' => $fatura->id], $l, $c, $moeda) + [
                    'item_encomenda_id' => $item->id, 'projeto_id' => $item->projeto_id,
                    'valor_transitoria_kz' => $transitoria['valor'] ?? null,
                    'cambial_recebido_por_faturar_qtd' => $transitoria['qa'] ?? null, 'cambial_recebido_por_faturar_kz' => $transitoria['va'] ?? null,
                    'cambial_faturado_por_receber_qtd' => $transitoria['qb'] ?? null, 'cambial_faturado_por_receber_kz' => $transitoria['vb'] ?? null,
                ]);
                $item->update(['quantidade_faturada' => bcadd((string) ($item->quantidade_faturada ?? 0), $l['quantidade'], 3)]);
            }

            return $fatura->refresh();
        });
    }

    /** @param  array{fornecedor_id: int, numero_fatura: string, data: string, codigo_moeda?: ?string, taxa_cambio?: ?float, linhas: list<array{produto_id: int, quantidade: float|string, preco_unitario: float|string, taxa_imposto?: float|string|null, descricao?: ?string}>}  $d */
    public function registarDireta(array $d): FaturaCompra
    {
        $empresa = $this->contexto->obrigatorio();
        $data = substr($d['data'], 0, 10);
        $this->exercicios->exigirAberto($empresa, $data);   // o legado não verificava
        $fornecedor = $this->processo->fornecedor($d['fornecedor_id']);
        $moeda = CalculadoraCompra::moeda($this->cambios, $empresa, $d['codigo_moeda'] ?? $fornecedor->codigo_moeda, isset($d['taxa_cambio']) ? (string) $d['taxa_cambio'] : null, $data);
        $produtos = Produto::query()->whereIn('id', array_column($d['linhas'], 'produto_id'))->get()->keyBy('id');
        $linhas = [];
        foreach ($d['linhas'] as $n => $l) {
            $p = $produtos[$l['produto_id']] ?? throw new ErroNegocio('Linha '.($n + 1).': produto inexistente.', 'PRODUTO_INEXISTENTE', 422);
            if ($p->movimenta_stock) {
                throw new ErroNegocio('Linha '.($n + 1).": {$p->codigo} é artigo de stock — registe-o por encomenda e recepção.", 'FATURA_DIRETA_COM_STOCK', 422);
            }
            if ((float) $l['quantidade'] <= 0 || (float) $l['preco_unitario'] < 0) {
                throw new ErroNegocio('Linha '.($n + 1).': quantidade e preço inválidos.', 'LINHA_INVALIDA', 422);
            }
            $linhas[] = ['produto_id' => $p->id, 'descricao' => $l['descricao'] ?? $p->nome, 'quantidade' => (string) $l['quantidade'],
                'preco_unitario' => number_format((float) $l['preco_unitario'], 2, '.', ''), 'taxa_imposto' => (string) ($l['taxa_imposto'] ?? $p->taxa_imposto ?? 0)];
        }
        if (! $linhas) {
            throw new ErroNegocio('Indique pelo menos uma linha.', 'SEM_LINHAS', 422);
        }
        $calc = CalculadoraCompra::calcular($linhas, $moeda['taxa']);

        return DB::transaction(function () use ($d, $fornecedor, $data, $moeda, $calc, $linhas) {
            $this->exigirNumeroLivre($fornecedor->id, $d['numero_fatura']);
            $fatura = $this->criarCabecalho($fornecedor->id, null, $d, $data, $moeda, $calc);
            foreach ($linhas as $i => $l) {
                ItemCompra::create($this->processo->linha('FATURA', ['fatura_compra_id' => $fatura->id], $l, $calc['linhas'][$i], $moeda) + ['projeto_id' => $d['projeto_id'] ?? null]);
            }
            $controlo = app(ServicoControloOrcamental::class);
            $controlo->avaliar('EXPLORACAO', ['origem' => 'FATURA_FORNECEDOR', 'documento' => "{$fornecedor->id}/{$d['numero_fatura']}", 'data' => $data],
                $controlo->linhasCompra(ItemCompra::query()->where('fatura_compra_id', $fatura->id)->get(), $fatura), ['fatura_compra_id' => $fatura->id]);

            return $fatura->refresh();
        });
    }

    public function anular(FaturaCompra $fatura, string $motivo): FaturaCompra
    {
        return DB::transaction(function () use ($fatura, $motivo) {
            $fatura = FaturaCompra::query()->lockForUpdate()->findOrFail($fatura->id);
            if ($fatura->estado === 'ANULADA') {
                throw new ErroNegocio('A factura já está anulada.', 'JA_ANULADO', 422);
            }
            if ($fatura->contabilizado) {
                throw new ErroNegocio('A factura está contabilizada: descontabilize-a primeiro (estorno).', 'FATURA_CONTABILIZADA', 422);
            }
            if (in_array($fatura->estado, ['PAGO', 'PARCIAL'], true)) {
                throw new ErroNegocio('A factura tem pagamentos.', 'FATURA_COM_PAGAMENTOS', 422);
            }
            foreach (ItemCompra::query()->where('fatura_compra_id', $fatura->id)->get() as $l) {
                if (! $l->item_encomenda_id) {
                    continue;
                }
                $item = ItemCompra::query()->lockForUpdate()->findOrFail($l->item_encomenda_id);
                $fatQ = (string) ($item->cambial_faturado_por_receber_qtd ?? 0);
                if (bccomp($fatQ, (string) ($l->cambial_faturado_por_receber_qtd ?? 0), 3) < 0) {
                    throw new ErroNegocio('Já houve recepções valorizadas por esta factura: reverta primeiro essas validações.', 'FATURA_COM_RECECOES_POSTERIORES', 422);
                }
                $item->update([
                    'quantidade_faturada' => bcsub((string) $item->quantidade_faturada, (string) $l->quantidade, 3),
                    'cambial_recebido_por_faturar_qtd' => bcadd((string) ($item->cambial_recebido_por_faturar_qtd ?? 0), (string) ($l->cambial_recebido_por_faturar_qtd ?? 0), 3),
                    'cambial_recebido_por_faturar_kz' => bcadd((string) ($item->cambial_recebido_por_faturar_kz ?? 0), (string) ($l->cambial_recebido_por_faturar_kz ?? 0), 2),
                    'cambial_faturado_por_receber_qtd' => bcsub($fatQ, (string) ($l->cambial_faturado_por_receber_qtd ?? 0), 3),
                    'cambial_faturado_por_receber_kz' => bcsub((string) ($item->cambial_faturado_por_receber_kz ?? 0), (string) ($l->cambial_faturado_por_receber_kz ?? 0), 2),
                ]);
            }
            $fatura->update(['estado' => 'ANULADA', 'anulado_em' => now(), 'motivo_anulacao' => $motivo]);

            return $fatura;
        });
    }

    /** @return array{valor: string, qa: string, va: string, qb: string, vb: string} */
    private function consumirTransitoria(ItemCompra $item, string $q, string $liquidoKz): array
    {
        $recQ = (string) ($item->cambial_recebido_por_faturar_qtd ?? 0);
        $recV = (string) ($item->cambial_recebido_por_faturar_kz ?? 0);
        $qa = bccomp($q, $recQ, 3) < 0 ? $q : $recQ;
        $va = bccomp($qa, '0', 3) > 0 ? CalculadoraDocumento::arredondar(bcdiv(bcmul($recV, $qa, 8), $recQ, 8)) : '0.00';
        $qb = bcsub($q, $qa, 3);
        $vb = bccomp($qb, '0', 3) > 0 ? CalculadoraDocumento::arredondar(bcdiv(bcmul($liquidoKz, $qb, 8), $q, 8)) : '0.00';
        $item->update([
            'cambial_recebido_por_faturar_qtd' => bcsub($recQ, $qa, 3), 'cambial_recebido_por_faturar_kz' => bcsub($recV, $va, 2),
            'cambial_faturado_por_receber_qtd' => bcadd((string) ($item->cambial_faturado_por_receber_qtd ?? 0), $qb, 3),
            'cambial_faturado_por_receber_kz' => bcadd((string) ($item->cambial_faturado_por_receber_kz ?? 0), $vb, 2),
        ]);

        return ['valor' => bcadd($va, $vb, 2), 'qa' => $qa, 'va' => $va, 'qb' => $qb, 'vb' => $vb];
    }

    private function criarCabecalho(int $fornecedorId, ?EncomendaCompra $encomenda, array $d, string $data, array $moeda, array $calc): FaturaCompra
    {
        return FaturaCompra::create([
            'encomenda_compra_id' => $encomenda?->id, 'fornecedor_id' => $fornecedorId, 'numero_fatura' => trim($d['numero_fatura']), 'data' => $data,
            'data_vencimento' => $d['data_vencimento'] ?? null, 'montante_total' => $calc['bruto_kz'], 'total_imposto' => $calc['imposto_kz'],
            'estado' => 'PENDENTE', 'contabilizado' => false, 'projeto_id' => $encomenda?->projeto_id ?? ($d['projeto_id'] ?? null),
            'unidade_negocio_id' => $encomenda?->unidade_negocio_id ?? ($d['unidade_negocio_id'] ?? null),
            'centro_custo_id' => $encomenda?->centro_custo_id ?? ($d['centro_custo_id'] ?? null),
            'codigo_moeda' => $moeda['codigo'], 'taxa_cambio' => $moeda['estrangeira'] ? $moeda['taxa'] : null, 'taxa_cambio_id' => $moeda['taxa_id'],
            'taxa_cambio_manual' => $moeda['manual'], 'montante_total_moeda' => $moeda['estrangeira'] ? $calc['bruto'] : null,
            'total_imposto_moeda' => $moeda['estrangeira'] ? $calc['imposto'] : null,
        ]);
    }

    private function exigirNumeroLivre(int $fornecedorId, string $numero): void
    {
        // lock transaccional por (fornecedor, n.º): duas gravações simultâneas do mesmo n.º não passam ambas
        DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ["fatura_compra:{$fornecedorId}:".mb_strtoupper(trim($numero))]);
        $existe = FaturaCompra::query()->where('fornecedor_id', $fornecedorId)->whereRaw('upper(trim(numero_fatura)) = upper(trim(?))', [$numero])
            ->where(fn ($q) => $q->whereNull('estado')->orWhere('estado', '<>', 'ANULADA'))->exists();
        if ($existe) {
            throw new ErroNegocio("Já existe a factura {$numero} deste fornecedor.", 'FATURA_DUPLICADA', 422);
        }
    }
}
