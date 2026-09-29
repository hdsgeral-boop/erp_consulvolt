<?php

namespace App\Models;

use App\Models\Base\ProdutoBase;
use App\Support\Cache\ChaveCache;
use Illuminate\Support\Facades\Cache;

/**
 * produtos — produtos e serviços (inclui quartos de hotelaria e serviços de lavandaria). /api/logistica/produtos.
 * O stock NÃO é editado na ficha: resulta dos movimentos de inventário por armazém (o legado mantinha três
 * fontes de stock desalinhadas: products.stock_qty, warehouse_stock e inventory_movements).
 */
class Produto extends ProdutoBase
{
    /** Contas que o legado exige de movimento neste model (js/db_v2.js:412). */
    public const CAMPOS_CONTA = [
        'codigo_conta', 'conta_custo', 'conta_compra', 'conta_inventario', 'conta_iva', 'conta_iva_liquidado',
        'conta_iva_dedutivel', 'conta_quebra', 'conta_sobra', 'conta_ativo',
    ];

    protected static function booted(): void
    {
        $invalidar = fn (Produto $p) => Cache::forget(ChaveCache::empresa((int) $p->empresa_id, 'logistica', 'catalogo_produtos'));
        static::saved($invalidar);
        static::deleted($invalidar);
        static::restored($invalidar);
    }
}
