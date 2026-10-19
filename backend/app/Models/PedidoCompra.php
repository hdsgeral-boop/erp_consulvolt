<?php

namespace App\Models;

use App\Models\Base\PedidoCompraBase;
use App\Models\Concerns\InvalidaNiveisStock;

/**
 * pedidos_compra — /api/compras/pedidos.
 * Regras de negócio e relações adicionais vêm aqui; a estrutura está em PedidoCompraBase (gerado).
 * Ronda 4: o estado (PENDENTE/APROVADO contam como «em encomenda») e a anulação mudam os níveis de stock.
 */
class PedidoCompra extends PedidoCompraBase
{
    use InvalidaNiveisStock;

    public function camposNiveisStock(): array
    {
        return ['estado', 'anulado_em'];
    }
}
