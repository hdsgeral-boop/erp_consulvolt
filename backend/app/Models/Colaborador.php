<?php

namespace App\Models;

use App\Models\Base\ColaboradorBase;

/**
 * colaboradores — /api/rh/colaboradores.
 * Regras de negócio e relações adicionais vêm aqui; a estrutura está em ColaboradorBase (gerado).
 * Fase B: data_cessacao (migração 2026_10_14_100100; preenchida ao cessar o contrato, limpa ao anular a cessação).
 */
class Colaborador extends ColaboradorBase
{
    protected function casts(): array
    {
        return parent::casts() + ['data_cessacao' => 'date'];
    }
}
