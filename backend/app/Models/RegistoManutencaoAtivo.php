<?php

namespace App\Models;

use App\Models\Base\RegistoManutencaoAtivoBase;

/**
 * registos_manutencao_ativos — /api/ativos/manutencoes (ServicoManutencaoAtivos).
 */
class RegistoManutencaoAtivo extends RegistoManutencaoAtivoBase
{
    public const TIPOS = ['PREVENTIVA', 'CORRECTIVA'];

    public const PLANEADA = 'PLANEADA';

    public const CONCLUIDA = 'CONCLUIDA';
}
