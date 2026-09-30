<?php

namespace App\Models;

use App\Models\Base\TransferenciaCentroCustoAtivoBase;

/**
 * transferencias_centros_custo_ativos — /api/ativos/transferencias (ServicoAtivos::transferir).
 * Escrita pelo módulo de Activos; os Projectos só lêem (projeto_id / codigo_projeto).
 */
class TransferenciaCentroCustoAtivo extends TransferenciaCentroCustoAtivoBase {}
