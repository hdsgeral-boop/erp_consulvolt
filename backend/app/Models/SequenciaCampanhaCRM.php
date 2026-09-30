<?php

namespace App\Models;

use App\Models\Base\SequenciaCampanhaCRMBase;

/**
 * sequencias_campanhas_crm — /api/crm/sequencias.
 * Regras de negócio e relações adicionais vêm aqui; a estrutura está em SequenciaCampanhaCRMBase (gerado).
 * `passos` é uma lista no legado (crm_dados.js:366); enquanto a coluna for varchar(255) guarda o JSON
 * (ServicoCampanhasCRM limita o tamanho) — correcção de esquema proposta: jsonb.
 */
class SequenciaCampanhaCRM extends SequenciaCampanhaCRMBase
{
    protected function casts(): array
    {
        return ['passos' => 'array'] + parent::casts();
    }

    protected function asJson($value, $flags = 0)
    {
        return parent::asJson($value, $flags | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
