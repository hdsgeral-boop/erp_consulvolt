<?php

namespace App\Models;

use App\Models\Concerns\PertenceEmpresa;

/** contrato_encargos — encargos autónomos do contrato (condomínio, água…): incluídos na renda ou a acrescer em linha própria. */
class ContratoEncargo extends ModeloBase
{
    use PertenceEmpresa;

    protected $table = 'contrato_encargos';

    public $timestamps = false;

    protected $fillable = ['empresa_id', 'contrato_id', 'designacao', 'valor', 'incluido_na_renda', 'origem', 'fracao_id', 'ordem'];

    protected function casts(): array
    {
        return ['empresa_id' => 'integer', 'contrato_id' => 'integer', 'fracao_id' => 'integer', 'valor' => 'float', 'incluido_na_renda' => 'boolean', 'ordem' => 'integer'];
    }
}
