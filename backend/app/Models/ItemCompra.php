<?php

namespace App\Models;

use App\Models\Base\ItemCompraBase;
use App\Models\Concerns\InvalidaNiveisStock;

/**
 * itens_compra — /api/compras/itens.
 * Regras de negócio e relações adicionais vêm aqui; a estrutura está em ItemCompraBase (gerado).
 * Ronda 4: as quantidades das linhas de pedidos/encomendas mudam os níveis de stock.
 */
class ItemCompra extends ItemCompraBase
{
    use InvalidaNiveisStock;

    public function camposNiveisStock(): array
    {
        return ['quantidade', 'quantidade_recebida', 'produto_id', 'pedido_compra_id', 'encomenda_compra_id', 'tipo_documento_origem'];
    }
}
