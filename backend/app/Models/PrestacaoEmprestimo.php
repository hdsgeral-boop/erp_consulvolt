<?php

namespace App\Models;

use App\Models\Base\PrestacaoEmprestimoBase;

/**
 * prestacoes_emprestimos — /api/rh/emprestimos/{id} (plano de amortização).
 * Regras de negócio e relações adicionais vêm aqui; a estrutura está em PrestacaoEmprestimoBase (gerado).
 * Estados: PENDENTE (por descontar) | DESCONTADO (lançada num processamento) | ISENTO (dispensada).
 * Fase B: ligação ao lançamento do processamento (migração 2026_10_14_100100).
 */
class PrestacaoEmprestimo extends PrestacaoEmprestimoBase
{
    public const COLUNAS_FASE_B = ['linha_folha_salarial_id', 'periodo_processamento_salarial_id', 'descontado_em', 'descontado_por'];

    protected function casts(): array
    {
        return parent::casts() + ['linha_folha_salarial_id' => 'integer', 'periodo_processamento_salarial_id' => 'integer', 'descontado_em' => 'datetime'];
    }
}
