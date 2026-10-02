<?php

namespace App\Services\Compras;

use App\Exceptions\ErroNegocio;
use App\Models\FaturaCompra;
use App\Models\ItemCompra;
use App\Models\Terceiro;
use App\Services\Contabilidade\LocalizadorLancamentos;
use App\Services\Contabilidade\ServicoLancamentos;
use App\Services\Contabilidade\ServicoNotasPorConta;
use Illuminate\Support\Facades\DB;

/**
 * Contabilização das facturas de fornecedor (postPurchaseInvoiceToAccounting, js/ui_compras_v2.js:3270-3553), diário FF:
 *   D artigo de stock (via encomenda) → conta transitória do fornecedor (328), pelo valor consumido na recepção;
 *     a diferença para o valor da factura → diferenças de câmbio (desfavoráveis a D / favoráveis a C);
 *   D imobilizado → conta do activo do produto · D serviços/outros → conta de custo do produto
 *     (o legado caía na '72', que no PGC angolano são custos com pessoal);
 *   D IVA dedutível → conta do produto ou da configuração;
 *   C fornecedor → pelo TOTAL DA FACTURA (o legado creditava a soma das linhas).
 * Um só lançamento equilibrado (ServicoLancamentos); descontabilizar = estorno (o legado apagava por doc_number e
 * podia apagar lançamentos de outro fornecedor ou da Tesouraria com o mesmo n.º).
 */
final class ServicoContabilizacaoCompras
{
    public function __construct(
        private readonly ServicoLancamentos $lancamentos,
        private readonly LocalizadorLancamentos $localizador,
        private readonly ServicoConfigCompras $config,
        private readonly ServicoNotasPorConta $notas,
    ) {}

    public function contabilizar(FaturaCompra $fatura): FaturaCompra
    {
        return DB::transaction(function () use ($fatura) {
            $fatura = FaturaCompra::query()->lockForUpdate()->findOrFail($fatura->id);
            if ($fatura->contabilizado) {
                throw new ErroNegocio('A factura já está contabilizada.', 'JA_CONTABILIZADO', 422);
            }
            if ($fatura->estado === 'ANULADA') {
                throw new ErroNegocio('Factura anulada: não é contabilizável.', 'DOCUMENTO_ANULADO', 422);
            }
            $fornecedor = Terceiro::query()->withTrashed()->findOrFail($fatura->fornecedor_id);
            $contaFornecedor = $fornecedor->codigo_conta ?: throw new ErroNegocio('O fornecedor não tem conta contabilística.', 'FORNECEDOR_SEM_CONTA', 422);
            $comum = ['terceiro_id' => $fornecedor->id, 'unidade_negocio_id' => $fatura->unidade_negocio_id, 'centro_custo_id' => $fatura->centro_custo_id];

            $debitos = [];   // conta => valor (D positivo, C negativo)
            $somar = function (string $conta, string $valor) use (&$debitos) {
                $debitos[$conta] = bcadd($debitos[$conta] ?? '0.00', $valor, 2);
            };
            $linhas = ItemCompra::query()->where('fatura_compra_id', $fatura->id)->with(['produto' => fn ($q) => $q->withTrashed()])->orderBy('id')->get();
            if ($linhas->isEmpty()) {
                throw new ErroNegocio('A factura não tem linhas.', 'SEM_LINHAS', 422);
            }
            foreach ($linhas as $n => $l) {
                $p = $l->produto;
                $onde = 'Linha '.($n + 1).($p ? " ({$p->codigo})" : '').':';
                $liquido = number_format((float) ($l->total_kz ?? $l->total), 2, '.', '');
                if ($p?->movimenta_stock && $l->item_encomenda_id) {
                    $transitoria = $fornecedor->conta_compra_transitoria ?: $this->config->exigir('transitoria_compras', 'O fornecedor não tem conta transitória de compras.');
                    // linhas migradas sem valor_transitoria_kz: a transitória salda pelo valor da linha, como o legado quando faltava
                    // valor_328_kz (ui_compras_v2.js:3364-3370); antes o NULL passava a 0 e todo o líquido ia para diferenças de câmbio (E-STK-2)
                    $valorTrans = $l->valor_transitoria_kz !== null ? number_format((float) $l->valor_transitoria_kz, 2, '.', '') : $liquido;
                    $somar($transitoria, $valorTrans);
                    $diferenca = bcsub($liquido, $valorTrans, 2);
                    if (bccomp($diferenca, '0', 2) > 0) {
                        $somar($this->config->exigir('diferencas_cambio_desfavoraveis', "{$onde} diferença entre a factura e a recepção."), $diferenca);
                    } elseif (bccomp($diferenca, '0', 2) < 0) {
                        $somar($this->config->exigir('diferencas_cambio_favoraveis', "{$onde} diferença entre a factura e a recepção."), $diferenca);
                    }
                } elseif ($p?->movimenta_stock) {
                    throw new ErroNegocio("{$onde} artigo de stock sem encomenda/recepção.", 'FATURA_DIRETA_COM_STOCK', 422);
                } elseif ($p?->e_ativo_imobilizado) {
                    $somar($p->conta_ativo ?: $this->config->exigir('imobilizado', "{$onde} activo sem conta de imobilizado."), $liquido);
                } else {
                    $somar($p?->conta_custo ?: ($p?->conta_compra ?: $this->config->exigir('custos_servicos', "{$onde} produto sem conta de custo.")), $liquido);
                }
                // IVA gravado na linha (em Kz); linhas do legado sem esse valor: recalculado sobre o líquido
                $iva = $l->imposto_kz !== null ? number_format((float) $l->imposto_kz, 2, '.', '')
                    : CalculadoraCompra::calcular([['quantidade' => '1', 'preco_unitario' => $liquido, 'taxa_imposto' => (string) ($l->taxa_imposto ?? 0)]])['imposto'];
                if (bccomp($iva, '0', 2) > 0) {
                    $somar($p?->conta_iva_dedutivel ?: $this->config->exigir('iva_dedutivel', "{$onde} produto sem conta de IVA dedutível."), $iva);
                }
            }

            $lancamento = [];
            $totalD = $totalC = '0.00';
            foreach ($debitos as $conta => $valor) {
                if (bccomp($valor, '0', 2) === 0) {
                    continue;
                }
                $d = bccomp($valor, '0', 2) > 0;
                $abs = ltrim($valor, '-');
                $lancamento[] = ['codigo_conta' => (string) $conta, 'tipo_dc' => $d ? 'D' : 'C', 'valor' => $abs] + $comum;
                $d ? $totalD = bcadd($totalD, $abs, 2) : $totalC = bcadd($totalC, $abs, 2);
            }
            $credito = bcsub($totalD, $totalC, 2);
            if (bccomp($credito, number_format((float) $fatura->montante_total, 2, '.', ''), 2) !== 0) {
                throw new ErroNegocio("As linhas da factura somam {$credito} mas o total é {$fatura->montante_total}: verifique a factura.", 'TOTAIS_INCONSISTENTES', 422,
                    ['soma_linhas' => $credito, 'total' => (string) $fatura->montante_total]);
            }
            // fornecedor em moeda estrangeira: a linha guarda o valor na moeda (saldo em moeda na liquidação)
            $moeda = $fatura->codigo_moeda && $fatura->codigo_moeda !== 'AOA'
                ? ['codigo_moeda' => $fatura->codigo_moeda, 'valor_moeda' => $fatura->montante_total_moeda, 'taxa_cambio' => $fatura->taxa_cambio] : [];
            $lancamento[] = ['codigo_conta' => $contaFornecedor, 'tipo_dc' => 'C', 'valor' => $credito] + $moeda + $comum;
            // notas às demonstrações como o legado (ui_compras_v2.js:3452-3460): 321/322/328 → nota 11, 34* → nota 9, 11/12/14 → nota 4
            // (sem nota as linhas ficavam fora do Balanço e da DR — E-CON-1)
            $lancamento = $this->notas->aplicarALinhas($lancamento, fn (string $c) => match (true) {
                str_starts_with($c, '321') || str_starts_with($c, '322') || str_starts_with($c, '328') => '11',
                str_starts_with($c, '34') => '9',
                str_starts_with($c, '11') || str_starts_with($c, '12') || str_starts_with($c, '14') => '4',
                default => null,
            });

            $numeroLan = $this->lancamentos->criar([
                'diario_id' => $this->localizador->diario('FF', 'Facturas de fornecedor')->id, 'data_documento' => $fatura->data->toDateString(),
                'numero_documento' => $fatura->numero_fatura, 'referencia' => "FF {$fatura->numero_fatura}",
                'descricao' => mb_substr("FF {$fatura->numero_fatura} — {$fornecedor->nome}", 0, 1000), 'tipo_origem' => 'COMPRAS_FATURA', 'linhas' => $lancamento,
            ])->first()->numero_lan;
            $fatura->update(['contabilizado' => true, 'numero_lan_contabilizacao' => $numeroLan]);

            return $fatura;
        });
    }

    public function descontabilizar(FaturaCompra $fatura, string $motivo): FaturaCompra
    {
        return DB::transaction(function () use ($fatura, $motivo) {
            $fatura = FaturaCompra::query()->lockForUpdate()->findOrFail($fatura->id);
            if (! $fatura->contabilizado) {
                throw new ErroNegocio('A factura não está contabilizada.', 'NAO_CONTABILIZADO', 422);
            }
            if (in_array($fatura->estado, ['PAGO', 'PARCIAL'], true)) {
                throw new ErroNegocio('A factura tem pagamentos: anule-os primeiro na Tesouraria.', 'FATURA_COM_PAGAMENTOS', 422);
            }
            $conta = Terceiro::query()->withTrashed()->whereKey($fatura->fornecedor_id)->value('codigo_conta');
            // legado: n.º externo sem unicidade → só serve o lançamento que credita a conta DESTE fornecedor, no diário FF
            $linha = $this->localizador->localizar($fatura->numero_lan_contabilizacao, (string) $fatura->numero_fatura, $conta, 'C', $fatura->numero_lan_contabilizacao ? null : 'FF');
            $this->lancamentos->estornar($linha, $motivo);
            $fatura->update(['contabilizado' => false, 'numero_lan_contabilizacao' => null]);

            return $fatura;
        });
    }
}
