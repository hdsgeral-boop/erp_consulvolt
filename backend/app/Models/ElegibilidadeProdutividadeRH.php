<?php

namespace App\Models;

use App\Models\Base\ElegibilidadeProdutividadeRHBase;

/**
 * elegibilidade_produtividade_rh — /api/rh/produtividade/elegibilidade (Fase J).
 * Regras de negócio e relações adicionais vêm aqui; a estrutura está em ElegibilidadeProdutividadeRHBase.
 */
class ElegibilidadeProdutividadeRH extends ElegibilidadeProdutividadeRHBase
{
    public const ORIGENS = ['MANUAL', 'ENTREGAVEL'];
}
