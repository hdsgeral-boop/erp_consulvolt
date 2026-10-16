<?php

namespace App\Models;

use App\Models\Base\EscalaTrabalhoBase;
use App\Models\Concerns\InvalidaFaltasDoAno;

/**
 * escalas_trabalho — /api/rh/escalas.
 * Regras de negócio e relações adicionais vêm aqui; a estrutura está em EscalaTrabalhoBase (gerado).
 */
class EscalaTrabalho extends EscalaTrabalhoBase
{
    use InvalidaFaltasDoAno;   // acumulado anual de faltas em cache (CacheFaltasDoAno)
}
