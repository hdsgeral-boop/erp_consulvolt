<?php

namespace App\Models;

use App\Models\Base\ElegibilidadeProdutividadeRHBase;

/**
 * elegibilidade_produtividade_rh — /api/rh/produtividade/elegibilidade (Fase J).
 * Regras de negócio e relações adicionais vêm aqui; a estrutura está em ElegibilidadeProdutividadeRHBase.
 */
class ElegibilidadeProdutividadeRH extends ElegibilidadeProdutividadeRHBase
{
    /** CONTRATO: migrada do antigo JSON do contrato (migração 2026_11_20_090000); MANUAL e ENTREGAVEL são as da Fase J. */
    public const ORIGENS = ['MANUAL', 'ENTREGAVEL', 'CONTRATO'];
}
