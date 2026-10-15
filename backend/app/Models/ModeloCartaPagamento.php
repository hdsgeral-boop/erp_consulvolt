<?php

namespace App\Models;

use App\Models\Base\ModeloCartaPagamentoBase;

/**
 * modelos_cartas_pagamento — /api/tesouraria/cartas/modelos.
 * Regras de negócio e relações adicionais vêm aqui; a estrutura está em ModeloCartaPagamentoBase (gerado).
 * Colunas da Fase D fora do esquema gerado (migração 2026_10_15_700100): nome e predefinido — gravadas com forceFill
 * em App\Services\Tesouraria\ServicoCartasPagamento (não entram no $fillable gerado).
 */
class ModeloCartaPagamento extends ModeloCartaPagamentoBase
{
    protected function casts(): array
    {
        return parent::casts() + ['predefinido' => 'boolean'];
    }
}
