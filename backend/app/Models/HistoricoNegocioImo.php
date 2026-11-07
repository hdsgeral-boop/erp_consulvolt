<?php

namespace App\Models;

use App\Models\Concerns\PertenceEmpresa;

/** historico_negocio_imo — trilho imutável do negócio (criação, aprovação, fases, contratos, pagamentos, conclusão, abate). */
class HistoricoNegocioImo extends ModeloBase
{
    use PertenceEmpresa;

    public const UPDATED_AT = null;

    public const CREATED_AT = 'em';

    protected $table = 'historico_negocio_imo';

    protected $fillable = ['empresa_id', 'negocio_id', 'evento', 'estado_de', 'estado_para', 'motivo', 'dados', 'utilizador_id', 'em'];

    protected function casts(): array
    {
        return ['empresa_id' => 'integer', 'negocio_id' => 'integer', 'utilizador_id' => 'integer', 'dados' => 'array', 'em' => 'datetime'];
    }
}
