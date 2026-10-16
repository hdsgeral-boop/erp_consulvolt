<?php

namespace App\Models;

use App\Models\Base\ConfigAssiduidadeBase;
use App\Models\Concerns\InvalidaFaltasDoAno;

/**
 * configuracoes_assiduidade — /api/rh/assiduidade/config.
 * Regras de negócio e relações adicionais vêm aqui; a estrutura está em ConfigAssiduidadeBase (gerado).
 */
class ConfigAssiduidade extends ConfigAssiduidadeBase
{
    use InvalidaFaltasDoAno;   // acumulado anual de faltas em cache (CacheFaltasDoAno)
}
