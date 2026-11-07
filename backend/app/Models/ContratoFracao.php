<?php

namespace App\Models;

use App\Models\Concerns\PertenceEmpresa;

/** contrato_fracoes — as fracções que um contrato abrange (o lado e o intervalo repetem-se aqui para a verificação de conflitos). */
class ContratoFracao extends ModeloBase
{
    use PertenceEmpresa;

    protected $table = 'contrato_fracoes';

    protected $fillable = ['empresa_id', 'contrato_id', 'fracao_id', 'posicao', 'inicio', 'fim'];

    protected function casts(): array
    {
        return ['empresa_id' => 'integer', 'contrato_id' => 'integer', 'fracao_id' => 'integer', 'inicio' => 'date:Y-m-d', 'fim' => 'date:Y-m-d'];
    }
}
