<?php

namespace App\Models;

use App\Models\Base\ProdutoBase;
use App\Support\Cache\ChaveCache;
use App\Support\Cache\InvalidacaoCache;

/**
 * produtos — produtos e serviços (inclui quartos de hotelaria e serviços de lavandaria). /api/logistica/produtos.
 * O stock NÃO é editado na ficha: resulta dos movimentos de inventário por armazém (o legado mantinha três
 * fontes de stock desalinhadas: products.stock_qty, warehouse_stock e inventory_movements).
 * Ronda 3: as contas contabilísticas definem-se na categoria (ContasProduto::de); as colunas de conta do produto ficam
 * como contas de recurso. Níveis de stock (mínimo, segurança, ponto de encomenda, máximo) e ficha técnica: colunas da
 * migração 2026_10_17_093117, fora do $fillable gerado (gravadas pelo ServicoProdutos com forceFill, depois de validadas).
 */
class Produto extends ProdutoBase
{
    /** Contas que o legado exige de movimento neste model (js/db_v2.js:412). */
    public const CAMPOS_CONTA = [
        'codigo_conta', 'conta_custo', 'conta_compra', 'conta_inventario', 'conta_iva', 'conta_iva_liquidado',
        'conta_iva_dedutivel', 'conta_quebra', 'conta_sobra', 'conta_ativo',
    ];

    /** Níveis de stock, por ordem crescente esperada: mínimo ≤ segurança ≤ ponto de encomenda ≤ máximo. */
    public const NIVEIS_STOCK = ['stock_minimo', 'stock_seguranca', 'ponto_encomenda', 'stock_maximo'];

    /** Campos da ficha técnica. */
    public const CAMPOS_FICHA = ['marca', 'modelo', 'comprimento', 'largura', 'altura', 'unidade_dimensao', 'peso_liquido', 'peso_bruto', 'caracteristicas', 'observacoes_tecnicas'];

    protected function casts(): array
    {
        return parent::casts() + [
            'stock_maximo' => 'decimal:3', 'stock_seguranca' => 'decimal:3', 'ponto_encomenda' => 'decimal:3',
            'comprimento' => 'decimal:3', 'largura' => 'decimal:3', 'altura' => 'decimal:3', 'peso_liquido' => 'decimal:3', 'peso_bruto' => 'decimal:3',
            'caracteristicas' => 'array',
        ];
    }

    protected static function booted(): void
    {
        $invalidar = function (Produto $p) {   // agora e depois do commit (R2)
            InvalidacaoCache::esquecer(ChaveCache::empresa((int) $p->empresa_id, 'logistica', 'catalogo_produtos'));
            InvalidacaoCache::esquecer(ChaveCache::empresa((int) $p->empresa_id, 'logistica', 'niveis_stock'));
        };
        static::saved($invalidar);
        static::deleted($invalidar);
        static::restored($invalidar);
    }
}
