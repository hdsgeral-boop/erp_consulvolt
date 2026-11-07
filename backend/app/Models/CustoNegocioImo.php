<?php

namespace App\Models;

use App\Models\Concerns\PertenceEmpresa;

/** custos_negocio_imo — encargo do negócio (mediação, escritura, registo, honorários…); o que capitaliza entra no custo de aquisição do bem. */
class CustoNegocioImo extends ModeloBase
{
    use PertenceEmpresa;

    protected $table = 'custos_negocio_imo';

    protected $fillable = ['empresa_id', 'negocio_id', 'tipo', 'designacao', 'valor', 'capitaliza', 'pago', 'data', 'ordem'];

    protected function casts(): array
    {
        return ['empresa_id' => 'integer', 'negocio_id' => 'integer', 'valor' => 'float', 'capitaliza' => 'boolean', 'pago' => 'boolean', 'data' => 'date:Y-m-d'];
    }
}
