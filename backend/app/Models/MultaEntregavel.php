<?php

namespace App\Models;

use App\Models\Base\MultaEntregavelBase;

/**
 * multas_entregavel — multas por atraso de entregáveis, propostas pelo sistema e decididas por uma pessoa (Fase K, ADR-079).
 * Regras de negócio e relações adicionais vêm aqui; a estrutura está em MultaEntregavelBase.
 */
class MultaEntregavel extends MultaEntregavelBase
{
    public const ESTADOS = ['PROPOSTA', 'APLICADA', 'DISPENSADA', 'DESCONTADA'];

    public const ROTULOS = ['PROPOSTA' => 'Proposta', 'APLICADA' => 'Aplicada (por descontar)', 'DISPENSADA' => 'Dispensada', 'DESCONTADA' => 'Descontada na folha'];

    /** Origem das linhas da folha geradas pelas multas (LinhaFolhaSalarial::origem). */
    public const ORIGEM_LANCAMENTO = 'MULTA_ENTREGAVEL';
}
