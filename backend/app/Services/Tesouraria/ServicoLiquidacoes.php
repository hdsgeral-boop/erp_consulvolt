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

    /** Valida a ligação de uma linha e devolve o n.º do documento ligado (para a chave dos pendentes). */
    public function validarLigacao(?int $vendaId, ?int $faturaId, ?int $terceiroId, string $onde): ?string
    {
        if ($vendaId) {
            $v = Venda::query()->find($vendaId);
            if (! $v || $v->cliente_id !== (int) $terceiroId || ! $v->contabilizado) {
                throw new ErroNegocio("{$onde} a venda indicada não é deste cliente ou não está contabilizada.", 'LIGACAO_INVALIDA', 422);
            }

            return $v->numero_documento;
        }
        if ($faturaId) {
            $f = FaturaCompra::query()->find($faturaId);
            if (! $f || $f->fornecedor_id !== (int) $terceiroId || ! $f->contabilizado || $f->estado === 'ANULADA') {
                throw new ErroNegocio("{$onde} a factura indicada não é deste fornecedor ou não está contabilizada.", 'LIGACAO_INVALIDA', 422);
            }

            return $f->numero_fatura;
        }

        return null;
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
