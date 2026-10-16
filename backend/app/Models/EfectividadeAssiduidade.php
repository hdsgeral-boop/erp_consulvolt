<?php

namespace App\Models;

use App\Models\Base\EfectividadeAssiduidadeBase;
use App\Models\Concerns\InvalidaFaltasDoAno;

/**
 * efectividade_assiduidade — /api/rh/assiduidade.
 * Regras de negócio e relações adicionais vêm aqui; a estrutura está em EfectividadeAssiduidadeBase (gerado).
 */
class EfectividadeAssiduidade extends EfectividadeAssiduidadeBase
{
    use InvalidaFaltasDoAno;   // acumulado anual de faltas em cache (CacheFaltasDoAno)
}
