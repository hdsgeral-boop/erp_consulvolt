<?php

namespace App\Models;

use App\Models\Concerns\PertenceEmpresa;

/** historico_ordem_imo — trilho imutável dos eventos da ordem de trabalho (criação, aprovação, pedido de material, recepções, estados, débito). */
class HistoricoOrdemImo extends ModeloBase
{
    use PertenceEmpresa;

    public const UPDATED_AT = null;

    public const CREATED_AT = 'em';

    protected $table = 'historico_ordem_imo';

    protected $fillable = ['empresa_id', 'ordem_id', 'evento', 'estado_de', 'estado_para', 'motivo', 'dados', 'utilizador_id', 'em'];

    protected function casts(): array
    {
        return ['empresa_id' => 'integer', 'ordem_id' => 'integer', 'utilizador_id' => 'integer', 'dados' => 'array', 'em' => 'datetime'];
    }
}
