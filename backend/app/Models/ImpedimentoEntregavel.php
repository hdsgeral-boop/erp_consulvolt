<?php

namespace App\Models;

use App\Models\Base\ImpedimentoEntregavelBase;

/**
 * impedimentos_entregavel — «não há condições» para executar um entregável num período (Fase J).
 * Regras de negócio e relações adicionais vêm aqui; a estrutura está em ImpedimentoEntregavelBase.
 */
class ImpedimentoEntregavel extends ImpedimentoEntregavelBase
{
    public const ESTADOS = ['ABERTO', 'ACEITE', 'RECUSADO'];

    public const ROTULOS = ['ABERTO' => 'Por decidir', 'ACEITE' => 'Aceite', 'RECUSADO' => 'Recusado'];
}
