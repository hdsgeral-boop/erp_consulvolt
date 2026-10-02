<?php

namespace App\Services\Tesouraria;

use App\Exceptions\ErroNegocio;
use App\Models\FaturaCompra;
use App\Models\Terceiro;
use App\Models\Venda;
use App\Services\Vendas\ServicoEstadoVenda;
use Illuminate\Support\Facades\DB;

/**
 * Efeito das liquidações (documentos de tesouraria e movimentos de caixa) nos documentos de origem:
 *   venda: pago += recebido (C) − devolvido (D), estado recalculado; factura de fornecedor: estado pelo diário.
 * Comum aos dois canais (no legado nenhum dos dois actualizava o documento liquidado).
 */
final class ServicoLiquidacoes
{
    public function __construct(private readonly ServicoEstadoVenda $estadoVenda) {}

    /**
     * Valida a ligação de uma linha e devolve o n.º do documento ligado (para a chave dos pendentes).
     * O n.º é sempre o do documento ligado: um n.º diferente enviado pelo cliente é rejeitado — com um n.º inventado
     * não havia pendente, o limite do saldo em aberto era saltado e a venda era actualizada na mesma.
     */
    public function validarLigacao(?int $vendaId, ?int $faturaId, ?int $terceiroId, string $onde, ?string $numeroEnviado = null): ?string
    {
        $numero = null;
        if ($vendaId) {
            $v = Venda::query()->find($vendaId);
            if (! $v || $v->cliente_id !== (int) $terceiroId || ! $v->contabilizado) {
                throw new ErroNegocio("{$onde} a venda indicada não é deste cliente ou não está contabilizada.", 'LIGACAO_INVALIDA', 422);
            }
            if ($v->estado === 'ANULADO') {
                throw new ErroNegocio("{$onde} a venda {$v->numero_documento} está anulada.", 'LIGACAO_INVALIDA', 422);
            }
            $numero = (string) $v->numero_documento;
        } elseif ($faturaId) {
            $f = FaturaCompra::query()->find($faturaId);
            if (! $f || $f->fornecedor_id !== (int) $terceiroId || ! $f->contabilizado || $f->estado === 'ANULADA') {
                throw new ErroNegocio("{$onde} a factura indicada não é deste fornecedor ou não está contabilizada.", 'LIGACAO_INVALIDA', 422);
            }
            $numero = (string) $f->numero_fatura;
        }
        if ($numero !== null && $numeroEnviado !== null && trim($numeroEnviado) !== '' && trim($numeroEnviado) !== $numero) {
            throw new ErroNegocio("{$onde} o n.º de documento indicado ({$numeroEnviado}) não é o do documento ligado ({$numero}).", 'LIGACAO_INVALIDA', 422,
                ['numero_documento' => $numero]);
        }

        return $numero;
    }

    /**
     * Uma linha ligada a uma venda/factura de fornecedor tem de liquidar o PENDENTE desse documento, na conta do terceiro
     * onde foi contabilizado (o pendente é ligado ao documento por ServicoPendentes::ligarDocumentos). Sem pendente
     * — documento já liquidado, conta trocada ou n.º diferente — rejeita-se, em vez de tratar como adiantamento.
     */
    public function exigirPendente(?array $aberto, ?int $vendaId, ?int $faturaId, string $numero, string $onde): void
    {
        if (! $vendaId && ! $faturaId) {
            return;
        }
        $ligado = $aberto && ($vendaId ? (int) ($aberto['venda_id'] ?? 0) === $vendaId : (int) ($aberto['fatura_compra_id'] ?? 0) === $faturaId);
        if (! $ligado) {
            throw new ErroNegocio("{$onde} o documento {$numero} não tem saldo em aberto nesta conta do terceiro (já liquidado ou conta diferente da do documento).",
                'SEM_PENDENTE', 422, ['numero_documento' => $numero]);
        }
    }

    /**
     * @param  iterable<array{venda_id: ?int, fatura_compra_id: ?int, tipo_dc: string, valor: string|float}>  $linhas  (sentido da CONTRAPARTIDA)
     * @param  int  $sinal  +1 ao integrar/contabilizar, −1 ao estornar
     */
    public function aplicar(iterable $linhas, int $sinal): void
    {
        $vendas = [];
        $faturas = [];
        foreach ($linhas as $l) {
            if (! empty($l['venda_id'])) {
                $v = (string) $l['valor'];
                $vendas[$l['venda_id']] = bcadd($vendas[$l['venda_id']] ?? '0.00', $l['tipo_dc'] === 'C' ? $v : bcmul($v, '-1', 2), 2);
            }
            if (! empty($l['fatura_compra_id'])) {
                $faturas[$l['fatura_compra_id']] = true;
            }
        }
        foreach ($vendas as $id => $recebido) {
            $venda = Venda::query()->lockForUpdate()->find($id);
            if (! $venda) {
                continue;
            }
            $pago = bcadd((string) ($venda->valor_pago ?? 0), $sinal > 0 ? $recebido : bcmul($recebido, '-1', 2), 2);
            if (bccomp($pago, bcadd((string) $venda->total_bruto, '0.01', 2), 2) > 0) {
                throw new ErroNegocio("A liquidação deixaria a venda {$venda->numero_documento} com {$pago} pagos, acima do total ({$venda->total_bruto}).",
                    'VALOR_SUPERIOR_EM_ABERTO', 422, ['venda_id' => $venda->id, 'total_bruto' => (string) $venda->total_bruto]);
            }
            $venda->update(['valor_pago' => bccomp($pago, '0', 2) < 0 ? '0.00' : $pago]);
            $this->estadoVenda->recalcular($venda);
        }
        foreach (array_keys($faturas) as $id) {
            $this->recalcularFaturaCompra(FaturaCompra::query()->lockForUpdate()->find($id));
        }
    }

    /** Estado da factura de fornecedor pelo diário: crédito − débito na conta do fornecedor para o n.º da factura. */
    public function recalcularFaturaCompra(?FaturaCompra $f): void
    {
        if (! $f || $f->estado === 'ANULADA') {
            return;
        }
        $conta = Terceiro::query()->withTrashed()->whereKey($f->fornecedor_id)->value('codigo_conta');
        $saldo = (string) DB::table('lancamentos_contabeis')->where('empresa_id', $f->empresa_id)->where('terceiro_id', $f->fornecedor_id)
            ->where('codigo_conta', $conta)->where('numero_documento', $f->numero_fatura)
            ->selectRaw("COALESCE(SUM(CASE WHEN tipo_dc = 'C' THEN valor ELSE -valor END), 0) AS s")->value('s');
        $tol = ServicoPendentes::TOLERANCIA;
        $estado = bccomp($saldo, $tol, 3) <= 0 ? 'PAGO' : (bccomp($saldo, bcsub((string) $f->montante_total, $tol, 3), 3) < 0 ? 'PARCIAL' : 'PENDENTE');
        $f->update(['estado' => $estado]);
    }
}
