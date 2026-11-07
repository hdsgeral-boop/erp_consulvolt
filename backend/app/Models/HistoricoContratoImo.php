<?php

namespace App\Models;

use App\Models\Concerns\PertenceEmpresa;

/** historico_contrato_imo — trilho imutável dos eventos do contrato (criação, activação, suspensão, cessação, renovação, actualização da renda). */
class HistoricoContratoImo extends ModeloBase
{
    use PertenceEmpresa;

    public const UPDATED_AT = null;

    public const CREATED_AT = 'em';

    protected $table = 'historico_contrato_imo';

    protected $fillable = ['empresa_id', 'contrato_id', 'evento', 'estado_de', 'estado_para', 'motivo', 'dados', 'utilizador_id', 'em'];

    protected function casts(): array
    {
        return ['empresa_id' => 'integer', 'contrato_id' => 'integer', 'utilizador_id' => 'integer', 'dados' => 'array', 'em' => 'datetime'];
    }
}
