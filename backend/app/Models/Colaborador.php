<?php

namespace App\Models;

use App\Models\Base\ColaboradorBase;
use App\Models\Concerns\InvalidaFaltasDoAno;

/**
 * colaboradores — /api/rh/colaboradores.
 * Regras de negócio e relações adicionais vêm aqui; a estrutura está em ColaboradorBase (gerado).
 * Fase B: data_cessacao (migração 2026_10_14_100100; preenchida ao cessar o contrato, limpa ao anular a cessação).
 */
class Colaborador extends ColaboradorBase
{
    use InvalidaFaltasDoAno;   // acumulado anual de faltas em cache (CacheFaltasDoAno)

    /** Só o estado e as datas mudam quem é apurado na efectividade (resumoMes). */
    public function camposFaltasDoAno(): ?array
    {
        return ['estado', 'data_admissao', 'data_cessacao', 'eliminado_em'];
    }

    protected function casts(): array
    {
        return parent::casts() + ['data_cessacao' => 'date'];
    }
}
