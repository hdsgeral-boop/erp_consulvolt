<?php

namespace App\Models;

use App\Models\Base\OrdemManutencaoAtivoBase;

/**
 * ordens_manutencao_ativos — /api/ativos/manutencao/ordens (Fase F, Versão Actual; ServicoOrdensManutencao).
 * Legado novo: asset_maintenance_orders (js/activos_manutencao.js:219-391). Estados PLANEADA → AGUARDA_MATERIAL → EM_CURSO
 * → CONCLUIDA | CANCELADA; custo total = materiais + serviços + mão-de-obra.
 * r3_activos: `tarefas` = [{id, texto, obrigatoria, feito, feito_por, feito_em}]; `historico` regista quem/quando concluiu
 * ou reabriu cada tarefa e a ordem; `plano_antes` guarda o plano antes da conclusão (repõe-se se a ordem for reaberta).
 */
class OrdemManutencaoAtivo extends OrdemManutencaoAtivoBase
{
    public const PLANEADA = 'PLANEADA';

    public const AGUARDA_MATERIAL = 'AGUARDA_MATERIAL';

    public const EM_CURSO = 'EM_CURSO';

    public const CONCLUIDA = 'CONCLUIDA';

    public const CANCELADA = 'CANCELADA';

    public const ABERTAS = [self::PLANEADA, self::AGUARDA_MATERIAL, self::EM_CURSO];

    public const ESTADOS = [self::PLANEADA, self::AGUARDA_MATERIAL, self::EM_CURSO, self::CONCLUIDA, self::CANCELADA];

    public const PRIORIDADES = ['BAIXA', 'NORMAL', 'ALTA', 'URGENTE'];
}
