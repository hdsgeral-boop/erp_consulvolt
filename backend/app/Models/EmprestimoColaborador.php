<?php

namespace App\Models;

use App\Models\Base\EmprestimoColaboradorBase;

/**
 * emprestimos_colaboradores — /api/rh/emprestimos.
 * Regras de negócio e relações adicionais vêm aqui; a estrutura está em EmprestimoColaboradorBase (gerado).
 * Estados (js/modules/rh/emprestimos.js:30): PENDENTE_CHEFIA | PENDENTE_RH | APROVADO | RECUSADO | CANCELADO | LIQUIDADO.
 * Fase B: decidido_em e liquidado_em (migração 2026_10_14_100100).
 */
class EmprestimoColaborador extends EmprestimoColaboradorBase
{
    public const ESTADOS = ['PENDENTE_CHEFIA', 'PENDENTE_RH', 'APROVADO', 'RECUSADO', 'CANCELADO', 'LIQUIDADO'];

    /** Estados de um empréstimo «em curso» (só um de cada vez por colaborador — emprestimos.js:127). */
    public const EM_CURSO = ['PENDENTE_CHEFIA', 'PENDENTE_RH', 'APROVADO'];

    protected function casts(): array
    {
        return parent::casts() + ['decidido_em' => 'datetime', 'liquidado_em' => 'datetime'];
    }
}
