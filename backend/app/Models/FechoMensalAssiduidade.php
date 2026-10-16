<?php

namespace App\Models;

use App\Models\Base\FechoMensalAssiduidadeBase;
use App\Models\Concerns\InvalidaFaltasDoAno;

/**
 * fechos_mensais_assiduidade — /api/rh/assiduidade/fechos.
 * Regras de negócio e relações adicionais vêm aqui; a estrutura está em FechoMensalAssiduidadeBase (gerado).
 */
class FechoMensalAssiduidade extends FechoMensalAssiduidadeBase
{
    use InvalidaFaltasDoAno;   // acumulado anual de faltas em cache (CacheFaltasDoAno)
}
