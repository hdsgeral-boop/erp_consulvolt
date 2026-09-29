<?php

namespace App\Models;

use App\Models\Base\PlanoContaBase;
use App\Support\Cache\ChaveCache;
use Illuminate\Support\Facades\Cache;

/**
 * plano_contas — /api/contabilidade/plano-contas.
 * Tipo 'M' = conta de movimento (aceita lançamentos); 'T' = totalizadora (só agrega).
 * A leitura é servida pela cache Redis (ServicoPlanoContas); qualquer escrita invalida-a.
 */
class PlanoConta extends PlanoContaBase
{
    public const TIPO_MOVIMENTO = 'M';

    public const TIPO_TOTALIZADORA = 'T';

    protected static function booted(): void
    {
        $invalidar = fn (PlanoConta $conta) => Cache::forget(ChaveCache::empresa((int) $conta->empresa_id, 'contabilidade', 'plano_contas'));
        static::saved($invalidar);
        static::deleted($invalidar);
        static::restored($invalidar);
    }

    public function eMovimento(): bool
    {
        return $this->tipo !== self::TIPO_TOTALIZADORA;
    }
}
