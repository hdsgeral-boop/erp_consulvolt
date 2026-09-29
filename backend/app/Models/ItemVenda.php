<?php

namespace App\Models;

use App\Exceptions\ErroNegocio;
use App\Models\Base\ItemVendaBase;

/**
 * itens_venda — linhas dos documentos comerciais. total = valor líquido + IVA da linha (convenção do legado).
 * Linhas seladas (fe_selado) são imutáveis (js/db_v2.js:898-906).
 */
class ItemVenda extends ItemVendaBase
{
    private const CAMPOS_SELADOS = ['venda_id', 'produto_id', 'descricao', 'quantidade', 'preco_unitario', 'preco_unitario_moeda', 'taxa_imposto', 'total', 'fe_selado'];

    protected static function booted(): void
    {
        static::updating(function (ItemVenda $i) {
            if ($i->getOriginal('fe_selado') && $i->isDirty(self::CAMPOS_SELADOS)) {
                throw new ErroNegocio('Linha de documento fiscal selado: não pode ser alterada.', 'DOCUMENTO_SELADO', 422);
            }
        });
        static::deleting(function (ItemVenda $i) {
            if ($i->fe_selado) {
                throw new ErroNegocio('Linha de documento fiscal selado: não pode ser eliminada.', 'DOCUMENTO_SELADO', 422);
            }
        });
    }
}
