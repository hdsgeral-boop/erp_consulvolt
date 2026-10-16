<?php

namespace App\Models;

use App\Models\Base\PlanoFeriasColaboradorBase;
use App\Models\Concerns\InvalidaFaltasDoAno;

/**
 * plano_ferias_colaboradores — /api/rh/ferias.
 * Regras de negócio e relações adicionais vêm aqui; a estrutura está em PlanoFeriasColaboradorBase (gerado).
 */
class PlanoFeriasColaborador extends PlanoFeriasColaboradorBase
{
    use InvalidaFaltasDoAno;   // acumulado anual de faltas em cache (CacheFaltasDoAno)
}
