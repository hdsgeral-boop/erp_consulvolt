<?php

namespace App\Models;

use App\Models\Base\CategoriaProdutoBase;
use App\Support\Cache\ChaveCache;
use App\Support\Cache\InvalidacaoCache;

/**
 * categorias_produtos — /api/logistica/categorias-produtos.
 * Ronda 3: a categoria define TODAS as contas contabilísticas dos seus produtos (ContasProduto::de). As colunas novas
 * (migração 2026_10_17_093117) estão no esquema gerado e no $fillable desde a ronda 4 (esquema_extra.mjs).
 */
class CategoriaProduto extends CategoriaProdutoBase
{
    /**
     * Contas da categoria => [rótulo, prefixos de classe aceites] (PGC angolano). Quebras são custo (7) e sobras proveito
     * (6), como em ServicoConfigLogistica/ServicoConfigPOS.
     */
    public const CONTAS = [
        'codigo_conta' => ['Proveitos (vendas 61 / serviços 62)', ['6']],
        'conta_custo' => ['Custo das vendas — CMV (71 / 75)', ['7']],
        'conta_compra' => ['Compras (21)', ['21']],
        'conta_inventario' => ['Existências (22 a 27)', ['22', '23', '24', '25', '26', '27']],
        'conta_iva_liquidado' => ['IVA liquidado (345)', ['34']],
        'conta_iva_dedutivel' => ['IVA dedutível (345)', ['34']],
        'conta_quebra' => ['Quebras de inventário (custo, 7)', ['7']],
        'conta_sobra' => ['Sobras de inventário (proveito, 6)', ['6']],
        'conta_ativo' => ['Activo imobilizado (classe 1)', ['1']],
    ];

    protected static function booted(): void
    {
        // as contas da categoria mudam a contabilização dos produtos; o catálogo e os níveis de stock mostram a categoria
        $invalidar = function (CategoriaProduto $c) {
            InvalidacaoCache::esquecer(ChaveCache::empresa((int) $c->empresa_id, 'logistica', 'catalogo_produtos'));
            InvalidacaoCache::esquecer(ChaveCache::empresa((int) $c->empresa_id, 'logistica', 'niveis_stock'));
        };
        static::saved($invalidar);
        static::deleted($invalidar);
        static::restored($invalidar);
    }
}
