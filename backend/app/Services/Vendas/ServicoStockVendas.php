<?php

namespace App\Services\Vendas;

use App\Models\ItemVenda;
use App\Models\Produto;
use App\Models\Venda;
use App\Services\Logistica\ServicoArmazens;
use App\Services\Logistica\ServicoConfigLogistica;
use App\Services\Logistica\ServicoStock;

/**
 * Stock e custo das vendas (decisão do utilizador 2026-09-30, ADR-043):
 *   - FT, FR e GR baixam o stock ao emitir, ao custo médio; a FT gerada de uma GR não volta a baixar; a FT gerada
 *     de uma encomenda só baixa o que ainda não saiu por guia;
 *   - a GD repõe ao custo da GR; a NC só repõe quando é uma devolução de mercadoria (ao custo da factura);
 *   - a venda pode deixar o stock negativo (como no legado: o documento fiscal não fica à espera do registo das
 *     entradas); os negativos aparecem em Sistema › Validações;
 *   - CMV em inventário permanente: D custo / C inventário nas saídas, o inverso nas devoluções, no lançamento do
 *     próprio documento.
 * No legado as FT/FR/GR baixavam o stock sem movimento, a NC repunha sempre (mesmo nas correcções de preço), a GD
 * nem repunha (tipo gravado sem cedilha) e não havia CMV nas facturas — só nas guias, por vezes em duplicado.
 */
final class ServicoStockVendas
{
    public function __construct(
        private readonly ServicoStock $stock,
        private readonly ServicoArmazens $armazens,
        private readonly ServicoConfigLogistica $config,
    ) {}

    /** Armazém do documento: o indicado, o da origem ou o predefinido. */
    public function armazem(?int $indicado, ?Venda $origem): ?int
    {
        return $indicado ?: ($origem?->armazem_id ?: null);
    }

    /**
     * Movimenta o stock de uma linha ANTES de a gravar (para o custo ficar na linha na criação).
     *
     * @param  array{produto_id: int, quantidade: string}  $l
     * @return array{custo_unitario_kz: ?string, quantidade_stock: ?string}
     */
    public function movimentarLinha(Venda $venda, array $l, ?ItemVenda $origem, ?Venda $vendaOrigem, ?int $armazem): array
    {
        $p = Produto::query()->find($l['produto_id']);
        $tipo = $venda->tipo_documento;
        if (! $p?->movimenta_stock) {
            return ['custo_unitario_kz' => null, 'quantidade_stock' => null];
        }
        [$sentido, $q, $custo] = match (true) {
            in_array($tipo, ['FT', 'FR'], true) && $vendaOrigem?->tipo_documento === 'GR' => [null, '0', null],
            in_array($tipo, ['FT', 'FR'], true) && $vendaOrigem?->tipo_documento === 'NE' && $origem => ['S', $this->porEntregar($origem, (string) $l['quantidade']), null],
            in_array($tipo, ['FT', 'FR', 'GR'], true) => ['S', (string) $l['quantidade'], null],
            $tipo === 'GD' => ['E', (string) $l['quantidade'], $origem?->custo_unitario_kz !== null ? (string) $origem->custo_unitario_kz : null],
            $tipo === 'NC' && $venda->devolucao_mercadoria => ['E', (string) $l['quantidade'], $origem?->custo_unitario_kz !== null ? (string) $origem->custo_unitario_kz : null],
            default => [null, '0', null],
        };
        if ($sentido === null || bccomp($q, '0', 3) <= 0) {
            return ['custo_unitario_kz' => null, 'quantidade_stock' => $sentido === null ? null : '0'];
        }
        if (! $armazem) {   // só se escolhe (ou cria) o armazém quando há mesmo mercadoria a movimentar
            $armazem = $this->armazens->garantirPredefinido()->id;
            $venda->update(['armazem_id' => $armazem]);
        }
        $ref = "{$venda->tipo_documento} {$venda->numero_documento}";
        $doc = ['documento_tipo' => 'VENDA', 'documento_id' => $venda->id];
        $r = $sentido === 'S'
            ? $this->stock->saida($p->id, $armazem, $q, null, $venda->data_emissao->toDateString(), $ref, $venda->cliente_id, $venda->projeto_id, true, $doc)   // paridade: a venda não fica bloqueada pelo stock (relatório de negativos)
            : $this->stock->entrada($p->id, $armazem, $q, $custo ?? (string) ($p->custo_medio ?? '0'), $venda->data_emissao->toDateString(), $ref, $venda->cliente_id, $venda->projeto_id, $doc);
        if ($origem && $vendaOrigem?->tipo_documento === 'NE' && $sentido === 'S') {
            $origem->update(['quantidade_entregue' => bcadd((string) ($origem->quantidade_entregue ?? 0), $q, 3)]);
        }

        return ['custo_unitario_kz' => $r['custo_unitario'], 'quantidade_stock' => $q];
    }

    /** Encomenda → factura: só sai o que ainda não saiu por guia (entregue e ainda não facturado). */
    private function porEntregar(ItemVenda $origem, string $q): string
    {
        $jaSaiu = bcsub((string) ($origem->quantidade_entregue ?? 0), (string) ($origem->quantidade_faturada ?? 0), 3);
        $jaSaiu = bccomp($jaSaiu, '0', 3) > 0 ? $jaSaiu : '0';
        $baixar = bcsub($q, $jaSaiu, 3);

        return bccomp($baixar, '0', 3) > 0 ? $baixar : '0';
    }

    /** Anulação de uma guia: repõe (GR) ou retira (GD) o que movimentou, ao mesmo custo. */
    public function reverter(Venda $venda, string $motivo): void
    {
        foreach ($venda->itensVenda()->where('quantidade_stock', '>', 0)->get() as $i) {
            $ref = "Anulação {$venda->tipo_documento} {$venda->numero_documento}: {$motivo}";
            $doc = ['documento_tipo' => 'VENDA', 'documento_id' => $venda->id];
            $venda->tipo_documento === 'GD'
                ? $this->stock->saida($i->produto_id, (int) $venda->armazem_id, (string) $i->quantidade_stock, (string) $i->custo_unitario_kz, now()->toDateString(), $ref, $venda->cliente_id, null, false, $doc)
                : $this->stock->entrada($i->produto_id, (int) $venda->armazem_id, (string) $i->quantidade_stock, (string) $i->custo_unitario_kz, now()->toDateString(), $ref, $venda->cliente_id, null, $doc);
        }
    }

    /**
     * Linhas de CMV do documento (agregadas por conta): saídas D custo / C inventário; devoluções o inverso.
     *
     * @return list<array{codigo_conta: string, tipo_dc: string, valor: string}>
     */
    public function linhasCmv(Venda $venda): array
    {
        $devolucao = in_array($venda->tipo_documento, ['NC', 'GD'], true);
        $linhas = [];
        foreach ($venda->itensVenda()->where('quantidade_stock', '>', 0)->whereNotNull('custo_unitario_kz')->get() as $i) {
            $valor = number_format(round((float) bcmul((string) $i->quantidade_stock, (string) $i->custo_unitario_kz, 6), 2), 2, '.', '');
            if (bccomp($valor, '0', 2) <= 0) {
                continue;
            }
            $p = Produto::query()->withTrashed()->findOrFail($i->produto_id);
            foreach ([[$this->config->contaCusto($p), $devolucao ? 'C' : 'D'], [$this->config->contaInventario($p), $devolucao ? 'D' : 'C']] as [$conta, $dc]) {
                $linhas["{$conta}|{$dc}"] ??= ['codigo_conta' => $conta, 'tipo_dc' => $dc, 'valor' => '0.00'];
                $linhas["{$conta}|{$dc}"]['valor'] = bcadd($linhas["{$conta}|{$dc}"]['valor'], $valor, 2);
            }
        }

        return array_values($linhas);
    }
}
