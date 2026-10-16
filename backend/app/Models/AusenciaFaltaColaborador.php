<?php

namespace App\Models;

use App\Models\Base\AusenciaFaltaColaboradorBase;
use App\Models\Concerns\InvalidaFaltasDoAno;

/**
 * ausencias_faltas_colaboradores — /api/rh/ausencias.
 * Regras de negócio e relações adicionais vêm aqui; a estrutura está em AusenciaFaltaColaboradorBase (gerado).
 */
class AusenciaFaltaColaborador extends AusenciaFaltaColaboradorBase
{
    use InvalidaFaltasDoAno;   // acumulado anual de faltas em cache (CacheFaltasDoAno)
}
