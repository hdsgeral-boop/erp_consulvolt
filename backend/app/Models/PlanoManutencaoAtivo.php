<?php

namespace App\Models;

use App\Models\Base\PlanoManutencaoAtivoBase;

/**
 * planos_manutencao_ativos — sem uso no legado: a tabela só existia no esquema Dexie (js/db_v2.js:51), sem ecrã nem
 * escrita, e só tem activo e empresa. Fica sem API até haver campos (periodicidade, próxima data) — ver ADR-051.
 */
class PlanoManutencaoAtivo extends PlanoManutencaoAtivoBase {}
