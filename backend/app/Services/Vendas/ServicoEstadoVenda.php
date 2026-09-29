<?php

namespace App\Services\Vendas;

use App\Models\Venda;
use Illuminate\Support\Facades\DB;

/**
 * Estado persistido das facturas (o legado calculava-o só no ecrã e raramente gravava `status`).
 *   pendente = total − pago (recibos) − notas de crédito emitidas sobre a factura
 *   PAGO quando nada fica pendente; PARCIAL quando algo foi pago/creditado; senão PENDENTE.
 */
final class ServicoEstadoVenda
{
    public function recalcular(Venda $venda): void
    {
        if (! in_array($venda->tipo_documento, ['FT', 'FR'], true)) {
            return;
        }
        $abatido = bcadd((string) ($venda->valor_pago ?? '0'), $this->creditado($venda), 2);
        $pendente = bcsub((string) $venda->total_bruto, $abatido, 2);
        if (bccomp($pendente, '0', 2) < 0) {
            $pendente = '0.00';
        }
        $estado = bccomp($pendente, '0', 2) === 0 ? 'PAGO' : (bccomp($abatido, '0', 2) > 0 ? 'PARCIAL' : 'PENDENTE');
        $venda->update(['valor_pendente' => $pendente, 'estado' => $estado]);
    }

    /** Total das notas de crédito (não anuladas) emitidas sobre a factura. */
    public function creditado(Venda $venda): string
    {
        $v = DB::table('vendas_documentos_relacionados as r')->join('vendas as nc', 'nc.id', '=', 'r.venda_id')
            ->where('r.empresa_id', $venda->empresa_id)->where('r.venda_relacionada_id', $venda->id)
            ->where('nc.tipo_documento', 'NC')->where(fn ($q) => $q->whereNull('nc.estado')->orWhere('nc.estado', '<>', 'ANULADO'))
            ->sum('nc.total_bruto');

        return number_format((float) $v, 2, '.', '');
    }
}
