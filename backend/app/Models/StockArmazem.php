<?php

namespace App\Models;

use App\Models\Base\StockArmazemBase;
use App\Support\Cache\ChaveCache;
use App\Support\Cache\InvalidacaoCache;

/**
 * stock_armazem — /api/logistica/stock.
 * Regras de negócio e relações adicionais vêm aqui; a estrutura está em StockArmazemBase (gerado).
 * Ronda 3: cada alteração do stock invalida (agora e depois do commit) a avaliação dos níveis de stock em cache.
 */
class StockArmazem extends StockArmazemBase
{
    protected static function booted(): void
    {
        $invalidar = fn (StockArmazem $s) => InvalidacaoCache::esquecer(ChaveCache::empresa((int) $s->empresa_id, 'logistica', 'niveis_stock'));
        static::saved($invalidar);
        static::deleted($invalidar);
    }
}
