<?php

namespace App\Models;

use App\Models\Base\ItemAcrescimoDiferimentoBase;

/**
 * itens_acrescimos_diferimentos — /api/acrescimos/itens.
 * Regras de negócio e relações adicionais vêm aqui; a estrutura está em ItemAcrescimoDiferimentoBase (gerado).
 * `regularizacao` é um objecto no legado (ad_dados.js:187); enquanto a coluna for varchar(255) guarda-se o JSON
 * (ServicoItensAcrescimos limita o tamanho) — correcção de esquema proposta: jsonb.
 */
class ItemAcrescimoDiferimento extends ItemAcrescimoDiferimentoBase
{
    protected function casts(): array
    {
        return ['regularizacao' => 'array'] + parent::casts();
    }

    /** JSON sem escapes de acentos e barras: cabe mais texto nos 255 caracteres de `regularizacao`. */
    protected function asJson($value, $flags = 0)
    {
        return parent::asJson($value, $flags | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
