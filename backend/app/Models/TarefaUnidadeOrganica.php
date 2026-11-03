<?php

namespace App\Models;

use App\Models\Base\TarefaUnidadeOrganicaBase;

/**
 * tarefas_unidade_organica — /api/rh/estrutura/tarefas (Fase J).
 * Regras de negócio e relações adicionais vêm aqui; a estrutura está em TarefaUnidadeOrganicaBase.
 */
class TarefaUnidadeOrganica extends TarefaUnidadeOrganicaBase
{
    public const PERIODICIDADES = ['PONTUAL', 'DIARIA', 'SEMANAL', 'MENSAL', 'TRIMESTRAL', 'ANUAL'];
}
