<?php

namespace App\Models;

use App\Models\Base\AtivoImobilizadoBase;

/**
 * ativos_imobilizados — /api/ativos/bens (ServicoAtivos).
 * `amortizacao_acumulada` é derivada: inicial + Σ quotas contabilizadas (ServicoAtivos::recalcularAcumulado).
 * `lancamento_contabil_id` liga à linha do lançamento de aquisição (classe 11/12 a débito).
 */
class AtivoImobilizado extends AtivoImobilizadoBase
{
    public const ESTADO_ATIVO = 'ACTIVO';

    public const ESTADO_INATIVO = 'INACTIVO';

    public const ESTADO_ABATIDO = 'ABATIDO';

    public const ESTADOS = [self::ESTADO_ATIVO, self::ESTADO_INATIVO, self::ESTADO_ABATIDO];
}
