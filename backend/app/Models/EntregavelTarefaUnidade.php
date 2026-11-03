<?php

namespace App\Models;

use App\Models\Base\EntregavelTarefaUnidadeBase;

/**
 * entregaveis_tarefa_unidade — /api/rh/estrutura/entregaveis (Fase J).
 * Regras de negócio e relações adicionais vêm aqui; a estrutura está em EntregavelTarefaUnidadeBase.
 */
class EntregavelTarefaUnidade extends EntregavelTarefaUnidadeBase
{
    public const ORIGENS_DADOS = ['MANUAL', 'CRM'];
}
