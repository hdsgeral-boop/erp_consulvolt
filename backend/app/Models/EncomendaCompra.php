<?php

namespace App\Models;

use App\Models\Base\EncomendaCompraBase;
use App\Models\Concerns\InvalidaNiveisStock;

/**
 * encomendas_compra — /api/compras/encomendas.
 * Regras de negócio e relações adicionais vêm aqui; a estrutura está em EncomendaCompraBase (gerado).
 * Ronda 4: o estado (EM_PROCESSAMENTO/PARCIAL contam como «em encomenda») e a anulação mudam os níveis de stock.
 */
class EncomendaCompra extends EncomendaCompraBase
{
    use InvalidaNiveisStock;

    public function camposNiveisStock(): array
    {
        return ['estado', 'anulado_em'];
    }
}
