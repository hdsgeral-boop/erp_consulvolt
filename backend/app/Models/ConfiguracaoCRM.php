<?php

namespace App\Models;

use App\Models\Base\ConfiguracaoCRMBase;

/**
 * configuracoes_crm — /api/crm/configuracao.
 * Regras de negócio e relações adicionais vêm aqui; a estrutura está em ConfiguracaoCRMBase (gerado).
 * `motivos_perda` e `origens` são listas no legado (crm_dados.js:53); enquanto as colunas forem text/varchar(255)
 * guardam o JSON (ServicoConfiguracaoCRM limita o tamanho) — correcção de esquema proposta: jsonb.
 */
class ConfiguracaoCRM extends ConfiguracaoCRMBase
{
    protected function casts(): array
    {
        return ['motivos_perda' => 'array', 'origens' => 'array'] + parent::casts();
    }

    protected function asJson($value, $flags = 0)
    {
        return parent::asJson($value, $flags | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
