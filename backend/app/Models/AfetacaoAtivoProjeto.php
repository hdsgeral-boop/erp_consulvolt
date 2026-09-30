<?php

namespace App\Models;

use App\Models\Base\AfetacaoAtivoProjetoBase;

/**
 * afetacoes_ativos_projeto — /api/ativos/afetacoes (ServicoAtivos). Escrita pelo módulo de Activos; os Projectos só lêem.
 * Um activo não pode estar afecto a dois projectos em intervalos sobrepostos (data_fim NULL = em aberto).
 */
class AfetacaoAtivoProjeto extends AfetacaoAtivoProjetoBase {}
