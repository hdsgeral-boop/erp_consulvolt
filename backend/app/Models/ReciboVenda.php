<?php

namespace App\Models;

use App\Models\Base\ReciboVendaBase;

/**
 * recibos_venda — /api/vendas/recibos.
 * Regras de negócio e relações adicionais vêm aqui; a estrutura está em ReciboVendaBase (gerado).
 */
class ReciboVenda extends ReciboVendaBase
{
    /** M-18: recibo de adiantamento (coluna tipo_recibo da ronda 2, lida em bruto até o model ser regenerado). */
    public function eAdiantamento(): bool
    {
        return ($this->getAttributes()['tipo_recibo'] ?? null) === 'ADIANTAMENTO';
    }
}
