<?php

namespace App\Models;

use App\Models\Concerns\PertenceEmpresa;

/** periodos_ferias_empresa — período de férias de um ano, definido pelo administrador; delimita os pedidos do Portal. */
class PeriodoFeriasEmpresa extends ModeloBase
{
    use PertenceEmpresa;

    protected $table = 'periodos_ferias_empresa';

    protected $fillable = ['empresa_id', 'ano', 'data_inicio', 'data_fim', 'criado_por'];

    protected function casts(): array
    {
        return ['ano' => 'integer', 'data_inicio' => 'date', 'data_fim' => 'date'];
    }
}
